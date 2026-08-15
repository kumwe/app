<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Portal\Http;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Portal\Application\PortalSession;
use Kumwe\CMS\Portal\Application\PortalSessionIdentity;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;
use Kumwe\CMS\Portal\Domain\PortalContext;
use Kumwe\CMS\Portal\Http\Handler\PortalHomeHandler;
use Kumwe\CMS\Portal\Presentation\PortalNavigationVisibility;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardComposer;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardPreferenceService;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardView;
use Kumwe\CMS\Presentation\Application\Dashboard\DashboardWidget;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferencePolicy;
use Kumwe\CMS\Presentation\Application\Preference\PresentationPreferenceResolver;
use Kumwe\CMS\Tests\Support\InMemoryPresentationAccessGroupRepository;
use Kumwe\CMS\Tests\Support\InMemoryPresentationPreferenceRepository;
use Kumwe\CMS\Tests\Support\DashboardPreferenceTestRuntime;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Proves the portal home consumes the shared widget engine and exact portal navigation projection.
 *
 * @since  2.0.0
 */
#[CoversClass(PortalHomeHandler::class)]
#[CoversClass(PortalRenderer::class)]
#[UsesClass(DashboardComposer::class)]
#[UsesClass(DashboardPreferenceService::class)]
#[UsesClass(DashboardView::class)]
#[UsesClass(DashboardWidget::class)]
final class PortalHomeHandlerTest extends TestCase
{
    /**
     * Proves the overview self link is excluded while policy-visible business workflows remain useful.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testComposesContextAndPolicyVisibleWorkflowsWithoutOverviewSelfLink(): void
    {
        $session = $this->session();
        $visibility = $this->createStub(PortalNavigationVisibility::class);
        $visibility->method('visible')->willReturn(true);
        $registries = new ExtensionContributionRegistrySet();
        $renderer = new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/home.twig' => 'widgets:{{ dashboard.widgets|length }};'
                    . "context:{{ 'core.dashboard.access-context' in dashboard.selected_widget_ids ? 'yes' : 'no' }};"
                    . "records:{{ 'core.portal-business-records' in dashboard.selected_widget_ids ? 'yes' : 'no' }};"
                    . "self:{{ 'core.portal-home' in dashboard.selected_shortcut_ids ? 'yes' : 'no' }};"
                    . 'areas:{{ dashboard.widgets[0].data.items[3].value }}',
            ]), ['strict_variables' => true]),
            $registries->portalNavigation(),
            $registries->portalTemplates(),
            $visibility,
        );
        $handler = new PortalHomeHandler(
            $renderer,
            $this->dashboard(),
            (new DashboardPreferenceTestRuntime())->service,
        );
        $principal = $session->identity->principal;
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/portal')
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'test-portal-dashboard',
            ));

        $response = $handler->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('context:yes', $body);
        self::assertStringContainsString('records:yes', $body);
        self::assertStringContainsString('self:no', $body);
        self::assertStringContainsString('areas:4', $body);
    }

    /**
     * Proves a large live contribution catalog remains selectable without overflowing KIS defaults.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBoundsMoreThanSixtyFourVisibleWorkflowDefaults(): void
    {
        $session = $this->session();
        $visibility = $this->createStub(PortalNavigationVisibility::class);
        $visibility->method('visible')->willReturn(true);
        $registries = new ExtensionContributionRegistrySet();
        $owner = ContributionOwner::core();
        $registrar = $registries->registrar($owner, new ManifestContributionSet($owner), false);
        $registrar->portalWorkspace(new PortalWorkspaceDefinition(
            'core.portal-dashboard-volume',
            'Dashboard volume',
            'Regression workflows for the bounded portal dashboard catalog.',
            100,
        ));
        for ($index = 1; $index <= 140; $index++) {
            $suffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $registrar->portalNavigation(new PortalNavigationDefinition(
                'core.portal-dashboard-volume-' . $suffix,
                'core.portal-dashboard-volume',
                'Workflow ' . $suffix,
                'Open bounded workflow ' . $suffix . '.',
                '/portal/dashboard-volume-' . $suffix,
                'dashboard',
                'portal.access',
                $index,
            ));
        }
        $registrar->complete();
        $renderer = new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/home.twig' => 'selected:{{ dashboard.selected_widget_ids|length }};'
                    . 'available:{{ dashboard.available_widgets|length }};'
                    . 'areas:{{ dashboard.widgets[0].data.items[3].value }}',
            ]), ['strict_variables' => true]),
            $registries->portalNavigation(),
            $registries->portalTemplates(),
            $visibility,
        );
        $handler = new PortalHomeHandler(
            $renderer,
            $this->dashboard(),
            (new DashboardPreferenceTestRuntime())->service,
        );
        $principal = $session->identity->principal;
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/portal')
            ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
            ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $principal->context(
                SiteContext::default(),
                AuthenticationStrength::Password,
                'test-portal-dashboard-volume',
            ));

        $response = $handler->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('selected:64', $body);
        self::assertStringContainsString('available:128', $body);
        self::assertStringContainsString('areas:127', $body);
    }

    /**
     * Proves the GET boundary translates only closed preference-result flags into dashboard notices.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRendersClosedPreferenceResultNotices(): void
    {
        $session = $this->session();
        $visibility = $this->createStub(PortalNavigationVisibility::class);
        $visibility->method('visible')->willReturn(true);
        $registries = new ExtensionContributionRegistrySet();
        $renderer = new PortalRenderer(
            new Environment(new ArrayLoader([
                'portal/home.twig' => "saved:{{ dashboard.preference_saved ? 'yes' : 'no' }};"
                    . "error:{{ dashboard.preference_error|default('none', true) }};"
                    . "open:{{ dashboard.preference_open ? 'yes' : 'no' }}",
            ]), ['strict_variables' => true]),
            $registries->portalNavigation(),
            $registries->portalTemplates(),
            $visibility,
        );
        $handler = new PortalHomeHandler(
            $renderer,
            $this->dashboard(),
            (new DashboardPreferenceTestRuntime())->service,
        );
        $principal = $session->identity->principal;
        $cases = [
            [['dashboard-saved' => '1'], 'saved:yes;error:none;open:yes'],
            [
                ['dashboard-error' => 'conflict'],
                'saved:no;error:core.interface_standard.dashboard.conflict_notice;open:yes',
            ],
            [
                ['dashboard-error' => 'invalid'],
                'saved:no;error:core.interface_standard.dashboard.invalid_notice;open:yes',
            ],
        ];

        foreach ($cases as [$query, $expected]) {
            $request = (new ServerRequestFactory())
                ->createServerRequest('GET', 'https://kumwe.test/portal')
                ->withQueryParams($query)
                ->withAttribute(PortalSession::REQUEST_ATTRIBUTE, $session)
                ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $principal->context(
                    SiteContext::default(),
                    AuthenticationStrength::Password,
                    'test-portal-dashboard-notice',
                ));

            self::assertSame($expected, (string) $handler->handle($request)->getBody());
        }
    }

    /**
     * Build the real group-aware composer over empty presentation repositories.
     *
     * @return  DashboardComposer
     *
     * @since   2.0.0
     */
    private function dashboard(): DashboardComposer
    {
        $policy = $this->createStub(PresentationPreferencePolicy::class);
        $policy->method('allows')->willReturn(true);

        return new DashboardComposer(
            new PresentationPreferenceResolver(
                new InMemoryPresentationPreferenceRepository(),
                $policy,
            ),
            new InMemoryPresentationAccessGroupRepository(),
        );
    }

    /**
     * Build a resolved portal session carrying only portal access.
     *
     * @return  PortalSession
     *
     * @since   2.0.0
     */
    private function session(): PortalSession
    {
        $now = new DateTimeImmutable('2026-08-15T10:00:00+00:00');
        $principal = AuthenticatedPrincipal::issueFromStrings(
            new \stdClass(),
            '018f0000-0000-7000-8000-000000000001',
            ['portal.access'],
        );

        return new PortalSession(
            '018f0000-0000-7000-8000-000000000002',
            new PortalSessionIdentity($principal, new PortalContext(SiteContext::default(), null), 1),
            str_repeat('c', 43),
            $now,
            null,
            $now->modify('+1 hour'),
        );
    }
}
