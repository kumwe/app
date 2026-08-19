<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\App\Administrator\Http\Handler\AdministratorInterfaceStandardHandler;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\Identity\Application\Administration\AdministratorSession;
use Kumwe\App\Presentation\Application\Dashboard\DashboardWidget;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Twig\Loader\FilesystemLoader;

/**
 * Proves the production KIS gallery renders the typed dashboard contract without an active mutation path.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorInterfaceStandardHandler::class)]
#[UsesClass(AdministratorRenderer::class)]
#[UsesClass(RecoveryAdministratorRenderer::class)]
#[UsesClass(DashboardWidget::class)]
final class AdministratorInterfaceStandardHandlerTest extends TestCase
{
    /**
     * Render every widget kind, fallback icon, browser and disabled preference form through production Twig.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDashboardReferenceUsesProtectedReadOnlyComponents(): void
    {
        $handler = new AdministratorInterfaceStandardHandler($this->renderer());
        $principal = AuthorizationContext::principal(['administrator.access']);
        $session = new AdministratorSession(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb399',
            $principal,
            'gallery-csrf-token',
            new DateTimeImmutable('+1 hour'),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/interface-standard?tab=overview')
            ->withQueryParams(['tab' => 'overview'])
            ->withAttribute(AdministratorSession::REQUEST_ATTRIBUTE, $session);

        $response = $handler->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(4, substr_count($body, 'data-kis-dashboard-widget='));
        self::assertStringContainsString('data-kis-dashboard-widget="core.interface-standard.gallery-summary"', $body);
        self::assertStringContainsString('data-kis-dashboard-widget="core.interface-standard.gallery-activity"', $body);
        self::assertStringContainsString('data-kis-dashboard-widget="core.interface-standard.gallery-context"', $body);
        self::assertStringContainsString('data-kis-dashboard-widget="core.interface-standard.gallery-workflow"', $body);
        self::assertStringContainsString('data-kis-dashboard-icon="dashboard"', $body);
        self::assertStringContainsString('data-kis-dashboard-icon-fallback="true"', $body);
        self::assertMatchesRegularExpression(
            '/<fieldset[^>]*data-kis-dashboard-preferences-read-only[^>]*disabled/u',
            $body,
        );
        self::assertStringContainsString('name="dashboard_workflow_search"', $body);
        self::assertStringContainsString('name="dashboard_group_search"', $body);
        self::assertStringContainsString('Operations reviewers', $body);
        self::assertStringContainsString(
            'name="scope_id" value="' . AuthorizationContext::SUBJECT . '"',
            $body,
        );
        self::assertStringContainsString('name="scope" value="role-workspace"', $body);
        self::assertStringContainsString(
            'name="scope_id" value="role:00000000-0000-7000-8000-000000000702"',
            $body,
        );
        self::assertStringNotContainsString('/administrator/dashboard/preferences', $body);
        self::assertStringNotContainsString('/portal/dashboard/preferences', $body);
    }

    /**
     * Build the production administrator template environment with protected KIS component paths.
     *
     * @return  AdministratorRenderer  Renderer using the real gallery, layout and dashboard partials.
     *
     * @since   2.0.0
     */
    private function renderer(): AdministratorRenderer
    {
        $root = dirname(__DIR__, 5);
        $loader = new FilesystemLoader($root . '/templates/administrator');
        $loader->addPath($root . '/templates/interface-standard', 'kis');
        $twig = new AdministratorTwigEnvironment($loader, ['strict_variables' => true]);
        $twig->addExtension(InterfaceTranslation::twigExtension());

        return new AdministratorRenderer(
            $twig,
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new FilesystemLoader())),
        );
    }
}
