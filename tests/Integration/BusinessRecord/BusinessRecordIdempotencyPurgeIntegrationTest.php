<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessRecordIdempotencyRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\TestKernelFactory;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Proves the business idempotency ledger purge is bounded, transactional and partitioned between purgers.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineBusinessRecordIdempotencyRepository::class)]
final class BusinessRecordIdempotencyPurgeIntegrationTest extends TestCase
{
    /**
     * A purge outside a transaction, or with a batch outside 1 to 1000, is refused before it reads anything.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePurgeRefusesAnOpenEndedBatchAndAnUntransactedCall(): void
    {
        $container = TestKernelFactory::create(Environment::fromGlobals());
        $database = $this->service($container, Connection::class);
        $ledger = $this->service($container, BusinessRecordIdempotencyRepository::class);
        $now = new DateTimeImmutable();

        try {
            $ledger->purgeExpired($now, 10);
            self::fail('A purge outside the application transaction must be refused.');
        } catch (LogicException $refusal) {
            self::assertSame(
                'Business-record idempotency writes require an active application transaction.',
                $refusal->getMessage(),
            );
        }
        $database->beginTransaction();
        try {
            foreach ([0, 1_001] as $limit) {
                try {
                    $ledger->purgeExpired($now, $limit);
                    self::fail(sprintf('A purge batch of %d entries must be refused.', $limit));
                } catch (LogicException $refusal) {
                    self::assertSame(
                        'The idempotency purge batch is outside its bounded range.',
                        $refusal->getMessage(),
                    );
                }
            }
        } finally {
            $database->rollBack();
        }
    }

    /**
     * Two concurrent purgers take different expired entries, and neither waits on the other's lock.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConcurrentPurgersPartitionTheOldestExpiredEntriesWithoutWaiting(): void
    {
        $environment = Environment::fromGlobals();
        $primary = TestKernelFactory::create($environment);
        $secondary = TestKernelFactory::create($environment);
        $database = $this->service($primary, Connection::class);
        $peer = $this->service($secondary, Connection::class);
        $tables = $this->service($primary, TableNames::class);
        $first = $this->seed($database, $tables, '1999-01-01 00:00:00');
        $second = $this->seed($database, $tables, '1999-01-02 00:00:00');
        $now = new DateTimeImmutable();
        $peer->executeStatement($peer->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'SET SESSION innodb_lock_wait_timeout = 1'
            : "SET lock_timeout = '1s'");
        try {
            $database->beginTransaction();
            self::assertSame(1, $this->service($primary, BusinessRecordIdempotencyRepository::class)->purgeExpired(
                $now,
                1,
            ));
            $peer->beginTransaction();
            self::assertSame(1, $this->service($secondary, BusinessRecordIdempotencyRepository::class)->purgeExpired(
                $now,
                1,
            ), 'The second purger skips the locked oldest entry instead of waiting for it.');
            $peer->commit();
            self::assertSame([$first], $this->remaining($peer, $tables, [$first, $second]));
            $database->commit();
            self::assertSame([], $this->remaining($peer, $tables, [$first, $second]));
        } finally {
            foreach ([$database, $peer] as $connection) {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id IN (?)',
                $tables->quoted('business_command_idempotency'),
            ), [[$first, $second]], [ArrayParameterType::STRING]);
        }
    }

    /**
     * Insert one completed ledger entry that expired at the given instant.
     *
     * @param   Connection  $database  Session.
     * @param   TableNames  $tables    Installation names.
     * @param   string      $expiry    Expiry instant.
     *
     * @return  string  Entry identity.
     *
     * @since   2.0.0
     */
    private function seed(Connection $database, TableNames $tables, string $expiry): string
    {
        $id = Uuid::uuid7()->toString();
        $database->insert($tables->raw('business_command_idempotency'), [
            'id' => $id,
            'scope_digest' => hash('sha256', 'purge-partition-' . $id),
            'site_identifier' => 'purge-partition',
            'actor_id' => 'system:purge-partition',
            'operation' => 'business.record.update',
            'operation_id' => $id,
            'request_fingerprint' => str_repeat('b', 64),
            'authorization_fingerprint' => str_repeat('c', 64),
            'state' => 'completed',
            'result' => '{}',
            'result_checksum' => str_repeat('d', 64),
            'created_at' => $expiry,
            'expires_at' => $expiry,
        ]);

        return $id;
    }

    /**
     * List which of the given entries are still committed, in the order given.
     *
     * @param   Connection    $database  Session reading committed state.
     * @param   TableNames    $tables    Installation names.
     * @param   list<string>  $ids       Entry identities.
     *
     * @return  list<string>  Surviving identities.
     *
     * @since   2.0.0
     */
    private function remaining(Connection $database, TableNames $tables, array $ids): array
    {
        $present = $database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE id IN (?)',
            $tables->quoted('business_command_idempotency'),
        ), [$ids], [ArrayParameterType::STRING]);

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $present, true)));
    }

    /**
     * Resolve a typed service.
     *
     * @template T of object
     *
     * @param   Container        $container  Booted kernel.
     * @param   class-string<T>  $id         Service identity.
     *
     * @return  T  Service.
     *
     * @since   2.0.0
     */
    private function service(Container $container, string $id): object
    {
        $service = $container->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
