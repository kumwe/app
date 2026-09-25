<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Automation\JobExecutionClass;
use RuntimeException;

/**
 * Gives contributed queue tenants durable round-robin turns before deterministic job priority ordering.
 *
 * @since  2.0.0
 */
final readonly class DoctrineJobQueueFairness
{
    /**
     * Bind fairness turns to the same transaction as job creation and claiming.
     *
     * @param  Connection  $database  Work transaction connection.
     * @param  TableNames  $tables    Installation names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Register a tenant lane and stamp its jobs without deriving authority from the scheduling key.
     *
     * @param   string   $queue         Logical queue.
     * @param   string   $jobId         Persisted job identity.
     * @param   ?string  $site          Owning site, null only for installation-wide work.
     * @param   ?string  $organization  Organization at enqueue, null for site-wide schedules.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $queue, string $jobId, ?string $site, ?string $organization): void
    {
        $scope = hash('sha256', ($site ?? '') . "\0" . ($organization ?? ''));
        $suffix = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE queue_id = queue_id'
            : ' ON CONFLICT (queue_id, scope_key) DO NOTHING';
        $this->database->executeStatement(sprintf(
            'INSERT INTO %s (queue_id, scope_key, last_claimed_at, claim_count) VALUES (?, ?, ?, 0)%s',
            $this->tables->quoted('job_queue_turns'),
            $suffix,
        ), [$queue, $scope, new DateTimeImmutable('1970-01-01T00:00:00+00:00')], [
            Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]);
        $this->database->update($this->tables->raw('jobs'), ['worker_scope' => $scope], ['id' => $jobId]);
    }

    /**
     * Select the least recently served runnable tenant without waiting for another claimant's lane.
     *
     * @param   string             $queue  Logical queue.
     * @param   DateTimeImmutable  $now    Runnable-work comparison time.
     *
     * @return  ?string  Scheduling scope held until this transaction commits, or null for no runnable lane.
     *
     * @throws  RuntimeException  When invoked outside a work claim transaction.
     *
     * @since   2.0.0
     */
    public function claim(string $queue, DateTimeImmutable $now): ?string
    {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('Queue fairness must share the work claim transaction.');
        }
        $platform = $this->database->getDatabasePlatform();
        $jobId = $platform instanceof PostgreSQLPlatform ? 'CAST(j.id AS VARCHAR)' : 'j.id';
        $lock = $platform instanceof PostgreSQLPlatform || $platform instanceof AbstractMySQLPlatform
            ? ' FOR UPDATE SKIP LOCKED' : '';
        $candidates = $this->database->fetchFirstColumn(sprintf(
            'SELECT t.scope_key FROM %s t WHERE t.queue_id = ? AND EXISTS (SELECT 1 FROM %s j '
            . 'WHERE j.queue = t.queue_id AND j.worker_scope = t.scope_key AND '
            . "((j.status = 'pending' AND j.available_at <= ?) OR "
            . "(j.status = 'reserved' AND (j.lease_expires_at IS NULL OR j.lease_expires_at <= ?))) "
            . 'AND (j.execution_scope = ? OR EXISTS (SELECT 1 FROM %s o INNER JOIN %s s '
            . 'ON s.identifier = o.site_identifier WHERE o.resource_type = ? AND o.resource_id = %s '
            . 'AND s.enabled = ?))) ORDER BY t.last_claimed_at, t.claim_count, t.scope_key LIMIT 64',
            $this->tables->quoted('job_queue_turns'),
            $this->tables->quoted('jobs'),
            $this->tables->quoted('resource_site_ownership'),
            $this->tables->quoted('sites'),
            $jobId,
        ), [$queue, $now, $now, JobExecutionClass::Installation->value, 'job', true], [
            Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING,
            Types::STRING, Types::BOOLEAN,
        ]);
        // Lock candidates by primary key. A filesort with FOR UPDATE can lock every scanned
        // tenant on MySQL/MariaDB, defeating SKIP LOCKED even though the result has LIMIT 1.
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                throw new RuntimeException('A queue fairness lane has an invalid scope.');
            }
            $scope = $this->database->fetchOne(sprintf(
                'SELECT scope_key FROM %s WHERE queue_id = ? AND scope_key = ?%s',
                $this->tables->quoted('job_queue_turns'),
                $lock,
            ), [$queue, $candidate]);
            if (!is_string($scope)) {
                continue;
            }
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET last_claimed_at = ?, claim_count = claim_count + 1 WHERE queue_id = ? AND scope_key = ?',
                $this->tables->quoted('job_queue_turns'),
            ), [$now, $queue, $scope], [Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING]);
            return $scope;
        }
        return null;
    }
}
