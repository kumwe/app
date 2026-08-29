<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationEvent;
use Throwable;

/**
 * Durable per-consumer deduplication, ordering and poison-message ledger.
 *
 * @since  2.0.0
 */
interface InboxStore
{
    /**
     * Claim or deduplicate an event for the declared consumer.
     *
     * @param   EventConsumerDefinition  $consumer           Signed consumer contract governing the receipt.
     * @param   IntegrationEvent         $event              Versioned event being validated or processed.
     * @param   string                   $workerId           Stable identity of the claiming worker.
     * @param   string                   $runtimeGeneration  Trusted runtime generation that owns the lease.
     * @param   int                      $leaseSeconds       Number of seconds before the worker lease expires.
     *
     * @return  InboxClaimResult
     *
     * @since   2.0.0
     */
    public function receive(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): InboxClaimResult;

    /**
     * Renew the supplied durable-processing lease.
     *
     * @param   InboxLease  $lease         Fenced lease proving ownership of the durable item.
     * @param   int         $leaseSeconds  Number of seconds before the worker lease expires.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renew(InboxLease $lease, int $leaseSeconds): void;

    /**
     * Mark the supplied durable-processing lease complete.
     *
     * @param   InboxLease  $lease  Fenced lease proving ownership of the durable item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function complete(InboxLease $lease): void;

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
    ): void;

    /**
     * Return the most recent operator-visible records.
     *
     * @param   string  $consumerId  Stable consumer identifier used to scope receipt history.
     * @param   int     $limit       Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible delivery rows.
     *
     * @since   2.0.0
     */
    public function recent(string $consumerId, int $limit = 100): array;
}
