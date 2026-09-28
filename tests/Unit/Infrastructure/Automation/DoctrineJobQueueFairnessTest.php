<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Automation\DoctrineJobQueueFairness;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins that a fairness turn is taken only inside the work claim it orders, and only for claimable work.
 *
 * A turn advanced outside the claim's transaction would survive a claim that rolled back, pushing the tenant
 * to the back of the queue for work it never received. And a lane whose only job is still in the future has
 * nothing to hand out, so claiming must leave its turn untouched.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineJobQueueFairness::class)]
final class DoctrineJobQueueFairnessTest extends TestCase
{
    /**
     * A claim outside the work claim transaction is refused before any turn is read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATurnIsNeverTakenOutsideTheWorkClaimTransaction(): void
    {
        $database = self::database();

        try {
            self::fairness($database)->claim('default', new DateTimeImmutable('2026-09-24T12:00:00+00:00'));
            self::fail('A fairness turn outside the claim transaction must be refused.');
        } catch (RuntimeException $refusal) {
            self::assertSame('Queue fairness must share the work claim transaction.', $refusal->getMessage());
        }
    }

    /**
     * A recorded lane whose only job is not yet due yields no turn and keeps its place.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALaneWithOnlyFutureWorkYieldsNoTurnAndKeepsItsPlace(): void
    {
        $database = self::database();
        $database->insert('kumwe_jobs', [
            'id' => 'job-1', 'queue' => 'default', 'status' => 'pending',
            'available_at' => '2026-09-25 00:00:00', 'execution_scope' => 'installation',
        ]);
        $fairness = self::fairness($database);
        $fairness->record('default', 'job-1', 'default', null);

        $database->beginTransaction();
        try {
            self::assertNull($fairness->claim('default', new DateTimeImmutable('2026-09-24T12:00:00+00:00')));
        } finally {
            $database->rollBack();
        }
        self::assertSame(
            '0',
            (string) $database->fetchOne('SELECT claim_count FROM kumwe_job_queue_turns'),
            'An empty claim does not advance the lane.',
        );
    }

    /**
     * Build the fairness adapter over the given engine.
     *
     * @param   Connection  $database  Engine holding the queue tables.
     *
     * @return  DoctrineJobQueueFairness  Adapter under test.
     *
     * @since   2.0.0
     */
    private static function fairness(Connection $database): DoctrineJobQueueFairness
    {
        return new DoctrineJobQueueFairness($database, new TableNames($database, 'kumwe_'));
    }

    /**
     * Open an in-memory engine with the columns fairness reads.
     *
     * @return  Connection  Engine holding empty queue tables.
     *
     * @since   2.0.0
     */
    private static function database(): Connection
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $database->executeStatement(
            'CREATE TABLE kumwe_job_queue_turns (queue_id TEXT, scope_key TEXT, last_claimed_at TEXT, '
            . 'claim_count INTEGER, PRIMARY KEY (queue_id, scope_key))',
        );
        $database->executeStatement(
            'CREATE TABLE kumwe_jobs (id TEXT PRIMARY KEY, queue TEXT, status TEXT, available_at TEXT, '
            . 'lease_expires_at TEXT, execution_scope TEXT, worker_scope TEXT)',
        );
        $database->executeStatement(
            'CREATE TABLE kumwe_resource_site_ownership (resource_type TEXT, resource_id TEXT, site_identifier TEXT)',
        );
        $database->executeStatement('CREATE TABLE kumwe_sites (identifier TEXT, enabled INTEGER)');

        return $database;
    }
}
