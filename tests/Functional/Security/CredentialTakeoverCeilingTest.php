<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Delivery\Console\Command\RecoverCredentialsCommand;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\Access\AuthorizationDenied;
use Kumwe\Context\Value\ExecutionContext;
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
 * holding settings and extension authority and then sign in as it. Account recovery is
 * reachable only from the access screen with the actor's own consumed step-up proof, so the test drives the
 * service with that stepped context and requires both takeover paths to be refused against a
 * stronger account while an account inside the actor's own authority can still be recovered, and authority
 * held only through an organization membership — even one that is inactive today — counts towards it.
 * Break-glass console recovery is pinned at the behaviour the ceiling gives it today, pending a decision.
 *
 * @since  2.0.0
 */
#[CoversClass(AccessControlService::class)]
#[CoversClass(DoctrineAccessControlRepository::class)]
#[CoversClass(RecoverCredentialsCommand::class)]
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

        $access = $harness->container->get(AccessControlService::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        $stepped = self::steppedManager($identities, $manager['email']);

        try {
            $access->resetUserPassword($stepped, $stronger['subject'], $chosen, 'takeover attempt');
            self::fail('A stronger account must keep its password.');
        } catch (AuthorizationDenied) {
        }
        self::assertNull($identities->authenticate($stronger['email'], $chosen, 'security-qualification'));
        self::assertNotNull($identities->authenticate(
            $stronger['email'],
            'correct horse battery',
            'security-qualification',
        ), 'The original credential still works.');

        try {
            $access->revokeStepUpCredentials($stepped, $stronger['subject'], 'takeover attempt');
            self::fail('A stronger account must keep its second factor.');
        } catch (AuthorizationDenied) {
        }

        $access->resetUserPassword($stepped, $weaker['subject'], $chosen, 'ticket 4711');
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

        $identities = $harness->container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $stepped = self::steppedManager($identities, $manager['email']);
        foreach (
            [
                'password-reset' => static fn () => $access->resetUserPassword(
                    $stepped,
                    $member['subject'],
                    'attacker chosen passphrase',
                    'takeover attempt',
                ),
                'step-up revoke' => static fn () => $access->revokeStepUpCredentials(
                    $stepped,
                    $member['subject'],
                    'takeover attempt',
                ),
            ] as $operation => $attempt
        ) {
            try {
                $attempt();
                self::fail($operation . ' must be bounded by membership authority.');
            } catch (AuthorizationDenied) {
            }
        }
        self::assertNotNull($identities->authenticate(
            $member['email'],
            'correct horse battery',
            'security-qualification',
        ), 'The member keeps the credential it had.');
    }

    /**
     * Break-glass console recovery currently stops at the same ceiling for an account holding any grant.
     *
     * The recovery acts as the `system:credential-recovery` identity, and a system identity carries no grants
     * to draw a delegation ceiling from, so since the ceiling landed the console can reset the password or
     * retire the second factor only of an account that holds no grant, while ending a stronger account's
     * sessions still works. Whether break-glass should be exempt is an open maintainer decision; this pins
     * today's behaviour so that whichever way it is decided, the change is deliberate and visible here.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBreakGlassRecoveryCurrentlyStopsAtTheCeilingOfAnAccountHoldingGrants(): void
    {
        $harness = SecurityHttpHarness::boot();
        $stronger = $harness->machineActor(['administrator.access', 'settings.manage']);
        $access = $harness->container->get(AccessControlService::class);
        $identities = $harness->container->get(AdministratorIdentityGateway::class);
        self::assertInstanceOf(AccessControlService::class, $access);
        self::assertInstanceOf(AdministratorIdentityGateway::class, $identities);
        $grantless = 'break-glass-' . bin2hex(random_bytes(6)) . '@example.test';
        $access->createUser(
            TestKernelFactory::administratorContext($harness->container),
            $grantless,
            'Break-glass subject',
            'correct horse battery',
        );
        $chosen = 'break glass replacement passphrase';
        $file = $harness->tokenFile($chosen);

        try {
            foreach ([['reset-password', '--password-file=' . $file], ['revoke-step-up']] as $arguments) {
                $refused = $harness->console(RecoverCredentialsCommand::class, [
                    $arguments[0],
                    '--email=' . $stronger['email'],
                    ...array_slice($arguments, 1),
                ]);
                self::assertSame(1, $refused['status'], $arguments[0] . ' stops at the ceiling today.');
                self::assertStringContainsString('system:credential-recovery is not authorized', $refused['errors']);
            }
            self::assertNull($identities->authenticate($stronger['email'], $chosen, 'security-qualification'));
            self::assertNotNull($identities->authenticate(
                $stronger['email'],
                'correct horse battery',
                'security-qualification',
            ), 'The stronger account keeps its password.');

            $ended = $harness->console(RecoverCredentialsCommand::class, [
                'terminate-sessions',
                '--email=' . $stronger['email'],
            ]);
            self::assertSame(0, $ended['status'], 'Ending a stronger account\'s sessions is not a takeover.');

            $recovered = $harness->console(RecoverCredentialsCommand::class, [
                'reset-password',
                '--email=' . $grantless,
                '--password-file=' . $file,
            ]);
            self::assertSame(0, $recovered['status'], $recovered['errors']);
            self::assertNotNull($identities->authenticate($grantless, $chosen, 'security-qualification'));
        } finally {
            unlink($file);
        }
    }

    /**
     * Authenticate a qualification actor and hand back the context the access screen issues after its step-up.
     *
     * Account recovery left every machine surface, so the ceiling is proved where it lives: on the service,
     * reached the one way that remains, with the actor's own consumed step-up proof.
     *
     * @param   AdministratorIdentityGateway  $identities  Identity gateway of the booted kernel.
     * @param   string                        $email       Actor's sign-in address.
     *
     * @return  ExecutionContext  Stepped-up context of that actor.
     *
     * @since   2.0.0
     */
    private static function steppedManager(AdministratorIdentityGateway $identities, string $email): ExecutionContext
    {
        $principal = $identities->authenticate($email, 'correct horse battery', 'security-qualification');
        self::assertNotNull($principal, 'The user manager signs in.');

        return TestKernelFactory::steppedContextFor($principal);
    }
}
