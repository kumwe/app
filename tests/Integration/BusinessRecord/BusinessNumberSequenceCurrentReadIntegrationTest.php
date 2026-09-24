<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\BusinessRecord;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Kumwe\App\BusinessRecord\Infrastructure\Persistence\DoctrineBusinessNumberSequenceAllocator;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\Migration\BusinessNumberSequenceMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\Sequence\Exception\NumberSequenceUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins counter current reads after a peer commits beyond an existing ordinary-read snapshot (#152).
 *
 * MariaDB's newer snapshot-isolation default turns this interleaving into error 1020 unless application
 * connection setup explicitly keeps traditional InnoDB locking reads. These tests exercise production
 * connection setup and allocation, including rollback, held-row exclusion and unrelated-counter progress.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineConnectionFactory::class)]
#[CoversClass(DoctrineBusinessNumberSequenceAllocator::class)]
final class BusinessNumberSequenceCurrentReadIntegrationTest extends TestCase
{
    /**
     * A locking allocation observes a newer committed value while an ordinary repeatable read stays old.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAllocationUsesTheCurrentCounterAndRollbackReturnsItsNumber(): void
    {
        [$database, $peer, $tables] = $this->sessions();
        $counter = Uuid::uuid7()->toString();
        try {
            $database->beginTransaction();
            self::assertSame(1, $this->allocate($database, $tables, $counter));
            $database->commit();
            for ($iteration = 1; $iteration <= 12; ++$iteration) {
                $database->beginTransaction();
                self::assertSame($iteration, $this->value($database, $tables, $counter));
                $peer->beginTransaction();
                self::assertSame($iteration + 1, $this->allocate($peer, $tables, $counter));
                $peer->commit();
                if ($database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                    self::assertSame($iteration, $this->value($database, $tables, $counter));
                }
                self::assertSame($iteration + 2, $this->allocate($database, $tables, $counter));
                $database->rollBack();
                self::assertSame($iteration + 1, $this->value($peer, $tables, $counter));
            }
            $database->beginTransaction();
            self::assertSame(14, $this->allocate($database, $tables, $counter));
            $database->commit();
            self::assertSame(14, $this->value($peer, $tables, $counter));
        } finally {
            $this->cleanup($database, $peer, $tables, [$counter]);
        }
    }

    /**
     * Current reads retain counter exclusion and do not expose uncommitted allocations to a plain reader.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHotCounterStillExcludesItsPeerWithoutBlockingAnotherCounter(): void
    {
        [$database, $peer, $tables] = $this->sessions();
        $hot = Uuid::uuid7()->toString();
        $other = Uuid::uuid7()->toString();
        try {
            $database->beginTransaction();
            self::assertSame(1, $this->allocate($database, $tables, $hot));
            self::assertSame(1, $this->allocate($database, $tables, $other));
            $database->commit();
            $database->beginTransaction();
            self::assertSame(2, $this->allocate($database, $tables, $hot));
            $peer->beginTransaction();
            self::assertSame(1, $this->value($peer, $tables, $hot));
            self::assertSame(2, $this->allocate($peer, $tables, $other));
            $peer->commit();
            $peer->beginTransaction();
            try {
                $this->allocate($peer, $tables, $hot);
                self::fail('A current read must not bypass another transaction holding this counter.');
            } catch (NumberSequenceUnavailable $failure) {
                self::assertInstanceOf(DbalException::class, $failure->getPrevious());
            }
            $peer->rollBack();
            $database->rollBack();
            $peer->beginTransaction();
            self::assertSame(2, $this->allocate($peer, $tables, $hot));
            $peer->commit();
            self::assertSame(2, $this->value($database, $tables, $hot));
            self::assertSame(2, $this->value($database, $tables, $other));
        } finally {
            $this->cleanup($database, $peer, $tables, [$hot, $other]);
        }
    }

    /**
     * Open two production sessions and bound the refusal test's lock wait to one second.
     *
     * @return  array{Connection, Connection, TableNames}  Independent sessions sharing the counter table.
     *
     * @since   2.0.0
     */
    private function sessions(): array
    {
        $configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
        $factory = new DoctrineConnectionFactory($configuration->database);
        $database = $factory->create();
        $peer = $factory->create();
        $suffix = substr(str_replace('-', '', Uuid::uuid7()->toString()), -8);
        $tables = new TableNames($database, 'nsread_' . $suffix . '_');
        (new BusinessNumberSequenceMigration($tables))->up($database);
        foreach ([$database, $peer] as $connection) {
            if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $connection->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            } else {
                $connection->executeStatement("SET lock_timeout = '1s'");
            }
        }

        return [$database, $peer, $tables];
    }

    /**
     * Allocate on the production adapter with one stable field, scope and period.
     *
     * @param   Connection  $database  Session owning the open transaction.
     * @param   TableNames  $tables    Installation table names.
     * @param   string      $counter   Test-owned definition identity.
     *
     * @return  int  Reserved counter value.
     *
     * @since   2.0.0
     */
    private function allocate(Connection $database, TableNames $tables, string $counter): int
    {
        return (new DoctrineBusinessNumberSequenceAllocator($database, $tables))->allocate(
            'current-read-fixture',
            $counter,
            'number',
            '-',
            '',
            new DateTimeImmutable('2026-09-24T00:00:00+00:00'),
        );
    }

    /**
     * Observe the counter using an ordinary MVCC read, without acquiring a row lock.
     *
     * @param   Connection  $database  Session whose ordinary snapshot is observed.
     * @param   TableNames  $tables    Installation table names.
     * @param   string      $counter   Test-owned definition identity.
     *
     * @return  int  Visible value.
     *
     * @since   2.0.0
     */
    private function value(Connection $database, TableNames $tables, string $counter): int
    {
        $value = $database->fetchOne(sprintf(
            'SELECT current_value FROM %s WHERE definition_id = ?',
            $tables->quoted('business_number_sequences'),
        ), [$counter]);
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    /**
     * Release transaction locks and delete only this test's counter identities.
     *
     * @param   Connection    $database  First session.
     * @param   Connection    $peer      Other session.
     * @param   TableNames    $tables    Installation table names.
     * @param   list<string>  $counters  Test-owned definition identities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function cleanup(Connection $database, Connection $peer, TableNames $tables, array $counters): void
    {
        foreach ([$database, $peer] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        foreach ($counters as $counter) {
            $database->delete($tables->raw('business_number_sequences'), ['definition_id' => $counter]);
        }
        $database->createSchemaManager()->dropTable($tables->raw('business_number_sequences'));
        $database->close();
        $peer->close();
    }
}
