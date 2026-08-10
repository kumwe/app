<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Automation\FailureClassification;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Throwable;

/**
 * Transactional event store and fenced dispatch queue.
 *
 * @since  2.0.0
 */
interface OutboxStore
{
    /** @return void @since 2.0.0 */
    public function append(
        IntegrationEvent $event,
        int $maximumAttempts = 10,
        ?DateTimeImmutable $availableAt = null,
    ): void;

    /** @return ?OutboxLease @since 2.0.0 */
    public function claim(
        string $workerId,
        string $runtimeGeneration,
        int $leaseSeconds,
    ): ?OutboxLease;

    /** @return void @since 2.0.0 */
    public function renew(OutboxLease $lease, int $leaseSeconds): void;

    /** @return void @since 2.0.0 */
    public function complete(OutboxLease $lease): void;

    /** @return void @since 2.0.0 */
    public function fail(
        OutboxLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void;

    /** @return void @since 2.0.0 */
    public function replay(string $eventId, string $operatorId, ?DateTimeImmutable $availableAt = null): void;

    /** @return int Number of retained terminal rows removed. @since 2.0.0 */
    public function purgeExpired(DateTimeImmutable $now, int $limit = 1_000): int;

    /** @return list<array<string, mixed>> Operator-visible rows. @since 2.0.0 */
    public function recent(int $limit = 100): array;
}
