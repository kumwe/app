<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Twig;

use DateTimeImmutable;
use Kumwe\CMS\Identity\Domain\StepUp\StepUpEnrollmentSetup;
use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Verifies the administrator access-control template against production step-up value objects.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class AdministratorAccessControlTemplateTest extends TestCase
{
    /**
     * Render a pending authenticator enrollment with its immutable expiry as semantic time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthenticatorEnrollmentFormatsImmutableExpiry(): void
    {
        $root = dirname(__DIR__, 4);
        $loader = new FilesystemLoader($root . '/templates/administrator');
        $loader->addPath($root . '/templates/interface-standard', 'kis');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(InterfaceTranslation::twigExtension());
        $twig->getExtension(CoreExtension::class)->setTimezone('UTC');
        $secret = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $enrollment = new StepUpEnrollmentSetup(
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb701',
            $secret,
            'otpauth://totp/Kumwe%3Atest?secret=' . $secret . '&issuer=Kumwe',
            new DateTimeImmutable('2026-08-12T10:15:00+00:00'),
        );

        $html = $twig->render('access-control.twig', [
            'csrf' => 'test-csrf-token',
            'administrator_assets' => ['stylesheets' => [], 'modules' => []],
            'administrator_workspaces' => [],
            'administrator_navigation' => [],
            'administrator_commands_json' => '[]',
            'active_navigation' => 'core.access',
            'capabilities' => [],
            'saved' => false,
            'workspace' => ['section' => 'events', 'mode' => 'review', 'id' => null],
            'organization_selections' => [],
            'active_organization' => null,
            'active_workspace' => null,
            'step_up_enrollment' => $enrollment,
            'step_up_recovery_codes' => [],
        ]);

        self::assertStringContainsString('Add this secret to your authenticator now.', $html);
        self::assertStringContainsString('datetime="2026-08-12T10:15:00+00:00"', $html);
        self::assertStringContainsString('2026-08-12 10:15 UTC', $html);
        self::assertStringContainsString('name="enrollment_id"', $html);
    }

    /**
     * Renders one role capability change-set form with one submission-bound verifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRoleCapabilityChangeSetContainsOneVerifier(): void
    {
        $root = dirname(__DIR__, 4);
        $loader = new FilesystemLoader($root . '/templates/administrator');
        $loader->addPath($root . '/templates/interface-standard', 'kis');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(InterfaceTranslation::twigExtension());
        $roleId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb701';
        $html = $twig->render('access-control.twig', [
            'csrf' => 'test-csrf-token',
            'administrator_assets' => ['stylesheets' => [], 'modules' => []],
            'administrator_workspaces' => [],
            'administrator_navigation' => [],
            'administrator_commands_json' => '[]',
            'active_navigation' => 'core.access',
            'capabilities' => ['users.manage' => true],
            'saved' => false,
            'workspace' => ['section' => 'groups', 'mode' => 'review', 'id' => $roleId],
            'organization_selections' => [],
            'active_organization' => null,
            'active_workspace' => null,
            'roles' => [[
                'id' => $roleId,
                'name' => 'Administrator',
                'code' => 'administrator',
                'grant_snapshot' => str_repeat('a', 64),
                'grants' => [[
                    'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb702',
                    'capability' => 'users.manage',
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                ]],
            ]],
            'available_capabilities' => [[
                'code' => 'users.manage',
                'description' => 'Manage users.',
            ]],
        ]);

        self::assertStringContainsString('value="grant.synchronize"', $html);
        self::assertStringContainsString('name="grant_snapshot"', $html);
        self::assertStringContainsString('name="selected_capabilities[]"', $html);
        self::assertStringContainsString('This role carries no scoped grants.', $html);
        self::assertSame(1, substr_count($html, '<legend>Verify this exact action</legend>'));
    }
}
