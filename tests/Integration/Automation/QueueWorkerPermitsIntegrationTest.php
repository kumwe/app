<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Automation\DoctrineQueuePermits;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessIntegrationSdkMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\JobRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\QueueWorkerPermitsMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Automation\QueueRuntimePolicy;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\App\Application\Authorization\SystemPrincipal;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Infrastructure\Automation\DoctrineQueueRuntimeOperations;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\Context\Value\SiteContext;
use Psr\Clock\ClockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Pins shared job/inbox capacity, crash expiry, stale fencing and policy contraction in durable storage.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineQueuePermits::class)]
#[CoversClass(DoctrineQueueRuntimeOperations::class)]
#[CoversClass(QueueWorkerPermitsMigration::class)]
final class QueueWorkerPermitsIntegrationTest extends TestCase
{
    /**
     * Prove job and inbox replicas share the ceiling and a stale release cannot free its replacement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSharedCapacityExpiryAndFencedRelease(): void
    {
        [$database, $permits] = $this->store();
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $policy = new QueueRuntimePolicy('acme.work', 60, 5, 2, 7, 9);
        $permits->synchronize($policy, $now);
        $job = Uuid::uuid7()->toString();
        $inbox = Uuid::uuid7()->toString();
        $replacement = Uuid::uuid7()->toString();
        $claim = static fn (string $kind, string $token, DateTimeImmutable $at): bool =>
            $database->transactional(static fn (): bool => $permits->acquire(
                $policy,
                $at,
                $kind,
                Uuid::uuid7()->toString(),
                'acme.consumer',
                $token,
                $at->modify('+60 seconds'),
            ));
        self::assertTrue($claim('job', $job, $now));
        self::assertTrue($claim('inbox', $inbox, $now));
        self::assertFalse($claim('job', $replacement, $now));
        $later = $now->modify('+61 seconds');
        self::assertTrue($claim('job', $replacement, $later));
        $database->transactional(static fn () => $permits->release('acme.work', $job));
        self::assertTrue($claim('inbox', Uuid::uuid7()->toString(), $later));
        self::assertFalse($claim('inbox', Uuid::uuid7()->toString(), $later));
        $database->transactional(static fn () => $permits->release('acme.work', $replacement));
        self::assertTrue($claim('inbox', Uuid::uuid7()->toString(), $later));
    }

    /**
     * Prove a crash rolls back permit acquisition and renewing extends the actual capacity fence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRollbackAndRenewalRetainOneAuthoritativeCapacityFence(): void
    {
        [$database, $permits] = $this->store();
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $policy = new QueueRuntimePolicy('acme.work', 60, 5, 1, 7, 9);
        $permits->synchronize($policy, $now);
        $token = Uuid::uuid7()->toString();
        $database->beginTransaction();
        self::assertTrue($permits->acquire($policy, $now, 'job', $token, '', $token, $now->modify('+10 seconds')));
        $database->rollBack();
        $database->transactional(function () use ($permits, $policy, $now, $token): void {
            $expires = $now->modify('+10 seconds');
            self::assertTrue($permits->acquire($policy, $now, 'inbox', $token, 'c', $token, $expires));
            $permits->renew($policy, $token, $now->modify('+5 seconds'), $now->modify('+60 seconds'));
        });
        self::assertFalse($database->transactional(static fn (): bool => $permits->acquire(
            $policy,
            $now->modify('+11 seconds'),
            'job',
            $token,
            '',
            Uuid::uuid7()->toString(),
            $now->modify('+70 seconds'),
        )));
        $this->expectException(RuntimeException::class);
        $database->transactional(static fn () => $permits->renew(
            $policy,
            $token,
            $now->modify('+61 seconds'),
            $now->modify('+90 seconds'),
        ));
    }

    /**
     * Prove a smaller generation drains retired slots and rejects an old replica's claims and renewals.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContractionNeverAddsOldAndNewGenerationCapacity(): void
    {
        [$database, $permits] = $this->store();
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $old = new QueueRuntimePolicy('acme.work', 60, 5, 2, 7, 9);
        $new = new QueueRuntimePolicy('acme.work', 60, 5, 1, 7, 10);
        $permits->synchronize($old, $now);
        $first = Uuid::uuid7()->toString();
        $second = Uuid::uuid7()->toString();
        foreach ([$first, $second] as $token) {
            self::assertTrue($database->transactional(static fn (): bool => $permits->acquire(
                $old,
                $now,
                'job',
                $token,
                '',
                $token,
                $now->modify('+60 seconds'),
            )));
        }
        $permits->synchronize($new, $now);
        $database->transactional(static fn () => $permits->release('acme.work', $first));
        self::assertFalse($database->transactional(static fn (): bool => $permits->acquire(
            $new,
            $now,
            'job',
            $first,
            '',
            $first,
            $now->modify('+60 seconds'),
        )), 'The retired slot still holds a live lease.');
        $database->transactional(static fn () => $permits->release('acme.work', $second));
        self::assertFalse($database->transactional(static fn (): bool => $permits->acquire(
            $old,
            $now,
            'job',
            $first,
            '',
            $first,
            $now->modify('+60 seconds'),
        )));
        self::assertTrue($database->transactional(static fn (): bool => $permits->acquire(
            $new,
            $now,
            'job',
            $first,
            '',
            $first,
            $now->modify('+60 seconds'),
        )));
        $this->expectException(RuntimeException::class);
        $permits->synchronize($old, $now);
    }

    /**
     * Keep the operator timestamp contract after settlement without writing the former hot runtime row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInventoryRetainsLastClaimFromDurablePermitsAfterSettlement(): void
    {
        [$database, $permits] = $this->store();
        $tables = new TableNames($database, 'permit_');
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $policy = new QueueRuntimePolicy('acme.work', 60, 5, 2, 7, 9);
        $permits->synchronize($policy, $now);
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);
        $policies = self::createStub(QueueRuntimePolicyCatalog::class);
        $policies->method('policies')->willReturn([$policy]);
        $operations = new DoctrineQueueRuntimeOperations(
            $database,
            $tables,
            new DoctrineTransactionManager($database),
            $clock,
            self::createStub(AuthorizationGateway::class),
            $policies,
        );
        $context = SystemPrincipal::issue(new \stdClass(), SystemIdentity::Worker)->context(
            SiteContext::fromString('default'),
            'queue-inventory',
            'correlation',
        );
        self::assertNull($operations->inventory($context)[0]['last_claimed_at']);
        foreach ([0, 1] as $offset) {
            $at = $now->modify(sprintf('+%d seconds', $offset));
            $token = Uuid::uuid7()->toString();
            self::assertTrue($database->transactional(static fn (): bool => $permits->acquire(
                $policy,
                $at,
                'job',
                $token,
                '',
                $token,
                $at->modify('+60 seconds'),
            )));
            $database->transactional(static fn () => $permits->release($policy->queue, $token));
        }
        $last = $operations->inventory($context)[0]['last_claimed_at'];
        self::assertIsString($last);
        self::assertEquals($now->modify('+1 second'), new DateTimeImmutable($last));
        self::assertNull($database->fetchOne('SELECT last_claimed_at FROM permit_job_queue_runtime'));
    }

    /**
     * Refuse durable permit state the policy cannot account for, and a claim outside its work transaction.
     *
     * A ceiling that changes under an unchanged generation would let two replicas enforce different limits
     * for the same generation, so it is refused instead of being silently republished. A permit row whose
     * generation is not a nonnegative integer is malformed metadata, not a stale generation to overwrite. More
     * live reservations than one queue may ever hold cannot be adopted into permits, and a claim that does not
     * share the work claim's transaction could outlive the rolled-back work it was taken for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnaccountablePermitStateAndAnUntransactedClaimAreRefused(): void
    {
        [$database, $permits] = $this->store();
        $now = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $policy = new QueueRuntimePolicy('acme.work', 60, 5, 2, 7, 9);
        $permits->synchronize($policy, $now);
        $refusal = static function (callable $attempt): string {
            try {
                $attempt();
            } catch (RuntimeException $refused) {
                return $refused->getMessage();
            }
            self::fail('The permit operation must be refused.');
        };

        self::assertSame(
            'A queue policy changed without a runtime generation change.',
            $refusal(static fn () => $permits->synchronize(new QueueRuntimePolicy('acme.work', 60, 5, 3, 7, 9), $now)),
        );
        self::assertSame(
            'Queue permits must share the work claim transaction.',
            $refusal(static fn () => $permits->acquire(
                $policy,
                $now,
                'job',
                Uuid::uuid7()->toString(),
                '',
                Uuid::uuid7()->toString(),
                $now->modify('+60 seconds'),
            )),
        );
        $database->executeStatement(
            "UPDATE permit_job_queue_permits SET runtime_generation = -1 WHERE queue_id = 'acme.work'",
        );
        self::assertSame(
            'Queue permit policy metadata is malformed.',
            $refusal(static fn () => $permits->synchronize($policy, $now)),
        );

        $crowded = new QueueRuntimePolicy('acme.crowded', 60, 5, 1, 7, 1);
        $database->transactional(static function () use ($database, $now): void {
            for ($index = 0; $index < 1_025; $index++) {
                $database->insert('permit_jobs', [
                    'id' => Uuid::uuid7()->toString(), 'queue' => 'acme.crowded', 'job_type' => 'acme.crowded',
                    'payload' => '{}', 'status' => 'reserved', 'available_at' => $now->format('Y-m-d H:i:s'),
                    'lease_token' => Uuid::uuid7()->toString(),
                    'lease_expires_at' => $now->modify('+1 hour')->format('Y-m-d H:i:s'),
                    'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
                ]);
            }
        });
        self::assertSame(
            'Existing reservations exceed the supported queue permit bound.',
            $refusal(static fn () => $permits->synchronize($crowded, $now)),
        );
        self::assertSame(
            '0',
            (string) $database->fetchOne(
                "SELECT COUNT(*) FROM permit_job_queue_permits WHERE queue_id = 'acme.crowded'",
            ),
            'A refused publication leaves no partial permit set behind.',
        );
    }

    /**
     * Build an isolated durable schema without relying on parent-owned container wiring.
     *
     * @return  array{Connection, DoctrineQueuePermits}  Connection and shared permit adapter.
     *
     * @since   2.0.0
     */
    private function store(): array
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'permit_');
        (new CoreSchemaMigration($tables))->up($database);
        (new JobRecoveryMigration($tables))->up($database);
        (new BusinessIntegrationSdkMigration($tables))->up($database);
        $migration = new QueueWorkerPermitsMigration($tables);
        $migration->up($database);
        $migration->up($database);
        return [$database, new DoctrineQueuePermits($database, $tables)];
    }
}
