<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\FailureClassification;
use Kumwe\CMS\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\CMS\BusinessIntegration\Application\InboxClaimResult;
use Kumwe\CMS\BusinessIntegration\Application\InboxDisposition;
use Kumwe\CMS\BusinessIntegration\Application\InboxLease;
use Kumwe\CMS\BusinessIntegration\Application\InboxStore;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * DBAL consumer inbox with durable deduplication, aggregate checkpoints and fenced delivery leases.
 *
 * @since  2.0.0
 */
final readonly class DoctrineInboxStore implements InboxStore
{
    /**
     * Bind the inbox to its durable connection and exact event catalog.
     *
     * @param   Connection             $database      Shared application connection.
     * @param   TableNames             $tables        Physical table-name compiler.
     * @param   TransactionManager     $transactions  Receipt and checkpoint transaction boundary.
     * @param   ClockInterface         $clock         Lease and retry clock.
     * @param   EventContractRegistry  $contracts     Exact trusted event catalog.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private EventContractRegistry $contracts,
    ) {
    }

    /** @inheritDoc */
    public function receive(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): InboxClaimResult {
        $this->contracts->assertEvent($event);
        $this->assertClaimInput($workerId, $runtimeGeneration, $leaseSeconds);
        if ($consumer->eventType() !== $event->eventType()) {
            throw new InvalidArgumentException('The consumer does not declare this event type.');
        }
        try {
            return $this->receiveTransaction($consumer, $event, $workerId, $runtimeGeneration, $leaseSeconds);
        } catch (UniqueConstraintViolationException) {
            return $this->receiveTransaction($consumer, $event, $workerId, $runtimeGeneration, $leaseSeconds);
        }
    }

    /** @inheritDoc */
    public function renew(InboxLease $lease, int $leaseSeconds): void
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('An inbox lease must last between 5 and 3600 seconds.');
        }
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET lease_expires_at = ?, updated_at = ? WHERE consumer_id = ? AND event_id = ? '
            . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
            . 'AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_inbox'),
        ), [
            $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))), $now,
            $lease->consumer->identifier(), $lease->event->eventId(), $lease->workerId,
            $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
            Types::STRING, Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /** @inheritDoc */
    public function complete(InboxLease $lease): void
    {
        $this->transactions->transactional(function () use ($lease): void {
            $now = $this->clock->now();
            if ($lease->consumer->aggregateOrdered()) {
                $affected = $this->database->executeStatement(sprintf(
                    'UPDATE %s SET aggregate_version = ?, event_id = ?, updated_at = ? WHERE consumer_id = ? '
                    . 'AND aggregate_type = ? AND aggregate_id = ? AND aggregate_version = ?',
                    $this->tables->quoted('integration_consumer_checkpoints'),
                ), [
                    $lease->event->aggregateVersion(), $lease->event->eventId(), $now,
                    $lease->consumer->identifier(), $lease->event->aggregateType(), $lease->event->aggregateId(),
                    $lease->event->aggregateVersion() - 1,
                ], [
                    Types::INTEGER, Types::GUID, Types::DATETIME_IMMUTABLE, Types::STRING,
                    Types::STRING, Types::STRING, Types::INTEGER,
                ]);
                if ((string) $affected !== '1') {
                    throw new RuntimeException('The consumer aggregate checkpoint moved during delivery.');
                }
            }
            $this->assertOne($this->database->executeStatement(sprintf(
                "UPDATE %s SET status = 'completed', lease_owner = NULL, lease_token = NULL, "
                . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
                . 'completed_at = ?, updated_at = ? WHERE consumer_id = ? AND event_id = ? '
                . "AND status = 'reserved' AND lease_owner = ? AND lease_token = ? "
                . 'AND runtime_generation = ? AND lease_expires_at > ?',
                $this->tables->quoted('integration_inbox'),
            ), [
                $now, $now, $lease->consumer->identifier(), $lease->event->eventId(),
                $lease->workerId, $lease->leaseToken, $lease->runtimeGeneration, $now,
            ], [
                Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
                Types::STRING, Types::GUID, Types::STRING, Types::DATETIME_IMMUTABLE,
            ]));
        });
    }

    /** @inheritDoc */
    public function fail(
        InboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $retry = $classification === FailureClassification::TRANSIENT
            && $retryAt !== null
            && $lease->attempts < $lease->consumer->maximumAttempts();
        $now = $this->clock->now();
        $this->assertOne($this->database->executeStatement(sprintf(
            'UPDATE %s SET status = ?, available_at = ?, lease_owner = NULL, lease_token = NULL, '
            . 'lease_acquired_at = NULL, lease_expires_at = NULL, runtime_generation = NULL, '
            . 'failure_classification = ?, exception_type = ?, error_message = ?, updated_at = ? '
            . "WHERE consumer_id = ? AND event_id = ? AND status = 'reserved' AND lease_owner = ? "
            . 'AND lease_token = ? AND runtime_generation = ? AND lease_expires_at > ?',
            $this->tables->quoted('integration_inbox'),
        ), [
            $retry ? 'pending' : 'poison', $retry && $retryAt > $now ? $retryAt : $now,
            $classification->value, $failure::class, substr($failure->getMessage(), 0, 4_000), $now,
            $lease->consumer->identifier(), $lease->event->eventId(), $lease->workerId,
            $lease->leaseToken, $lease->runtimeGeneration, $now,
        ], [
            Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::STRING,
            Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID, Types::STRING, Types::GUID,
            Types::STRING, Types::DATETIME_IMMUTABLE,
        ]));
    }

    /** @inheritDoc */
    public function recent(string $consumerId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('An inbox list limit must be between 1 and 1000.');
        }
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT consumer_id, event_id, event_type, schema_version, handler_version, site_identifier, '
            . 'organization_id, aggregate_type, '
            . 'aggregate_id, aggregate_version, status, attempts, maximum_attempts, available_at, lease_owner, '
            . 'runtime_generation, failure_classification, exception_type, error_message, first_received_at, '
            . 'completed_at, updated_at FROM %s WHERE consumer_id = ? '
            . 'ORDER BY first_received_at DESC, event_id DESC LIMIT ?',
            $this->tables->quoted('integration_inbox'),
        ), [$consumerId, $limit], [Types::STRING, Types::INTEGER]);
    }

    /** @since 2.0.0 */
    private function receiveTransaction(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $worker,
        string $generation,
        int $leaseSeconds,
    ): InboxClaimResult {
        return $this->transactions->transactional(function () use (
            $consumer,
            $event,
            $worker,
            $generation,
            $leaseSeconds,
        ): InboxClaimResult {
            $now = $this->clock->now();
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT * FROM %s WHERE consumer_id = ? AND event_id = ?%s',
                $this->tables->quoted('integration_inbox'),
                $this->lockClause(false),
            ), [$consumer->identifier(), $event->eventId()], [Types::STRING, Types::GUID]);
            if ($row !== false) {
                $terminal = $this->existingDisposition($row, $now);
                if ($terminal !== null) {
                    return new InboxClaimResult($terminal);
                }
            }

            if (!$consumer->acceptsVersion($event->schemaVersion())
                || !$event->sensitivity()->allowedBy($consumer->sensitivityCeiling())) {
                $this->storeUnavailable($consumer, $event, $now, $row !== false);
                return new InboxClaimResult(InboxDisposition::UNAVAILABLE);
            }

            if ($consumer->aggregateOrdered()) {
                $checkpoint = $this->checkpoint($consumer, $event, $now);
                if ($event->aggregateVersion() <= $checkpoint) {
                    $this->storeDuplicate($consumer, $event, $now, $row !== false);
                    return new InboxClaimResult(InboxDisposition::DUPLICATE);
                }
                if ($event->aggregateVersion() !== $checkpoint + 1) {
                    $this->storePending($consumer, $event, $now, $row !== false);
                    return new InboxClaimResult(InboxDisposition::REORDERED);
                }
            }

            $attempts = $row === false ? 1 : $this->integer($row, 'attempts') + 1;
            if ($attempts > $consumer->maximumAttempts()) {
                $this->storePoison($consumer, $event, $now, $row !== false);
                return new InboxClaimResult(InboxDisposition::POISON);
            }
            $token = Uuid::uuid7()->toString();
            if ($row === false) {
                $this->insertReceipt(
                    $consumer,
                    $event,
                    'reserved',
                    $now,
                    attempts: $attempts,
                    worker: $worker,
                    token: $token,
                    generation: $generation,
                    expiresAt: $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                );
            } else {
                $this->assertOne($this->database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'reserved', handler_version = ?, attempts = ?, available_at = ?, "
                    . 'lease_owner = ?, lease_token = ?, lease_acquired_at = ?, lease_expires_at = ?, '
                    . 'runtime_generation = ?, failure_classification = NULL, exception_type = NULL, '
                    . 'error_message = NULL, updated_at = ? WHERE consumer_id = ? AND event_id = ? '
                    . "AND status IN ('pending', 'reserved', 'unavailable')",
                    $this->tables->quoted('integration_inbox'),
                ), [
                    $consumer->handlerVersion(), $attempts, $now, $worker, $token, $now,
                    $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))), $generation, $now,
                    $consumer->identifier(), $event->eventId(),
                ], [
                    Types::STRING, Types::INTEGER, Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
                    Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING,
                    Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
                ]));
            }
            return new InboxClaimResult(InboxDisposition::CLAIMED, new InboxLease(
                $consumer,
                $event,
                $attempts,
                $worker,
                $token,
                $generation,
            ));
        });
    }

    /** @param array<string, mixed> $row @since 2.0.0 */
    private function existingDisposition(array $row, DateTimeImmutable $now): ?InboxDisposition
    {
        $status = $row['status'] ?? null;
        if ($status === 'completed') {
            return InboxDisposition::DUPLICATE;
        }
        if ($status === 'poison') {
            return InboxDisposition::POISON;
        }
        if ($status === 'reserved') {
            $expires = $row['lease_expires_at'] ?? null;
            if ($expires instanceof \DateTimeInterface) {
                $expires = DateTimeImmutable::createFromInterface($expires);
            } elseif (is_string($expires)) {
                $expires = new DateTimeImmutable($expires);
            }
            if ($expires instanceof DateTimeImmutable && $expires > $now) {
                return InboxDisposition::BUSY;
            }
        }
        return null;
    }

    /** @since 2.0.0 */
    private function checkpoint(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
    ): int {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT aggregate_version FROM %s WHERE consumer_id = ? AND aggregate_type = ? '
            . 'AND aggregate_id = ?%s',
            $this->tables->quoted('integration_consumer_checkpoints'),
            $this->lockClause(false),
        ), [$consumer->identifier(), $event->aggregateType(), $event->aggregateId()]);
        if ($row !== false) {
            return $this->integer($row, 'aggregate_version');
        }
        $baseline = 0;
        $this->database->insert($this->tables->raw('integration_consumer_checkpoints'), [
            'consumer_id' => $consumer->identifier(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $baseline,
            'event_id' => null,
            'updated_at' => $now,
        ], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        return $baseline;
    }

    /** @since 2.0.0 */
    private function storeUnavailable(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt($consumer, $event, 'unavailable', $now, error: 'Unsupported schema or sensitivity.');
            return;
        }
        $this->updateSimple($consumer, $event, 'unavailable', $now, 'Unsupported schema or sensitivity.');
    }

    /** @since 2.0.0 */
    private function storeDuplicate(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt($consumer, $event, 'completed', $now, completedAt: $now);
            return;
        }
        $this->updateSimple($consumer, $event, 'completed', $now, completedAt: $now);
    }

    /** @since 2.0.0 */
    private function storePending(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt($consumer, $event, 'pending', $now, error: 'Waiting for an earlier aggregate version.');
            return;
        }
        $this->updateSimple($consumer, $event, 'pending', $now, 'Waiting for an earlier aggregate version.');
    }

    /** @since 2.0.0 */
    private function storePoison(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt($consumer, $event, 'poison', $now, error: 'Consumer attempt budget exhausted.');
            return;
        }
        $this->updateSimple($consumer, $event, 'poison', $now, 'Consumer attempt budget exhausted.');
    }

    /** @since 2.0.0 */
    private function insertReceipt(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $status,
        DateTimeImmutable $now,
        int $attempts = 0,
        ?string $worker = null,
        ?string $token = null,
        ?string $generation = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $error = null,
        ?DateTimeImmutable $completedAt = null,
    ): void {
        $this->database->insert($this->tables->raw('integration_inbox'), [
            'consumer_id' => $consumer->identifier(),
            'event_id' => $event->eventId(),
            'event_type' => $event->eventType(),
            'schema_version' => $event->schemaVersion(),
            'handler_version' => $consumer->handlerVersion(),
            'site_identifier' => $event->siteIdentifier(),
            'organization_id' => $event->organizationId(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $event->aggregateVersion(),
            'envelope' => $event->toArray(),
            'status' => $status,
            'attempts' => $attempts,
            'maximum_attempts' => $consumer->maximumAttempts(),
            'available_at' => $now,
            'lease_owner' => $worker,
            'lease_token' => $token,
            'lease_acquired_at' => $worker === null ? null : $now,
            'lease_expires_at' => $expiresAt,
            'runtime_generation' => $generation,
            'failure_classification' => null,
            'exception_type' => null,
            'error_message' => $error,
            'first_received_at' => $now,
            'completed_at' => $completedAt,
            'updated_at' => $now,
        ], [
            'envelope' => Types::JSON,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'lease_acquired_at' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'first_received_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /** @since 2.0.0 */
    private function updateSimple(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $status,
        DateTimeImmutable $now,
        ?string $error = null,
        ?DateTimeImmutable $completedAt = null,
    ): void {
        $this->database->update($this->tables->raw('integration_inbox'), [
            'status' => $status,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'runtime_generation' => null,
            'error_message' => $error,
            'completed_at' => $completedAt,
            'updated_at' => $now,
        ], ['consumer_id' => $consumer->identifier(), 'event_id' => $event->eventId()], [
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /** @param array<string, mixed> $row @since 2.0.0 */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Inbox field "%s" is not an integer.', $key));
        }
        return (int) $value;
    }

    /** @since 2.0.0 */
    private function lockClause(bool $skipLocked): string
    {
        $platform = $this->database->getDatabasePlatform();
        if (!$platform instanceof PostgreSQLPlatform && !$platform instanceof AbstractMySQLPlatform) {
            return '';
        }
        return $skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
    }

    /** @since 2.0.0 */
    private function assertClaimInput(string $worker, string $generation, int $seconds): void
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $worker) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $generation) !== 1
            || $seconds < 5
            || $seconds > 3_600
        ) {
            throw new InvalidArgumentException('The inbox claim lease metadata is invalid.');
        }
    }

    /** @since 2.0.0 */
    private function assertOne(int|string $affected): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException('The worker no longer owns the active inbox lease.');
        }
    }
}
