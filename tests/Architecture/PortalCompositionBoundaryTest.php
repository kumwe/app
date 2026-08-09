<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/** @since 2.0.0 */
final class PortalCompositionBoundaryTest extends TestCase
{
    public function testPortalCompositionUsesItsOwnMiddlewareCookieRendererAndRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $container = (string) file_get_contents($root . '/src/Kernel/ContainerFactory.php');
        $session = (string) file_get_contents(
            $root . '/src/Portal/Infrastructure/Session/DoctrinePortalSessionStore.php',
        );
        $login = (string) file_get_contents($root . '/src/Portal/Http/Handler/PortalLoginHandler.php');

        self::assertStringContainsString('new DoctrinePortalSessionStore(', $container);
        self::assertStringContainsString("'/portal/login'", $container);
        self::assertStringContainsString("'/portal/security'", $container);
        self::assertStringContainsString("'/portal/approvals'", $container);
        self::assertStringContainsString("['approve', 'reject', 'revoke']", $container);
        self::assertStringContainsString("'/portal/logout'", $container);
        self::assertStringContainsString('$application->pipe(PortalSessionMiddleware::class);', $container);
        self::assertStringContainsString('$application->pipe(PortalAuthorizationMiddleware::class);', $container);
        self::assertLessThan(
            strpos($container, '$application->pipe(BearerAuthenticationMiddleware::class);'),
            strpos($container, '$application->pipe(PortalAuthorizationMiddleware::class);'),
        );
        self::assertStringContainsString("COOKIE_NAME = 'kumwe_portal'", (string) file_get_contents(
            $root . '/src/Portal/Http/Middleware/PortalSessionMiddleware.php',
        ));
        self::assertStringContainsString('Path=/portal', $login);
        self::assertStringNotContainsString('kumwe_administrator', $session);
        self::assertStringNotContainsString('RecoveryAdministratorRenderer', $session . $login);
        $approval = (string) file_get_contents(
            $root . '/src/Portal/Http/Handler/PortalApprovalHandler.php',
        );
        self::assertStringContainsString("'business.approval.' . \$decision", $approval);
        self::assertStringContainsString('AuthenticationStrength::MultiFactor', $approval);
        self::assertStringContainsString('AuthorizationStepUpProofAdapter', $approval);
        self::assertStringNotContainsString('Doctrine\\DBAL', $approval);
    }

    public function testExtensionRuntimeMountsOnlyExplicitPortalTemplateRootsAndPortalRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $active = (string) file_get_contents($root . '/src/Extension/Runtime/ActiveExtensionSet.php');
        $loader = (string) file_get_contents($root . '/src/Extension/Runtime/ExtensionRuntimeLoader.php');

        self::assertStringContainsString("'/templates/views/portal'", $loader);
        self::assertStringContainsString('is_link($templates)', $loader);
        self::assertStringContainsString('portalRoutes()->registerInto(', $active);
        self::assertStringContainsString('portalTemplatePaths()', $active);
    }
}
