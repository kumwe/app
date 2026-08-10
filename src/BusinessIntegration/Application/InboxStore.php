<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Automation\FailureClassification;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Throwable;

/**
 * Durable per-consumer deduplication, ordering and poison-message ledger.
 *
 * @since  2.0.0
 */
interface InboxStore
{
    /** @return InboxClaimResult @since 2.0.0 */
    public function receive(
        EventConsumerDefinition $consumer,
        IntegrationEvent $event,
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): InboxClaimResult;

    /** @return void @since 2.0.0 */
    public function renew(InboxLease $lease, int $leaseSeconds): void;

    /** @return void @since 2.0.0 */
    public function complete(InboxLease $lease): void;

    /** @return void @since 2.0.0 */
    public function fail(
        InboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void;

    /** @return list<array<string, mixed>> Operator-visible delivery rows. @since 2.0.0 */
    public function recent(string $consumerId, int $limit = 100): array;
}
