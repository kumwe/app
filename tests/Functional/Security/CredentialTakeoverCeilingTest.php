<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Delivery\Http\Api\Identity\AccessControlApiHandler;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Infrastructure\Administration\DoctrineAccessControlRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins that managing users never lets an actor take over an account stronger than itself.
 *
 * Choosing another account's password, or stripping its second factor, is the path to acting as that
 * account. Role assignment and token issuance already refuse to hand anyone authority the actor could not
 * delegate; before this ceiling a holder of `users.manage` alone could reset the password of an account
 * holding settings and extension authority and then sign in as it. The test drives the REST operations the
 * browser-parity work exposed, with real tokens, and requires both takeover paths to be refused against a
 * stronger account while an account inside the actor's own authority can still be recovered, and authority
 * held only through an organization membership — even one that is inactive today — counts towards it.
 *
 * @since  2.0.0
 */
#[CoversClass(AccessControlService::class)]
#[CoversClass(AccessControlApiHandler::class)]
#[CoversClass(DoctrineAccessControlRepository::class)]
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

    /**
     * Authority held only through an organization membership, even an inactive one, bounds the takeover.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMembershipRoleAuthorityCountsTowardsTheCeiling(): void
    {
        $harness = SecurityHttpHarness::boot();
        $manager = $harness->machineActor(['users.manage', 'administrator.access', 'content.read']);
        $member = $harness->machineActor(['administrator.access', 'content.read']);
        $access = $harness->container->get(AccessControlService::class);
        $database = $harness->container->get(Connection::class);
        $tables = $harness->container->get(TableNames::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $administrator = TestKernelFactory::administratorContext($harness->container);
        $marker = bin2hex(random_bytes(6));
        $role = $access->createRole($administrator, 'membership-ceiling-' . $marker, 'Organization settings');
        $access->grant($administrator, $role, 'settings.manage');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $organization = Uuid::uuid7()->toString();
        $database->insert($tables->raw('organizations'), [
            'id' => $organization,
            'site_identifier' => 'default',
            'identifier' => 'membership-ceiling-' . $marker,
            'name' => 'Membership ceiling organization',
            'status' => 'active',
            'policy_generation' => 1,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $membership = Uuid::uuid7()->toString();
        $database->insert($tables->raw('organization_memberships'), [
            'id' => $membership,
            'organization_id' => $organization,
            'user_id' => $member['subject'],
            'status' => 'inactive',
            'version' => 1,
            'valid_from' => $now->modify('-1 day'),
            'valid_until' => null,
            'created_by' => $administrator->actorId(),
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            'valid_from' => Types::DATETIME_IMMUTABLE,
            'valid_until' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
        $database->insert($tables->raw('membership_roles'), [
            'membership_id' => $membership,
            'role_id' => $role,
            'assigned_by' => $administrator->actorId(),
            'assigned_at' => $now,
        ], ['assigned_at' => Types::DATETIME_IMMUTABLE]);

        foreach (
            [
                ['password-reset', ['password' => 'attacker chosen passphrase', 'reason' => 'takeover attempt']],
                ['step-up/revoke', ['reason' => 'takeover attempt']],
            ] as [$operation, $body]
        ) {
            $response = $harness->handle($harness->api(
                'POST',
                '/api/v1/users/' . $member['subject'] . '/' . $operation,
                $manager['token'],
                $body,
                ['Idempotency-Key' => 'membership-ceiling-' . bin2hex(random_bytes(6))],
            ));
            self::assertSame(403, $response->getStatusCode(), $operation . ' is bounded by membership authority.');
        }
        $identities = $harness->container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        self::assertNotNull($identities->authenticate(
            $member['email'],
            'correct horse battery',
            'security-qualification',
        ), 'The member keeps the credential it had.');
    }
}
