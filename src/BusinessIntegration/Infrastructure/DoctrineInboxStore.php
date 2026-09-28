<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Infrastructure;

use DateInterval;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Integration\RecordedIntegrationEvent;
use JsonException;
use Kumwe\App\Infrastructure\Automation\DoctrineQueuePermits;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\Automation\FailureClassification;
use Kumwe\Automation\QueueRuntimePolicy;
use Kumwe\Automation\QueueRuntimePolicyCatalog;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\Integration\EventContractRegistry;
use Kumwe\Integration\InboxClaimResult;
use Kumwe\Integration\InboxDisposition;
use Kumwe\Integration\InboxLease;
use Kumwe\Integration\InboxStore;
use Kumwe\Integration\RecordedEventEnvelope;
use Kumwe\Integration\EventConsumerDefinition;
use Kumwe\Integration\IntegrationEvent;
use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\NullMetricRecorder;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * DBAL consumer inbox with durable deduplication, aggregate checkpoints and fenced delivery leases.
 *
 * Completed receipts remain final across handler upgrades. A poison receipt becomes eligible exactly once
 * a different signed handler version is active, with its attempt budget restarted; this gives an operator a
 * deterministic recovery path without allowing an unchanged failing binary to churn the same message.
 *
 * @since  2.0.0
 */
