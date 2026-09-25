<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorDashboardPreferencesHandler;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Administrator\Contract\AdministratorNavigationDefinition;
use Kumwe\Administrator\Contract\AdministratorWorkspaceDefinition;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\InterfaceStandard\CustomizationScope;
use Kumwe\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\InterfaceStandard\SurfaceId;
use Kumwe\App\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWorkflowCatalog;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\InterfaceStandard\SurfaceArea;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceService;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceQueryDecoder;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\DashboardPreferenceTestRuntime;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Loader\ArrayLoader;
use Kumwe\App\Application\Authorization\ExecutionContextAttribute;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;

/**
 * Verifies administrator POST delivery derives a live catalog and exposes only closed redirect results.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorDashboardPreferencesHandler::class)]
#[CoversClass(DashboardWorkflowCatalog::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(DashboardComposer::class)]
#[UsesClass(DashboardPreferenceService::class)]
final class AdministratorDashboardPreferencesHandlerTest extends TestCase
{
    /**
     * Proves a capability-backed core widget is persisted and returns the saved dashboard fragment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesFromTheLiveCapabilityFilteredCatalog(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'core.dashboard.content-summary',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['administrator.access', 'content.read'], [
            'dashboard_group_page' => '65',
            'dashboard_group_search' => 'Finance & review',
            'dashboard_workflow_page' => '16',
            'dashboard_workflow_search' => 'Sales orders',
            'return' => 'https://attacker.example/',
        ]);

        $response = $handler->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            '/administrator?dashboard_group_search=Finance%20%26%20review&dashboard_group_page=65'
                . '&dashboard_workflow_search=Sales%20orders&dashboard_workflow_page=16'
                . '&dashboard-saved=1#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.dashboard'),
            CustomizationSlot::DashboardCards,
            CustomizationScope::User,
            AuthorizationContext::SUBJECT,
        ));
        self::assertSame(['core.dashboard.content-summary'], $stored?->value()->value());
    }

    /**
     * An editor lacking a stored widget's capability saves a role form and the role keeps that widget.
     *
     * The role row stores `acme.finance-workflow`, which the editor's capability-filtered navigation does not
     * contain. Submitting the role form with that retained choice saves, the stored selection keeps it for the
     * role's members, and an identifier outside both the editor catalogue and the role row is still refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testARoleFormKeepsAWidgetOutsideTheEditorsVisibility(): void
    {
        $group = PresentationAccessGroup::fromRole('018f22e2-7c8b-7ab0-8f3a-88e8026bb303', 'operations', 'Ops');
        $runtime = new DashboardPreferenceTestRuntime([$group]);
        $surface = SurfaceId::fromString('core.administrator.dashboard');
        $runtime->service->mutate(
            AuthorizationContext::human(['administrator.access', 'users.manage']),
            SurfaceArea::Administrator,
            $surface,
            ContributionOwner::core(),
            $runtime->decoder->decode([
                'action' => 'dashboard-cards.save',
                'scope' => 'role-workspace',
                'scope_id' => $group->id,
                'expected_version' => '0',
                'item_0' => 'acme.finance-workflow',
                'selected_0' => '1',
                'order_0' => '1',
            ]),
            ['acme.finance-workflow'],
            [],
        );
        $key = new PresentationPreferenceKey(
            $surface,
            CustomizationSlot::DashboardCards,
            CustomizationScope::RoleWorkspace,
            $group->id,
        );
        $version = $runtime->preferences->find($key)?->version();
        self::assertIsInt($version);
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );

        $saved = $handler->handle($this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'role-workspace',
            'scope_id' => $group->id,
            'expected_version' => (string) $version,
            'item_0' => 'core.dashboard.administrator-context',
            'selected_0' => '1',
            'order_0' => '1',
            'item_1' => 'acme.finance-workflow',
            'selected_1' => '1',
            'order_1' => '2',
        ], ['administrator.access', 'users.manage']));

        self::assertSame(
            '/administrator?dashboard-saved=1#dashboard-customization',
            $saved->getHeaderLine('Location'),
        );
        $stored = $runtime->preferences->find($key);
        self::assertSame(
            ['core.dashboard.administrator-context', 'acme.finance-workflow'],
            $stored?->value()->value(),
        );

        $refused = $handler->handle($this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'role-workspace',
            'scope_id' => $group->id,
            'expected_version' => (string) $stored?->version(),
            'item_0' => 'acme.payroll-workflow',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['administrator.access', 'users.manage']));
        self::assertSame(
            '/administrator?dashboard-error=invalid#dashboard-customization',
            $refused->getHeaderLine('Location'),
        );
        self::assertSame($stored?->value()->value(), $runtime->preferences->find($key)?->value()->value());
    }

    /**
     * Proves a withdrawn or forged identifier is translated to the stable invalid-result redirect.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnIdentifierOutsideTheLiveCatalog(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'vendor.withdrawn-widget',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['administrator.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/administrator?dashboard-error=invalid#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
    }

    /**
     * Proves POST validates a bounded submission against the complete current filtered catalogue.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSavesAWorkflowBeyondTheFormerRendererPrefix(): void
    {
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        $owner = ContributionOwner::core();
        $registries->workspaces()->register($owner, new AdministratorWorkspaceDefinition(
            'core.dashboard-volume',
            'Dashboard volume',
            'Regression workflows for the bounded administrator dashboard catalog.',
            100,
        ));
        for ($index = 1; $index <= 500; $index++) {
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $registries->navigation()->registerOwned($owner, new AdministratorNavigationDefinition(
                'core.dashboard-volume-' . $suffix,
                'core.dashboard-volume',
                'Workflow ' . $suffix,
                'Open bounded workflow ' . $suffix . '.',
                '/administrator/dashboard-volume-' . $suffix,
                'dashboard',
                'administrator.access',
                $index,
            ));
        }
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer($registries->navigation()),
        );
        $request = $this->request([
            'action' => 'navigation-shortcuts.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '0',
            'item_0' => 'core.dashboard-volume-500',
            'selected_0' => '1',
            'order_0' => '1',
        ], ['administrator.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/administrator?dashboard-saved=1#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
        $stored = $runtime->preferences->find(new PresentationPreferenceKey(
            SurfaceId::fromString('core.administrator.dashboard'),
            CustomizationSlot::NavigationShortcuts,
            CustomizationScope::User,
            AuthorizationContext::SUBJECT,
        ));
        self::assertSame(['core.dashboard-volume-500'], $stored?->value()->value());
    }

    /**
     * Proves an optimistic mismatch is translated separately from malformed form input.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRedirectsAnOptimisticMismatchAsAConflict(): void
    {
        $runtime = new DashboardPreferenceTestRuntime();
        $handler = new AdministratorDashboardPreferencesHandler(
            $runtime->service,
            $runtime->decoder,
            new DashboardPreferenceQueryDecoder(),
            $this->renderer(),
        );
        $request = $this->request([
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => AuthorizationContext::SUBJECT,
            'expected_version' => '1',
        ], ['administrator.access']);

        $response = $handler->handle($request);

        self::assertSame(
            '/administrator?dashboard-error=conflict#dashboard-customization',
            $response->getHeaderLine('Location'),
        );
    }

    /**
     * Build the request shape emitted by administrator authentication and CSRF middleware.
     *
     * @param   array<string, string>  $form          Flat dashboard preference form.
     * @param   list<string>           $capabilities  Capabilities carried by the administrator principal.
     * @param   array<string, string>  $query         Optional untrusted continuation query.
     *
     * @return  ServerRequestInterface  Authenticated POST request with parsed form data.
     *
     * @since   2.0.0
     */
    private function request(array $form, array $capabilities, array $query = []): ServerRequestInterface
    {
        $principal = AuthorizationContext::principal($capabilities);
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            $principal,
            'csrf-token',
            new DateTimeImmutable('+1 hour'),
        );

        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/administrator/dashboard/preferences')
            ->withQueryParams($query)
            ->withParsedBody($form)
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContextAttribute::NAME, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'test-dashboard-preferences',
            ));
    }

    /**
     * Build the renderer used only for its canonical live administrator navigation projection.
     *
     * @param   ?AdministratorNavigationRegistry  $navigation  Optional contribution catalog for a scale scenario.
     *
     * @return  AdministratorRenderer  Core or supplied registry-backed renderer.
     *
     * @since   2.0.0
     */
    private function renderer(?AdministratorNavigationRegistry $navigation = null): AdministratorRenderer
    {
        return new AdministratorRenderer(
            new AdministratorTwigEnvironment(new ArrayLoader()),
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
            new DeterministicCanonicalEncoder(),
            $navigation,
        );
    }
}
