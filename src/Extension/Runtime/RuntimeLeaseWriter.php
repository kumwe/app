<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Log\LoggerInterface;

/**
 * Writes one replica's materialization lease as a single statement that tolerates its own peers.
 *
 * A lease row is not owned by one process. `RuntimeIdentity` derives the key from the deployment,
 * replica, process and instance names, so every php-fpm child in a container, that container's health
 * check and every operator command executed inside it share one `replica_id` and therefore one row.
 * Claiming that row with an update, then an insert, then an update left two windows for a peer to write
 * between the statements; one upsert closes them. What is left is the server's own concurrency control,
 * which differs across the supported matrix: MariaDB 11.6.1 and later enable `innodb_snapshot_isolation`
 * by default and refuse a write against a row committed after the writer's read view opened, reporting
 * ER_CHECKREAD (1020); MySQL and PostgreSQL report the comparable conflict as a deadlock or
 * serialization failure. All of them mean the same thing here — a peer holding this identity has just
 * written this row — so the write is retried a bounded number of times and then left to the peer that
 * won, always through the log. That cannot quietly expire a lease: renewal is attempted on every unit
 * of work a replica handles, while the lease window it maintains is minutes long, so a lease can only
 * lapse if every attempt fails for that whole window, and each of those failures is logged.
 *
 * @since  2.0.0
 */
final readonly class RuntimeLeaseWriter
{
    /**
     * How many times one lease write is repeated before the renewal is left to the peer that won.
     *
     * @var    int
     * @since  2.0.0
     */
    private const ATTEMPTS = 3;

    /**
     * MariaDB's ER_CHECKREAD, raised under snapshot isolation when the row moved since the read view.
     *
     * @var    int
     * @since  2.0.0
     */
    private const RECORD_CHANGED = 1020;

    /**
     * Bind the writer to the connection, table names and log its absorbed conflicts are reported on.
     *
     * @param  Connection        $database  Registry connection the lease row lives on.
     * @param  TableNames        $tables    Prefixed physical names of the registry tables.
     * @param  ?LoggerInterface  $logger    Log absorbed peer conflicts are reported on; null keeps the
     *         writer usable in contexts that have no logger yet.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Claim or renew the lease row for one replica identity in a single atomic statement.
     *
     * @param   string             $replicaId            Lease key the row is stored under.
     * @param   RuntimeIdentity    $identity             Identity of the process claiming the lease.
     * @param   int                $generation           Generation this replica is serving.
     * @param   string             $publicationChecksum  Checksum of the publication it verified.
     * @param   string             $trustHmac            Trust HMAC of the publication it verified.
     * @param   DateTimeImmutable  $now                  Instant the claim is stamped with.
     * @param   DateTimeImmutable  $leaseUntil           Instant the claim stays valid until.
     *
     * @return  void
     *
     * @throws  DriverException  When the write fails for anything other than a concurrent write to this
     *          same lease row by a peer holding the same identity.
     *
     * @since   2.0.0
     */
    public function renew(
        string $replicaId,
        RuntimeIdentity $identity,
        int $generation,
        string $publicationChecksum,
        string $trustHmac,
        DateTimeImmutable $now,
        DateTimeImmutable $leaseUntil,
    ): void {
        $values = [
            $identity->deploymentId,
            $identity->replicaId,
            $identity->processId,
            $generation,
            $publicationChecksum,
            $trustHmac,
            $now,
            $now,
            $leaseUntil,
        ];
        $types = [
            Types::STRING,
            Types::STRING,
            Types::STRING,
            Types::BIGINT,
            Types::STRING,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
        ];
        $statement = $this->upsert();
        $parameters = [$replicaId, ...$values, ...$values];
        $bindings = [Types::STRING, ...$types, ...$types];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            try {
                $this->database->executeStatement($statement, $parameters, $bindings);

                return;
            } catch (DriverException $conflict) {
                if (!$this->concurrentPeerWrite($conflict)) {
                    throw $conflict;
                }
                $this->logger?->warning('Extension runtime lease write met a concurrent peer write.', [
                    'replica_id' => $replicaId,
                    'generation' => $generation,
                    'attempt' => $attempt,
                    'attempts' => self::ATTEMPTS,
                    'will_retry' => $attempt < self::ATTEMPTS,
                    'exception' => $conflict,
                ]);
            }
        }
    }

    /**
     * Compose the upsert in the dialect of the connected platform.
     *
     * The written values are bound twice rather than referenced back through MySQL's `VALUES()`, which
     * is deprecated there and absent from MariaDB's row-alias-free syntax, so one parameter list serves
     * every platform in the supported matrix.
     *
     * @return  string  Insert-or-update statement taking the key once and the written values twice.
     *
     * @since   2.0.0
     */
    private function upsert(): string
    {
        $conflict = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'ON DUPLICATE KEY UPDATE '
            : 'ON CONFLICT (replica_id) DO UPDATE SET ';

        return sprintf(
            'INSERT INTO %s (replica_id, deployment_id, replica_name, process_id, generation, '
            . 'publication_sha256, trust_hmac, materialized_at, last_seen_at, lease_until) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) %sdeployment_id = ?, replica_name = ?, '
            . 'process_id = ?, generation = ?, publication_sha256 = ?, trust_hmac = ?, '
            . 'materialized_at = ?, last_seen_at = ?, lease_until = ?',
            $this->tables->quoted('extension_runtime_materializations'),
            $conflict,
        );
    }

    /**
     * Report whether a driver error describes a peer writing this same row rather than a real fault.
     *
     * Deadlocks, lock-wait timeouts and PostgreSQL serialization failures already arrive as retryable
     * exceptions. MariaDB's snapshot-isolation conflict does not — the driver reports a bare
     * ER_CHECKREAD — so it is recognised by code. Nothing else is treated as concurrency.
     *
     * @param   DriverException  $exception  Error the lease write failed with.
     *
     * @return  bool  True only for a concurrency conflict on this row.
     *
     * @since   2.0.0
     */
    private function concurrentPeerWrite(DriverException $exception): bool
    {
        return $exception instanceof RetryableException || $exception->getCode() === self::RECORD_CHANGED;
    }
}
