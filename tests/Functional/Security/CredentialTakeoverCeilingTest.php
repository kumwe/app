<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use Kumwe\App\Delivery\Http\Api\Identity\AccessControlApiHandler;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that managing users never lets an actor take over an account stronger than itself.
 *
 * Choosing another account's password, or stripping its second factor, is the path to acting as that
 * account. Role assignment and token issuance already refuse to hand anyone authority the actor could not
 * delegate; before this ceiling a holder of `users.manage` alone could reset the password of an account
 * holding settings and extension authority and then sign in as it. The test drives the REST operations the
 * browser-parity work exposed, with real tokens, and requires both takeover paths to be refused against a
 * stronger account while an account inside the actor's own authority can still be recovered.
 *
 * @since  2.0.0
 */
#[CoversClass(AccessControlService::class)]
#[CoversClass(AccessControlApiHandler::class)]
final class CredentialTakeoverCeilingTest extends TestCase
{
    /**
     * A user manager cannot reset the password or second factor of an account holding more authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAUserManagerCannotTakeOverAStrongerAccount(): void
    {
        $harness = SecurityHttpHarness::boot();
        $manager = $harness->machineActor(['users.manage', 'administrator.access', 'content.read']);
        $stronger = $harness->machineActor(['administrator.access', 'settings.manage', 'extensions.manage']);
        $weaker = $harness->machineActor(['administrator.access', 'content.read']);
        $identities = $harness->container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $chosen = 'attacker chosen passphrase';

        $reset = $harness->handle($harness->api(
            'POST',
            '/api/v1/users/' . $stronger['subject'] . '/password-reset',
            $manager['token'],
            ['password' => $chosen, 'reason' => 'takeover attempt'],
            ['Idempotency-Key' => 'takeover-reset-' . bin2hex(random_bytes(6))],
        ));
        self::assertSame(403, $reset->getStatusCode(), 'The stronger account keeps its password.');
        self::assertNull($identities->authenticate($stronger['email'], $chosen, 'security-qualification'));
        self::assertNotNull($identities->authenticate(
            $stronger['email'],
            'correct horse battery',
            'security-qualification',
        ), 'The original credential still works.');

        $stepUp = $harness->handle($harness->api(
            'POST',
            '/api/v1/users/' . $stronger['subject'] . '/step-up/revoke',
            $manager['token'],
            ['reason' => 'takeover attempt'],
            ['Idempotency-Key' => 'takeover-step-up-' . bin2hex(random_bytes(6))],
        ));
        self::assertSame(403, $stepUp->getStatusCode(), 'The stronger account keeps its second factor.');

        $recovered = $harness->handle($harness->api(
            'POST',
            '/api/v1/users/' . $weaker['subject'] . '/password-reset',
            $manager['token'],
            ['password' => $chosen, 'reason' => 'ticket 4711'],
            ['Idempotency-Key' => 'recovery-reset-' . bin2hex(random_bytes(6))],
        ));
        self::assertSame(200, $recovered->getStatusCode(), 'An account inside the ceiling can be recovered.');
        self::assertNotNull($identities->authenticate($weaker['email'], $chosen, 'security-qualification'));
    }
}