final readonly class DoctrineInboxStore implements InboxStore
{
    /**
     * Bind the inbox to its durable connection and exact event catalog.
     *
     * @param  Connection                  $database      Shared application connection.
     * @param  TableNames                  $tables        Physical table-name compiler.
     * @param  TransactionManager          $transactions  Receipt and checkpoint transaction boundary.
     * @param  ClockInterface              $clock         Lease and retry clock.
     * @param  EventContractRegistry       $contracts     Exact trusted event catalog.
     * @param  ?QueueRuntimePolicyCatalog  $policies      Active contributed queue limits; null preserves core-only
     *         inbox behavior for isolated instances.
     * @param  ?CorrelationContext         $correlation   Log-context holder whose upstream trace identifier a new
     *         receipt records; null records none.
     * @param  MetricRecorder              $metrics       Counts consumer settlements by outcome.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private EventContractRegistry $contracts,
        private ?QueueRuntimePolicyCatalog $policies = null,
        private ?CorrelationContext $correlation = null,
        private MetricRecorder $metrics = new NullMetricRecorder(),
    ) {
    }

    /**
     * Read the upstream W3C trace identifier recorded when a claimed receipt was first received.
     *
     * The receipt worker opens its log frame with this, so a consumer's lines join the trace of the
     * request whose event it is consuming even though the event crossed the outbox to get here.
     *
     * @param   InboxLease  $lease  Claimed receipt.
     *
     * @return  ?string  The recorded trace identifier, or null when the producer had none.
     *
     * @since   2.0.0
     */
    public function traceOf(InboxLease $lease): ?string
    {
        $trace = $this->database->fetchOne(sprintf(
            'SELECT trace_id FROM %s WHERE consumer_id = ? AND event_id = ?',
            $this->tables->quoted('integration_inbox'),
        ), [$lease->consumer->identifier(), $lease->event->eventId()], [Types::STRING, Types::GUID]);

        return is_string($trace) && $trace !== '' ? $trace : null;
    }

    /**
     * Claim or deduplicate an event for the declared consumer.
     *
     * @param   EventConsumerDefinition  $consumer           Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event              Versioned event being validated or processed.
     * @param   string                   $workerId           Stable identity of the claiming worker.
     * @param   string                   $runtimeGeneration  Trusted runtime generation that owns the lease.
     * @param   int                      $leaseSeconds       Number of seconds before the worker lease expires.
     *
     * @return  InboxClaimResult  Claim disposition and fenced lease, when processing was granted.
     *
     * @since   2.0.0
     */
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
        $consumer = $this->effectiveConsumer($consumer);
        $policy = $this->policies?->policy($consumer->queue());
        if ($policy !== null && $leaseSeconds > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }
        if ($policy !== null) {
            $this->permits()->synchronize($policy, $this->clock->now());
        }
        try {
            return $this->receiveTransaction(
                $consumer,
                $event,
                $workerId,
                $runtimeGeneration,
                $leaseSeconds,
                $policy,
            );
        } catch (UniqueConstraintViolationException) {
            return $this->receiveTransaction(
                $consumer,
                $event,
                $workerId,
                $runtimeGeneration,
                $leaseSeconds,
                $policy,
            );
        }
    }

    /**
     * Materialize the complete selected fanout atomically without claiming or executing any target.
     *
     * Retries only fill absent receipts: completed, pending, poison and live leases remain untouched.
     *
     * @param   list<EventConsumerDefinition>  $consumers  Active consumers and webhook receipt declarations.
     * @param   IntegrationEvent               $event      Sequenced authoritative event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function materialize(array $consumers, IntegrationEvent $event): void
    {
        $this->contracts->assertEvent($event);
        $this->transactions->transactional(function () use ($consumers, $event): void {
            $now = $this->clock->now();
            foreach ($consumers as $consumer) {
                if ($consumer->eventType() !== $event->eventType()) {
                    throw new InvalidArgumentException('A fanout receipt declares another event type.');
                }
                $this->insertReceipt($consumer, $event, 'pending', $now, ignoreDuplicate: true);
                $suffix = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                    ? ' ON DUPLICATE KEY UPDATE consumer_id = consumer_id'
                    : ' ON CONFLICT (consumer_id, scope_checksum) DO NOTHING';
                $this->database->executeStatement(sprintf(
                    'INSERT INTO %s (consumer_id, scope_checksum, site_identifier, organization_scope, '
                    . 'last_claimed_at, claim_count) VALUES (?, ?, ?, ?, ?, 0)%s',
                    $this->tables->quoted('integration_delivery_turns'),
                    $suffix,
                ), [$consumer->identifier(), $this->scopeChecksum($event), $event->siteIdentifier(),
                    $this->organizationScope($event), new DateTimeImmutable('1970-01-01T00:00:00+00:00')], [
                    Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
                ]);
            }
        });
    }

    /**
     * Claim a bounded fair batch from the active signed graph in one short transaction.
     *
     * Turns are durable per consumer, site and organization. Contended lanes and receipts are skipped;
     * no handler runs while their locks are held. Pending work binds to the current trusted handler,
     * while completed receipts remain final and poison restarts only after a signed handler upgrade.
     *
     * @param   list<EventConsumerDefinition>  $consumers   Exact active consumer/webhook graph.
     * @param   CanonicalEncoder               $encoder     Package event reconstruction dependency.
     * @param   string                         $worker      Replica and process identity.
     * @param   string                         $generation  Current trusted generation.
     * @param   int                            $seconds     Requested lease, narrowed to each queue policy.
     * @param   int                            $limit       Maximum leases, between one and 64.
     *
     * @return  list<InboxLease>  Independent durable leases ready for execution outside this transaction.
     *
     * @throws  InvalidArgumentException  When the batch bound or worker lease is invalid.
     *
     * @since   2.0.0
     */
    public function claimBatch(
        array $consumers,
        CanonicalEncoder $encoder,
        string $worker,
        string $generation,
        int $seconds,
        int $limit = 1,
    ): array {
        $this->assertClaimInput($worker, $generation, $seconds);
        if ($limit < 1 || $limit > 64) {
            throw new InvalidArgumentException('An inbox batch must contain between one and 64 leases.');
        }
        if ($consumers === []) {
            return [];
        }
        $catalog = [];
        $predicates = [];
        $parameters = [];
        $types = [];
        foreach ($consumers as $consumer) {
            $catalog[$consumer->identifier()][$consumer->eventType()] = $consumer;
            $this->prepareConsumer($consumer);
            $predicates[] = '(i.consumer_id = ? AND i.event_type = ? AND ('
                . "(i.status = 'pending' AND i.available_at <= ?) OR "
                . "(i.status = 'reserved' AND i.lease_expires_at <= ?) OR "
                . "(i.status = 'unavailable' AND i.available_at <= ?) OR "
                . "(i.status IN ('poison', 'unavailable') AND i.handler_version <> ?)))";
            array_push(
                $parameters,
                $consumer->identifier(),
                $consumer->eventType(),
                $this->clock->now(),
                $this->clock->now(),
                $this->clock->now(),
                $consumer->handlerVersion()
            );
            array_push(
                $types,
                Types::STRING,
                Types::STRING,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
                Types::DATETIME_IMMUTABLE,
                Types::STRING
            );
            $policy = $this->policies?->policy($consumer->queue());
            if ($policy !== null) {
                $this->permits()->synchronize($policy, $this->clock->now());
            }
        }
        $eligible = '(' . implode(' OR ', $predicates) . ')';
        return $this->transactions->transactional(function () use (
            $catalog,
            $encoder,
            $worker,
            $generation,
            $seconds,
            $limit,
            $eligible,
            $parameters,
            $types,
        ): array {
            $leases = [];
            // Read bounded candidates first; locking an ordered filesort can lock every tenant.
            $candidates = $this->database->fetchAllAssociative(
                sprintf(
                    'SELECT t.* FROM %s t WHERE NOT EXISTS (SELECT 1 FROM %s h '
                    . 'WHERE h.consumer_id = t.consumer_id AND (h.lease_expires_at > ? OR h.blocked_until > ?)) '
                    . 'AND EXISTS (SELECT 1 FROM %s i '
                    . 'WHERE i.consumer_id = t.consumer_id AND i.site_identifier = t.site_identifier '
                    . "AND COALESCE(i.organization_id, '') = t.organization_scope AND %s) "
                    . 'ORDER BY t.last_claimed_at, t.claim_count, t.consumer_id, t.scope_checksum LIMIT 64',
                    $this->tables->quoted('integration_delivery_turns'),
                    $this->tables->quoted('integration_delivery_health'),
                    $this->tables->quoted('integration_inbox'),
                    $eligible,
                ),
                [$this->clock->now(), $this->clock->now(), ...$parameters],
                [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, ...$types]
            );
            foreach ($candidates as $candidate) {
                if (count($leases) >= $limit) {
                    break;
                }
                $turn = $this->database->fetchAssociative(sprintf(
                    'SELECT * FROM %s WHERE consumer_id = ? AND scope_checksum = ?%s',
                    $this->tables->quoted('integration_delivery_turns'),
                    $this->lockClause(true),
                ), [$candidate['consumer_id'], $candidate['scope_checksum']]);
                if ($turn === false) {
                    continue;
                }
                $now = $this->clock->now();
                $this->database->executeStatement(sprintf(
                    'UPDATE %s SET last_claimed_at = ?, claim_count = claim_count + 1 '
                    . 'WHERE consumer_id = ? AND scope_checksum = ?',
                    $this->tables->quoted('integration_delivery_turns'),
                ), [$now, $turn['consumer_id'], $turn['scope_checksum']], [
                    Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING,
                ]);
                // Different tenant turns for one consumer still share one durable execution permit.
                // Recheck with a lock: the turn query can have observed another replica's old snapshot.
                $consumerPermit = $this->database->fetchOne(sprintf(
                    'SELECT consumer_id FROM %s WHERE consumer_id = ? '
                    . 'AND (lease_expires_at IS NULL OR lease_expires_at <= ?) '
                    . 'AND (blocked_until IS NULL OR blocked_until <= ?)%s',
                    $this->tables->quoted('integration_delivery_health'),
                    $this->lockClause(true),
                ), [$turn['consumer_id'], $now, $now], [
                    Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
                ]);
                if ($consumerPermit === false) {
                    continue;
                }
                $row = $this->database->fetchAssociative(
                    sprintf(
                        'SELECT i.* FROM %s i WHERE i.consumer_id = ? AND i.site_identifier = ? '
                        . "AND COALESCE(i.organization_id, '') = ? AND %s "
                        . 'ORDER BY i.available_at, i.aggregate_version, i.first_received_at, i.event_id LIMIT 1%s',
                        $this->tables->quoted('integration_inbox'),
                        $eligible,
                        $this->lockClause(true),
                    ),
                    [$turn['consumer_id'], $turn['site_identifier'], $turn['organization_scope'], ...$parameters],
                    [Types::STRING, Types::STRING, Types::STRING, ...$types]
                );
                if ($row === false) {
                    continue;
                }
                $definition = $catalog[$this->requiredString($row, 'consumer_id')]
                    [$this->requiredString($row, 'event_type')];
                try {
                    $document = is_string($row['envelope'])
                        ? json_decode($row['envelope'], true, 64, JSON_THROW_ON_ERROR) : $row['envelope'];
                    if (!is_array($document) || array_is_list($document)) {
                        throw new InvalidArgumentException('An inbox envelope must be an object.');
                    }
                    /** @var array<string, mixed> $document */
                    $event = RecordedIntegrationEvent::fromArray($encoder, $document);
                    $this->contracts->assertEvent($event);
                    if (
                        $event->eventId() !== $row['event_id'] || $event->siteIdentifier() !== $row['site_identifier']
                        || $event->organizationId() !== $row['organization_id']
                        || $event->eventType() !== $row['event_type']
                    ) {
                        throw new InvalidArgumentException('An inbox envelope disagrees with its durable scope.');
                    }
                } catch (InvalidArgumentException | JsonException $failure) {
                    $this->database->update($this->tables->raw('integration_inbox'), [
                        'status' => 'poison', 'handler_version' => $definition->handlerVersion(),
                        'error_message' => substr($failure->getMessage(), 0, 4000), 'updated_at' => $now,
                    ], ['consumer_id' => $row['consumer_id'], 'event_id' => $row['event_id']], [
                        'updated_at' => Types::DATETIME_IMMUTABLE,
                    ]);
                    continue;
                }
                $policy = $this->policies?->policy($definition->queue());
                $result = $this->receiveTransaction(
                    $this->effectiveConsumer($definition),
                    $event,
                    $worker,
                    $generation,
                    min($seconds, $policy->leaseSeconds ?? $seconds),
                    $policy,
                );
                if ($result->lease !== null) {
                    // Copy the authoritative work expiry exactly. Recomputing it from the earlier
                    // scan timestamp could let another tenant take the consumer permit prematurely.
                    $this->database->executeStatement(sprintf(
                        'UPDATE %s SET lease_token = ?, lease_expires_at = '
                        . '(SELECT lease_expires_at FROM %s WHERE consumer_id = ? AND event_id = ?) '
                        . 'WHERE consumer_id = ?',
                        $this->tables->quoted('integration_delivery_health'),
                        $this->tables->quoted('integration_inbox'),
                    ), [$result->lease->leaseToken, $definition->identifier(), $event->eventId(),
                        $definition->identifier()]);
                    $leases[] = $result->lease;
                } elseif ($result->disposition === InboxDisposition::UNAVAILABLE) {
                    // A newly signed schema/sensitivity declaration can restore compatibility even if
                    // its handler binary version is unchanged. Recheck boundedly without spending attempts.
                    $this->database->update($this->tables->raw('integration_inbox'), [
                        'available_at' => $now->modify('+60 seconds'),
                    ], ['consumer_id' => $row['consumer_id'], 'event_id' => $row['event_id']], [
                        'available_at' => Types::DATETIME_IMMUTABLE,
                    ]);
                } elseif (in_array($result->disposition, [InboxDisposition::BUSY, InboxDisposition::REORDERED], true)) {
                    $this->database->executeStatement(sprintf(
                        'UPDATE %s SET available_at = ? WHERE consumer_id = ? AND event_id = ? '
                        . "AND status = 'pending' AND available_at <= ?",
                        $this->tables->quoted('integration_inbox'),
                    ), [$now->modify('+1 second'), $row['consumer_id'], $row['event_id'], $now], [
                        Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID, Types::DATETIME_IMMUTABLE,
                    ]);
                }
            }
            return $leases;
        });
    }

    /**
     * Renew the supplied durable-processing lease.
     *
     * @param   InboxLease  $lease                  Fenced lease proving ownership of the durable item.
     * @param   int         $leaseSeconds           Number of seconds before the worker lease expires.
     * @param   bool        $requireConsumerPermit  Whether this is an independently pooled receipt.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(InboxLease $lease, int $leaseSeconds, bool $requireConsumerPermit = false): void
    {
        if ($leaseSeconds < 5 || $leaseSeconds > 3_600) {
            throw new InvalidArgumentException('An inbox lease must last between 5 and 3600 seconds.');
        }
        $policy = $this->policies?->policy($lease->consumer->queue());
        if ($policy !== null && $leaseSeconds > $policy->leaseSeconds) {
            throw new InvalidArgumentException('A contributed queue lease cannot exceed its signed policy.');
        }
        $this->transactions->transactional(function () use (
            $lease,
            $leaseSeconds,
            $policy,
            $requireConsumerPermit,
        ): void {
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
            if ($policy !== null) {
                $this->permits()->renew(
                    $policy,
                    $lease->leaseToken,
                    $now,
                    $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                );
            }
            $renewed = $this->database->executeStatement(sprintf(
                'UPDATE %s SET lease_expires_at = ? WHERE consumer_id = ? AND lease_token = ? '
                . 'AND lease_expires_at > ?',
                $this->tables->quoted('integration_delivery_health'),
            ), [$now->modify(sprintf('+%d seconds', $leaseSeconds)), $lease->consumer->identifier(),
                $lease->leaseToken, $now], [
                Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID, Types::DATETIME_IMMUTABLE,
            ]);
            if ($requireConsumerPermit && (string) $renewed !== '1') {
                throw new RuntimeException('The worker no longer owns its consumer execution permit.');
            }
        });
    }

    /**
     * Mark the supplied durable-processing lease complete.
     *
     * @param   InboxLease  $lease  Fenced lease proving ownership of the durable item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(InboxLease $lease): void
    {
        $this->transactions->transactional(function () use ($lease): void {
            $now = $this->clock->now();
            if ($lease->consumer->aggregateOrdered()) {
                $affected = $this->database->executeStatement(sprintf(
                    'UPDATE %s SET aggregate_version = ?, event_id = ?, updated_at = ? WHERE consumer_id = ? '
                    . 'AND scope_checksum = ? AND site_identifier = ? AND organization_scope = ? '
                    . 'AND aggregate_type = ? AND aggregate_id = ? AND aggregate_version = ?',
                    $this->tables->quoted('integration_consumer_checkpoints'),
                ), [
                    $lease->event->aggregateVersion(), $lease->event->eventId(), $now,
                    $lease->consumer->identifier(), $this->scopeChecksum($lease->event),
                    $lease->event->siteIdentifier(), $this->organizationScope($lease->event),
                    $lease->event->aggregateType(), $lease->event->aggregateId(),
                    $lease->event->aggregateVersion() - 1,
                ], [
                    Types::INTEGER, Types::GUID, Types::DATETIME_IMMUTABLE, Types::STRING,
                    Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::INTEGER,
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
            if ($this->policies?->policy($lease->consumer->queue()) !== null) {
                $this->permits()->release($lease->consumer->queue(), $lease->leaseToken);
            }
            $this->database->update($this->tables->raw('integration_delivery_health'), [
                'lease_token' => null, 'lease_expires_at' => null, 'blocked_until' => null, 'failure_streak' => 0,
            ], ['consumer_id' => $lease->consumer->identifier(), 'lease_token' => $lease->leaseToken]);
        });
        $this->metrics->increment(MetricCatalog::CONSUMER_SETTLEMENTS, ['outcome' => 'completed']);
    }

    /**
     * Record a failed durable delivery and its retry decision.
     *
     * @param   InboxLease             $lease           Fenced lease proving ownership of the durable item.
     * @param   FailureClassification  $classification  Failure class controlling retry or quarantine behavior.
     * @param   Throwable              $failure         Failure whose retry classification is being recorded.
     * @param   ?DateTimeImmutable     $retryAt         Next eligible attempt timestamp, or null for quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fail(
        InboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void {
        $retry = $classification === FailureClassification::TRANSIENT
            && $retryAt !== null
            && $lease->attempts < $lease->consumer->maximumAttempts();
        $this->transactions->transactional(function () use ($lease, $classification, $failure, $retryAt, $retry): void {
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
            if ($this->policies?->policy($lease->consumer->queue()) !== null) {
                $this->permits()->release($lease->consumer->queue(), $lease->leaseToken);
            }
            $cooldown = $classification === FailureClassification::TRANSIENT
                ? $now->modify(sprintf('+%d seconds', max(1, min(
                    300,
                    ($retryAt?->getTimestamp() ?? $now->getTimestamp()) - $now->getTimestamp()
                )))) : null;
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET lease_token = NULL, lease_expires_at = NULL, blocked_until = ?, '
                . 'failure_streak = failure_streak + 1 WHERE consumer_id = ? AND lease_token = ?',
                $this->tables->quoted('integration_delivery_health'),
            ), [$cooldown, $lease->consumer->identifier(), $lease->leaseToken], [
                Types::DATETIME_IMMUTABLE, Types::STRING, Types::GUID,
            ]);
        });
        $this->metrics->increment(MetricCatalog::CONSUMER_SETTLEMENTS, ['outcome' => $retry ? 'retried' : 'dead']);
    }

    /**
     * Return the most recent operator-visible records.
     *
     * @param   string  $consumerId  Stable consumer identifier used to scope receipt history.
     * @param   int     $limit       Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible rows in deterministic order.
     *
     * @since   2.0.0
     */
    public function recent(string $consumerId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new InvalidArgumentException('An inbox list limit must be between 1 and 1000.');
        }
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT consumer_id, event_id, queue, event_type, schema_version, handler_version, site_identifier, '
            . 'organization_id, aggregate_type, '
            . 'aggregate_id, aggregate_version, status, attempts, maximum_attempts, available_at, lease_owner, '
            . 'runtime_generation, failure_classification, exception_type, error_message, first_received_at, '
            . 'completed_at, evidence_compacted_at, updated_at FROM %s WHERE consumer_id = ? '
            . 'ORDER BY first_received_at DESC, event_id DESC LIMIT ?',
            $this->tables->quoted('integration_inbox'),
        ), [$consumerId, $limit], [Types::STRING, Types::INTEGER]);
    }

    /**
     * Resolve one consumer receipt atomically under ordering and lease constraints.
     *
     * @param   EventConsumerDefinition  $consumer      Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event         Versioned event being validated or processed.
     * @param   string                   $worker        Stable identity of the claiming worker.
     * @param   string                   $generation    Trusted runtime generation that owns the lease.
     * @param   int                      $leaseSeconds  Number of seconds before the worker lease expires.
     * @param   ?QueueRuntimePolicy      $policy        Active contributed queue policy, when declared.
     *
     * @return  InboxClaimResult  Claim disposition and fenced lease resolved atomically.
     *
     * @since   2.0.0
     */
    private function receiveTransaction(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $worker,
        string $generation,
        int $leaseSeconds,
        ?QueueRuntimePolicy $policy,
    ): InboxClaimResult {
        return $this->transactions->transactional(function () use (
            $consumer,
            $event,
            $worker,
            $generation,
            $leaseSeconds,
            $policy,
        ): InboxClaimResult {
            $now = $this->clock->now();
            $row = $this->database->fetchAssociative(sprintf(
                'SELECT * FROM %s WHERE consumer_id = ? AND event_id = ?%s',
                $this->tables->quoted('integration_inbox'),
                $this->lockClause(false),
            ), [$consumer->identifier(), $event->eventId()], [Types::STRING, Types::GUID]);
            $handlerUpgraded = $row !== false
                && ($row['handler_version'] ?? null) !== $consumer->handlerVersion();
            if ($row !== false) {
                $terminal = $this->existingDisposition($row, $now, $handlerUpgraded);
                if ($terminal !== null) {
                    return new InboxClaimResult($terminal);
                }
            }

            if (
                !$consumer->acceptsVersion($event->schemaVersion())
                || !$event->sensitivity()->allowedBy($consumer->sensitivityCeiling())
            ) {
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

            $attempts = $row === false || $handlerUpgraded ? 1 : $this->integer($row, 'attempts') + 1;
            if ($attempts > $consumer->maximumAttempts()) {
                $this->storePoison($consumer, $event, $now, $row !== false);
                return new InboxClaimResult(InboxDisposition::POISON);
            }
            $token = Uuid::uuid7()->toString();
            if (
                $policy !== null && !$this->permits()->acquire(
                    $policy,
                    $now,
                    'inbox',
                    $event->eventId(),
                    $consumer->identifier(),
                    $token,
                    $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))),
                )
            ) {
                return new InboxClaimResult(InboxDisposition::BUSY);
            }
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
                    "UPDATE %s SET status = 'reserved', queue = ?, handler_version = ?, envelope = ?, attempts = ?, "
                    . 'maximum_attempts = ?, available_at = ?, lease_owner = ?, lease_token = ?, '
                    . 'lease_acquired_at = ?, lease_expires_at = ?, '
                    . 'runtime_generation = ?, failure_classification = NULL, exception_type = NULL, '
                    . 'error_message = NULL, evidence_compacted_at = NULL, updated_at = ? '
                    . 'WHERE consumer_id = ? AND event_id = ? '
                    . "AND status IN ('pending', 'poison', 'reserved', 'unavailable')",
                    $this->tables->quoted('integration_inbox'),
                ), [
                    $consumer->queue(), $consumer->handlerVersion(), RecordedEventEnvelope::document($event), $attempts,
                    $consumer->maximumAttempts(),
                    $now, $worker, $token, $now,
                    $now->add(new DateInterval(sprintf('PT%dS', $leaseSeconds))), $generation, $now,
                    $consumer->identifier(), $event->eventId(),
                ], [
                    Types::STRING, Types::STRING, Types::JSON, Types::INTEGER, Types::INTEGER,
                    Types::DATETIME_IMMUTABLE,
                    Types::STRING, Types::GUID, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING,
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

    /**
     * Resolve the disposition of an existing receipt at the current timestamp.
     *
     * @param   array<string, mixed>  $row              Durable database row being reconstituted.
     * @param   DateTimeImmutable     $now              Authoritative timestamp for the state transition.
     * @param   bool                  $handlerUpgraded  Whether a new signed handler revision supersedes the receipt.
     *
     * @return  ?InboxDisposition  Current receipt disposition, or null when a new row is required.
     *
     * @since   2.0.0
     */
    private function existingDisposition(
        array $row,
        DateTimeImmutable $now,
        bool $handlerUpgraded,
    ): ?InboxDisposition {
        $status = $row['status'] ?? null;
        if ($status === 'completed') {
            return InboxDisposition::DUPLICATE;
        }
        if ($status === 'poison' && !$handlerUpgraded) {
            return InboxDisposition::POISON;
        }
        if ($status === 'pending') {
            $available = $row['available_at'] ?? null;
            if ($available instanceof \DateTimeInterface) {
                $available = DateTimeImmutable::createFromInterface($available);
            } elseif (is_string($available)) {
                $available = new DateTimeImmutable($available);
            }
            if (!$available instanceof DateTimeImmutable) {
                throw new RuntimeException('A pending inbox receipt has an invalid availability time.');
            }
            if ($available > $now) {
                return InboxDisposition::BUSY;
            }
        }
        if ($status === 'reserved') {
            $expires = $row['lease_expires_at'] ?? null;
            if ($expires instanceof \DateTimeInterface) {
                $expires = DateTimeImmutable::createFromInterface($expires);
            } elseif (is_string($expires)) {
                $expires = new DateTimeImmutable($expires);
            }
            if (!$expires instanceof DateTimeImmutable) {
                throw new RuntimeException('A reserved inbox receipt has an invalid lease expiration time.');
            }
            if ($expires > $now) {
                return InboxDisposition::BUSY;
            }
        }
        return null;
    }

    /**
     * Read the completed aggregate-version checkpoint for an ordered consumer.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     *
     * @return  int  Highest aggregate version completed for this ordered consumer.
     *
     * @since   2.0.0
     */
    private function checkpoint(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
    ): int {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT aggregate_version FROM %s WHERE consumer_id = ? AND scope_checksum = ? '
            . 'AND site_identifier = ? AND organization_scope = ? AND aggregate_type = ? AND aggregate_id = ?%s',
            $this->tables->quoted('integration_consumer_checkpoints'),
            $this->lockClause(false),
        ), [
            $consumer->identifier(), $this->scopeChecksum($event), $event->siteIdentifier(),
            $this->organizationScope($event), $event->aggregateType(), $event->aggregateId(),
        ], [Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING, Types::STRING]);
        if ($row !== false) {
            return $this->integer($row, 'aggregate_version');
        }
        $baseline = 0;
        $this->database->insert($this->tables->raw('integration_consumer_checkpoints'), [
            'consumer_id' => $consumer->identifier(),
            'scope_checksum' => $this->scopeChecksum($event),
            'site_identifier' => $event->siteIdentifier(),
            'organization_scope' => $this->organizationScope($event),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $baseline,
            'event_id' => null,
            'updated_at' => $now,
        ], ['updated_at' => Types::DATETIME_IMMUTABLE]);
        return $baseline;
    }

    /**
     * Normalize the nullable event organization into an unambiguous durable checkpoint scope.
     *
     * Empty strings are forbidden by the event envelope, so they safely represent site-wide events.
     *
     * @param   IntegrationEvent  $event  Event whose tenant scope is being checkpointed.
     *
     * @return  string  Organization identity, or the site-wide empty sentinel.
     *
     * @since   2.0.0
     */
    private function organizationScope(IntegrationEvent $event): string
    {
        return $event->organizationId() ?? '';
    }

    /**
     * Compile a bounded tenant-scope key suitable for a portable composite primary key.
     *
     * The NUL separator cannot occur in a validated event identity. Explicit scope columns remain in every
     * predicate so even a theoretical digest collision fails closed instead of crossing a tenant boundary.
     *
     * @param   IntegrationEvent  $event  Event whose tenant scope is being checkpointed.
     *
     * @return  string  Lowercase SHA-256 tenant-scope checksum.
     *
     * @since   2.0.0
     */
    private function scopeChecksum(IntegrationEvent $event): string
    {
        return hash('sha256', $event->siteIdentifier() . "\0" . $this->organizationScope($event));
    }

    /**
     * Persist an unavailable receipt without granting a processing lease.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Persist or refresh a receipt that represents an already completed delivery.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Persist a pending receipt while an earlier aggregate version remains incomplete.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function storePending(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        DateTimeImmutable $now,
        bool $exists,
    ): void {
        if (!$exists) {
            $this->insertReceipt(
                $consumer,
                $event,
                'pending',
                $now,
                error: 'Waiting for an earlier aggregate version.',
            );
            return;
        }
        $this->updateSimple($consumer, $event, 'pending', $now, 'Waiting for an earlier aggregate version.');
    }

    /**
     * Persist a quarantined receipt that cannot be delivered safely.
     *
     * @param   EventConsumerDefinition  $consumer  Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event     Versioned event being validated or processed.
     * @param   DateTimeImmutable        $now       Authoritative timestamp for the state transition.
     * @param   bool                     $exists    Existing receipt row, when one has already been recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Insert a complete inbox receipt with scope, lease, and failure metadata.
     *
     * @param   EventConsumerDefinition  $consumer         Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event            Versioned event being validated or processed.
     * @param   string                   $status           Durable state to record for the receipt.
     * @param   DateTimeImmutable        $now              Authoritative timestamp for the state transition.
     * @param   int                      $attempts         Attempt count to record for this receipt.
     * @param   ?string                  $worker           Stable identity of the claiming worker.
     * @param   ?string                  $token            Opaque lease token used to fence concurrent workers.
     * @param   ?string                  $generation       Trusted runtime generation that owns the lease.
     * @param   ?DateTimeImmutable       $expiresAt        Lease expiration timestamp, when a lease is granted.
     * @param   ?string                  $error            Sanitized failure detail retained for operators.
     * @param   ?DateTimeImmutable       $completedAt      Timestamp at which processing completed, when applicable.
     * @param   bool                     $ignoreDuplicate  Preserve every existing receipt during materialization.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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
        bool $ignoreDuplicate = false,
    ): void {
        $values = [
            'consumer_id' => $consumer->identifier(),
            'event_id' => $event->eventId(),
            'queue' => $consumer->queue(),
            'event_type' => $event->eventType(),
            'schema_version' => $event->schemaVersion(),
            'handler_version' => $consumer->handlerVersion(),
            'site_identifier' => $event->siteIdentifier(),
            'organization_id' => $event->organizationId(),
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => $event->aggregateId(),
            'aggregate_version' => $event->aggregateVersion(),
            'envelope' => RecordedEventEnvelope::document($event),
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
            'evidence_compacted_at' => null,
            'updated_at' => $now,
        ];
        $traceId = $this->correlation?->traceId();
        if ($traceId !== null) {
            // Omitted rather than null, so a receipt written without a trace needs no trace column.
            $values['trace_id'] = $traceId;
        }
        $types = [
            'envelope' => Types::JSON,
            'available_at' => Types::DATETIME_IMMUTABLE,
            'lease_acquired_at' => Types::DATETIME_IMMUTABLE,
            'lease_expires_at' => Types::DATETIME_IMMUTABLE,
            'first_received_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        if (!$ignoreDuplicate) {
            $this->database->insert($this->tables->raw('integration_inbox'), $values, $types);
            return;
        }
        $suffix = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE consumer_id = consumer_id'
            : ' ON CONFLICT (consumer_id, event_id) DO NOTHING';
        $this->database->executeStatement(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)%s',
            $this->tables->quoted('integration_inbox'),
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?')),
            $suffix,
        ), array_values($values), array_map(
            static fn (string $key): string => $types[$key] ?? Types::STRING,
            array_keys($values)
        ));
    }

    /**
     * Update a receipt status without granting or renewing a processing lease.
     *
     * @param   EventConsumerDefinition  $consumer     Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event        Versioned event being validated or processed.
     * @param   string                   $status       Durable state to record for the receipt.
     * @param   DateTimeImmutable        $now          Authoritative timestamp for the state transition.
     * @param   ?string                  $error        Sanitized failure detail retained for operators.
     * @param   ?DateTimeImmutable       $completedAt  Timestamp at which processing completed, when applicable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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
            'queue' => $consumer->queue(),
            'handler_version' => $consumer->handlerVersion(),
            'maximum_attempts' => $consumer->maximumAttempts(),
            'lease_owner' => null,
            'lease_token' => null,
            'lease_acquired_at' => null,
            'lease_expires_at' => null,
            'runtime_generation' => null,
            'failure_classification' => null,
            'exception_type' => null,
            'error_message' => $error,
            'completed_at' => $completedAt,
            'evidence_compacted_at' => null,
            'envelope' => RecordedEventEnvelope::document($event),
            'updated_at' => $now,
        ], ['consumer_id' => $consumer->identifier(), 'event_id' => $event->eventId()], [
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'envelope' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Narrow a signed consumer attempt budget through its active queue declaration.
     *
     * The executable handler is still reconciled against the unchanged signed definition before the
     * dispatcher reaches this store. This derived value exists only for the durable receipt and retry
     * lease, where the queue ceiling must be no wider than the consumer's own ceiling.
     *
     * @param   EventConsumerDefinition  $consumer  Signed active consumer or outbound-adapter receipt.
     *
     * @return  EventConsumerDefinition  Equivalent receipt contract with the effective attempt ceiling.
     *
     * @since   2.0.0
     */
    private function effectiveConsumer(EventConsumerDefinition $consumer): EventConsumerDefinition
    {
        $policy = $this->policies?->policy($consumer->queue());
        $maximum = $policy === null
            ? $consumer->maximumAttempts()
            : min($consumer->maximumAttempts(), $policy->maximumAttempts);
        if ($maximum === $consumer->maximumAttempts()) {
            return $consumer;
        }

        return new EventConsumerDefinition(
            $consumer->identifier(),
            $consumer->eventType(),
            $consumer->schemaVersions(),
            $consumer->handlerVersion(),
            $consumer->queue(),
            $consumer->aggregateOrdered(),
            $consumer->idempotency(),
            $maximum,
            $consumer->sensitivityCeiling(),
        );
    }

    /**
     * Initialize one cross-replica consumer gate and reset circuit backoff on a signed handler upgrade.
     *
     * One active receipt per consumer bounds a hung target independently of its queue. The live lease is
     * retained during upgrades so old and new handlers cannot overlap; only the backoff is reset.
     *
     * @param   EventConsumerDefinition  $consumer  Current signed consumer or webhook receipt definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function prepareConsumer(EventConsumerDefinition $consumer): void
    {
        $version = $this->database->fetchOne(sprintf(
            'SELECT handler_version FROM %s WHERE consumer_id = ?',
            $this->tables->quoted('integration_delivery_health'),
        ), [$consumer->identifier()]);
        if ($version === $consumer->handlerVersion()) {
            return;
        }
        if ($version === false) {
            $suffix = $this->database->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? ' ON DUPLICATE KEY UPDATE consumer_id = consumer_id'
                : ' ON CONFLICT (consumer_id) DO NOTHING';
            $this->database->executeStatement(sprintf(
                'INSERT INTO %s (consumer_id, handler_version, failure_streak) VALUES (?, ?, 0)%s',
                $this->tables->quoted('integration_delivery_health'),
                $suffix,
            ), [$consumer->identifier(), $consumer->handlerVersion()]);
        }
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET handler_version = ?, blocked_until = NULL, failure_streak = 0 '
            . 'WHERE consumer_id = ? AND handler_version <> ?',
            $this->tables->quoted('integration_delivery_health'),
        ), [$consumer->handlerVersion(), $consumer->identifier(), $consumer->handlerVersion()]);
    }

    /**
     * Compose capacity arbitration on the inbox transaction connection.
     *
     * @return  DoctrineQueuePermits  The same durable permit namespace used by job workers.
     *
     * @since   2.0.0
     */
    private function permits(): DoctrineQueuePermits
    {
        return new DoctrineQueuePermits($this->database, $this->tables);
    }

    /**
     * Require a durable identity before using it as a catalog key.
     *
     * @param   array<string, mixed>  $row  Durable receipt.
     * @param   string                $key  Identity column.
     *
     * @return  string  Nonempty identity.
     *
     * @throws  RuntimeException  When durable identity is malformed.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('An inbox identity is malformed.');
        }
        return $value;
    }

    /**
     * Read and validate an integer value.
     *
     * @param   array<string, mixed>  $row  Durable database row being reconstituted.
     * @param   string                $key  Array or row key whose value is being read.
     *
     * @return  int  Integer stored under the requested key.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('Inbox field "%s" is not an integer.', $key));
        }
        return (int) $value;
    }

    /**
     * Return the database-specific row-locking clause.
     *
     * @param   bool  $skipLocked  Whether rows held by another worker should be skipped.
     *
     * @return  string  Driver-specific SQL suffix used to fence concurrent claims.
     *
     * @since   2.0.0
     */
    private function lockClause(bool $skipLocked): string
    {
        $platform = $this->database->getDatabasePlatform();
        if (!$platform instanceof PostgreSQLPlatform && !$platform instanceof AbstractMySQLPlatform) {
            return '';
        }
        return $skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
    }

    /**
     * Validate worker identity, runtime generation, and lease bounds.
     *
     * @param   string  $worker      Stable identity of the claiming worker.
     * @param   string  $generation  Trusted runtime generation that owns the lease.
     * @param   int     $seconds     Requested lease duration in seconds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Require exactly one row to have been changed by a fenced update.
     *
     * @param   int|string  $affected  Number of rows changed by the fenced statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertOne(int|string $affected): void
    {
        if ((string) $affected !== '1') {
            throw new RuntimeException('The worker no longer owns the active inbox lease.');
        }
    }
}
