<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Automation\QueueRuntimePolicy;
use RuntimeException;

/**
 * Shares a bounded set of durable permits between job and inbox claims.
 *
 * A permit carries the same token and expiry as its work row. Claim, renewal and settlement update both
 * in one transaction. Expired permits are reclaimable without scanning the work backlog.
 * Availability probes at most 1024 permits, never an aggregate over a backlog.
 * Policy publication locks the permit set; an ordinary claim reads its candidates unlocked and then locks
 * exactly one permit by primary key with SKIP LOCKED, so no engine's sort or limit strategy widens the lock.
 *
 * @since  2.0.0
 */
final readonly class DoctrineQueuePermits
{
    /**
     * Bind capacity arbitration to the same connection as the work transaction.
     *
     * @param  Connection  $database  Job and inbox transaction connection.
     * @param  TableNames  $tables    Installation table names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Publish a changed policy outside the hot claim transaction; unchanged generations only read.
     *
     * Existing reservations are adopted on first use, including leases held before the migration.
     * Never recreate the permit namespace on a generation change: old holders still consume capacity.
     *
     * @param   QueueRuntimePolicy  $policy  Current trusted declaration.
     * @param   DateTimeImmutable   $now     Lease comparison instant.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stale generation or a contradictory policy attempts publication.
     *
     * @since   2.0.0
     */
    public function synchronize(QueueRuntimePolicy $policy, DateTimeImmutable $now): void
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT runtime_generation, maximum_in_flight FROM %s WHERE queue_id = ? AND slot_number = 0',
            $this->tables->quoted('job_queue_permits'),
        ), [$policy->queue]);
        if ($row !== false && $this->integer($row['runtime_generation']) === $policy->runtimeGeneration) {
            if ($this->integer($row['maximum_in_flight']) !== $policy->maximumInFlight) {
                throw new RuntimeException('A queue policy changed without a runtime generation change.');
            }
            return;
        }
        $this->database->transactional(function () use ($policy, $now): void {
            // This row is a publication mutex only. No unchanged-generation claim locks or updates it.
            $this->insertRuntime($policy, $now);
            $this->database->fetchOne(sprintf(
                'SELECT queue_id FROM %s WHERE queue_id = ?%s',
                $this->tables->quoted('job_queue_runtime'),
                $this->lock(false),
            ), [$policy->queue]);
            $slots = $this->database->fetchAllAssociative(sprintf(
                'SELECT * FROM %s WHERE queue_id = ? ORDER BY slot_number%s',
                $this->tables->quoted('job_queue_permits'),
                $this->lock(false),
            ), [$policy->queue]);
            if ($slots !== [] && $this->integer($slots[0]['runtime_generation']) > $policy->runtimeGeneration) {
                throw new RuntimeException('A stale runtime cannot republish queue permits.');
            }
            if (
                $slots !== [] && $this->integer($slots[0]['runtime_generation']) === $policy->runtimeGeneration
                && $this->integer($slots[0]['maximum_in_flight']) !== $policy->maximumInFlight
            ) {
                throw new RuntimeException('A queue policy changed without a runtime generation change.');
            }
            $adopt = [];
            if ($slots === []) {
                $jobs = $this->database->fetchAllAssociative(sprintf(
                    "SELECT 'job' AS work_kind, id AS work_id, '' AS consumer_id, "
                    . 'lease_token, lease_expires_at FROM %s '
                    . "WHERE queue = ? AND status = 'reserved' AND lease_expires_at > ? LIMIT 1025",
                    $this->tables->quoted('jobs'),
                ), [$policy->queue, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);
                // Separate bounded reads also preserve legacy VARCHAR job tokens on PostgreSQL;
                // UNION would require coercing them to the inbox's UUID column type.
                $inbox = $this->database->fetchAllAssociative(sprintf(
                    "SELECT 'inbox' AS work_kind, event_id AS work_id, consumer_id, lease_token, "
                    . "lease_expires_at FROM %s WHERE queue = ? AND status = 'reserved' "
                    . 'AND lease_expires_at > ? LIMIT 1025',
                    $this->tables->quoted('integration_inbox'),
                ), [$policy->queue, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);
                $adopt = [...$jobs, ...$inbox];
            }
            $size = max($policy->maximumInFlight, count($slots), count($adopt));
            if ($size > 1024) {
                throw new RuntimeException('Existing reservations exceed the supported queue permit bound.');
            }
            for ($slot = count($slots); $slot < $size; $slot++) {
                $this->database->insert($this->tables->raw('job_queue_permits'), [
                    'queue_id' => $policy->queue,
                    'slot_number' => $slot,
                    'runtime_generation' => $policy->runtimeGeneration,
                    'maximum_in_flight' => $policy->maximumInFlight,
                    'work_kind' => $adopt[$slot]['work_kind'] ?? null,
                    'work_id' => $adopt[$slot]['work_id'] ?? null,
                    'consumer_id' => $adopt[$slot]['consumer_id'] ?? null,
                    'lease_token' => $adopt[$slot]['lease_token'] ?? null,
                    'lease_expires_at' => $adopt[$slot]['lease_expires_at'] ?? null,
                ]);
            }
            $this->database->update($this->tables->raw('job_queue_permits'), [
                'runtime_generation' => $policy->runtimeGeneration,
                'maximum_in_flight' => $policy->maximumInFlight,
            ], ['queue_id' => $policy->queue]);
            $this->database->update($this->tables->raw('job_queue_runtime'), [
                'lease_seconds' => $policy->leaseSeconds,
                'maximum_attempts' => $policy->maximumAttempts,
                'maximum_in_flight' => $policy->maximumInFlight,
                'retention_days' => $policy->retentionDays,
                'runtime_generation' => $policy->runtimeGeneration,
                'updated_at' => $now,
            ], ['queue_id' => $policy->queue], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        });
    }

    /**
     * Reserve one permit in the transaction that stamps the supplied token on its authoritative work row.
     *
     * Shrinking a policy drains live retired slots before admitting any replacement work. This deliberately
     * sacrifices temporary utilization instead of allowing old and new generations to add their ceilings.
     *
     * @param   QueueRuntimePolicy  $policy      Trusted queue limits.
     * @param   DateTimeImmutable   $now         Lease comparison instant.
     * @param   string              $kind        Either job or inbox.
     * @param   string              $id          Job or event identity.
     * @param   string              $consumerId  Inbox identity, empty for a job.
     * @param   string              $token       Fresh work lease fence.
     * @param   DateTimeImmutable   $expiresAt   Expiration shared with the work row.
     *
     * @return  bool  Whether this transaction obtained capacity.
     *
     * @throws  RuntimeException  When called outside a transaction.
     *
     * @since   2.0.0
     */
    public function acquire(
        QueueRuntimePolicy $policy,
        DateTimeImmutable $now,
        string $kind,
        string $id,
        string $consumerId,
        string $token,
        DateTimeImmutable $expiresAt,
    ): bool {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('Queue permits must share the work claim transaction.');
        }
        // Read the candidates without locking, then lock one by primary key. A sorted locking read with
        // LIMIT 1 locks every free permit it examines on MySQL before the limit applies, so a second
        // replica sees nothing left to skip to even though only one slot was ever handed out.
        $candidates = $this->database->fetchFirstColumn(sprintf(
            'SELECT p.slot_number FROM %s p WHERE p.queue_id = ? AND p.runtime_generation = ? '
            . 'AND p.slot_number < ? AND (p.lease_expires_at IS NULL OR p.lease_expires_at <= ?) '
            . 'ORDER BY p.slot_number LIMIT 1024',
            $this->tables->quoted('job_queue_permits'),
        ), [$policy->queue, $policy->runtimeGeneration, $policy->maximumInFlight, $now], [
            Types::STRING, Types::INTEGER, Types::INTEGER, Types::DATETIME_IMMUTABLE,
        ]);
        if ($candidates === []) {
            return false;
        }
        $retired = $this->database->fetchOne(sprintf(
            'SELECT retired.slot_number FROM %s retired WHERE retired.queue_id = ? '
            . 'AND retired.slot_number >= ? AND retired.lease_expires_at > ? LIMIT 1',
            $this->tables->quoted('job_queue_permits'),
        ), [$policy->queue, $policy->maximumInFlight, $now], [
            Types::STRING, Types::INTEGER, Types::DATETIME_IMMUTABLE,
        ]);
        if ($retired !== false) {
            return false;
        }
        foreach ($candidates as $candidate) {
            $slot = $this->integer($candidate);
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT p.slot_number FROM %s p WHERE p.queue_id = ? AND p.slot_number = ? '
                . 'AND p.runtime_generation = ? AND (p.lease_expires_at IS NULL OR p.lease_expires_at <= ?)%s',
                $this->tables->quoted('job_queue_permits'),
                $this->lock(true),
            ), [$policy->queue, $slot, $policy->runtimeGeneration, $now], [
                Types::STRING, Types::INTEGER, Types::INTEGER, Types::DATETIME_IMMUTABLE,
            ]);
            if ($row === false) {
                continue;
            }
            $this->database->update($this->tables->raw('job_queue_permits'), [
                'work_kind' => $kind, 'work_id' => $id, 'consumer_id' => $consumerId, 'lease_token' => $token,
                'lease_expires_at' => $expiresAt, 'last_claimed_at' => $now,
            ], ['queue_id' => $policy->queue, 'slot_number' => $slot], [
                'lease_expires_at' => Types::DATETIME_IMMUTABLE, 'last_claimed_at' => Types::DATETIME_IMMUTABLE,
            ]);
            return true;
        }
        return false;
    }

    /**
     * Renew the fenced permit in the same transaction as its work lease.
     *
     * @param   QueueRuntimePolicy  $policy     Policy loaded by the worker.
     * @param   string              $token      Work lease fence.
     * @param   DateTimeImmutable   $now        Lease comparison instant.
     * @param   DateTimeImmutable   $expiresAt  New shared expiration.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the permit expired, changed generation, or was reassigned.
     *
     * @since   2.0.0
     */
    public function renew(
        QueueRuntimePolicy $policy,
        string $token,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): void {
        $changed = $this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = ? WHERE queue_id = ? AND lease_token = ? '
            . 'AND runtime_generation = ? AND slot_number < ? AND lease_expires_at > ?',
            $this->tables->quoted('job_queue_permits'),
        ), [$expiresAt, $policy->queue, $token, $policy->runtimeGeneration, $policy->maximumInFlight, $now], [
            Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID, Types::INTEGER,
            Types::INTEGER, Types::DATETIME_IMMUTABLE,
        ]);
        if ((string) $changed !== '1') {
            throw new RuntimeException('The worker no longer owns a current queue permit.');
        }
    }

    /**
     * Release capacity after fenced work settlement in the same transaction.
     *
     * @param   string  $queue  Logical queue name.
     * @param   string  $token  Settled work token; stale holders cannot release a replacement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function release(string $queue, string $token): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = NULL, lease_token = NULL, work_kind = NULL, '
            . 'work_id = NULL, consumer_id = NULL WHERE queue_id = ? AND lease_token = ?',
            $this->tables->quoted('job_queue_permits'),
        ), [$queue, $token]);
    }

    /**
     * Insert publication metadata without making a unique collision abort a PostgreSQL transaction.
     *
     * @param   QueueRuntimePolicy  $policy  Policy to initialize.
     * @param   DateTimeImmutable   $now     Publication timestamp.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertRuntime(QueueRuntimePolicy $policy, DateTimeImmutable $now): void
    {
        $suffix = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE queue_id = queue_id'
            : ' ON CONFLICT (queue_id) DO NOTHING';
        $this->database->executeStatement(sprintf(
            'INSERT INTO %s (queue_id, lease_seconds, maximum_attempts, maximum_in_flight, retention_days, '
            . 'runtime_generation, last_claimed_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)%s',
            $this->tables->quoted('job_queue_runtime'),
            $suffix,
        ), [$policy->queue, $policy->leaseSeconds, $policy->maximumAttempts, $policy->maximumInFlight,
            $policy->retentionDays, $policy->runtimeGeneration, $now], [
            Types::STRING, Types::INTEGER, Types::INTEGER, Types::INTEGER, Types::INTEGER,
            Types::INTEGER, Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Normalize a DBAL integer without accepting malformed durable policy metadata.
     *
     * @param   mixed  $value  Driver integer or decimal string.
     *
     * @return  int  Nonnegative policy value.
     *
     * @throws  RuntimeException  When policy metadata is malformed.
     *
     * @since   2.0.0
     */
    private function integer(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException('Queue permit policy metadata is malformed.');
    }

    /**
     * Compile portable row locking for supported engines; SQLite serializes write transactions itself.
     *
     * @param   bool  $skip  Whether contended permits should be skipped.
     *
     * @return  string  Locking suffix.
     *
     * @since   2.0.0
     */
    private function lock(bool $skip): string
    {
        $platform = $this->database->getDatabasePlatform();
        return $platform instanceof AbstractMySQLPlatform || $platform instanceof PostgreSQLPlatform
            ? ' FOR UPDATE' . ($skip ? ' SKIP LOCKED' : '') : '';
    }
}
