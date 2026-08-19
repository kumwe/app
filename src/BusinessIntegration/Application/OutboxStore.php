<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;
use Throwable;

/**
 * Transactional event store and fenced dispatch queue.
 *
 * @since  2.0.0
 */
interface OutboxStore
{
    /**
     * Append the supplied item to durable storage.
     *
     * @param   IntegrationEvent    $event            Versioned event being validated or processed.
     * @param   int                 $maximumAttempts  Delivery-attempt ceiling before quarantine.
     * @param   ?DateTimeImmutable  $availableAt      Earliest timestamp at which the event may be claimed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function append(
        IntegrationEvent $event,
        int $maximumAttempts = 10,
        ?DateTimeImmutable $availableAt = null,
    ): void;

    /**
     * Claim the next eligible item for the named worker.
     *
     * @param   string  $workerId           Stable identity of the claiming worker.
     * @param   string  $runtimeGeneration  Trusted runtime generation that owns the lease.
     * @param   int     $leaseSeconds       Number of seconds before the worker lease expires.
     *
     * @return  ?OutboxLease
     *
     * @since   2.0.0
     */
    public function claim(
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): ?OutboxLease;

    /**
     * Renew the supplied durable-processing lease.
     *
     * @param   OutboxLease  $lease         Fenced lease proving ownership of the durable item.
     * @param   int          $leaseSeconds  Number of seconds before the worker lease expires.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(OutboxLease $lease, int $leaseSeconds): void;

    /**
     * Mark the supplied durable-processing lease complete.
     *
     * @param   OutboxLease  $lease  Fenced lease proving ownership of the durable item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(OutboxLease $lease): void;

    /**
     * Release a fenced lease after normal downstream backpressure without consuming an attempt.
     *
     * @param   OutboxLease  $lease         Fenced lease proving ownership of the durable item.
     * @param   int          $delaySeconds  Bounded delay before another fan-out attempt may claim it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function defer(OutboxLease $lease, int $delaySeconds = 5): void;

    /**
     * Record a failed durable delivery and its retry decision.
     *
     * @param   OutboxLease            $lease           Fenced lease proving ownership of the durable item.
     * @param   FailureClassification  $classification  Failure class controlling retry or quarantine behavior.
     * @param   Throwable              $failure         Failure whose retry classification is being recorded.
     * @param   ?DateTimeImmutable     $retryAt         Next eligible attempt timestamp, or null for quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fail(
        OutboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void;

    /**
     * Make an operator-authorized event eligible for replay.
     *
     * @param   string              $eventId      Immutable identifier of the event to replay.
     * @param   string              $operatorId   Authenticated operator authorizing the replay.
     * @param   ?DateTimeImmutable  $availableAt  Earliest timestamp at which the event may be claimed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function replay(string $eventId, string $operatorId, ?DateTimeImmutable $availableAt = null): void;

    /**
     * Purge an operator-bounded batch of expired records.
     *
     * @param   DateTimeImmutable  $now    Authoritative timestamp for the state transition.
     * @param   int                $limit  Maximum number of records the operation may return or change.
     *
     * @return  int  Number of retained terminal rows removed.
     *
     * @since   2.0.0
     */
    public function purgeExpired(DateTimeImmutable $now, int $limit = 1_000): int;

    /**
     * Return the most recent operator-visible records.
     *
     * @param   int  $limit  Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible rows.
     *
     * @since   2.0.0
     */
    public function recent(int $limit = 100): array;
}
