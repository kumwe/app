<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Automation\FailureClassification;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkItem;
use Throwable;

/**
 * Durable optimistic process state and leased work repository.
 *
 * @since  2.0.0
 */
interface ProcessManagerStore
{
    /** @param iterable<ProcessWorkItem> $work @return void @since 2.0.0 */
    public function create(ProcessInstance $process, iterable $work = []): void;

    /** @return ?ProcessInstance @since 2.0.0 */
    public function load(string $processId): ?ProcessInstance;

    /** @return ?ProcessInstance @since 2.0.0 */
    public function findByCorrelation(string $processType, string $correlationId): ?ProcessInstance;

    /** @param iterable<ProcessWorkItem> $work @return void @since 2.0.0 */
    public function save(ProcessInstance $process, int $expectedVersion, iterable $work = []): void;

    /** @return ?ProcessWorkLease @since 2.0.0 */
    public function claimWork(string $workerId, string $runtimeGeneration, int $leaseSeconds): ?ProcessWorkLease;

    /** @return void @since 2.0.0 */
    public function renewWork(ProcessWorkLease $lease, int $leaseSeconds): void;

    /** @return void @since 2.0.0 */
    public function completeWork(ProcessWorkLease $lease): void;

    /** @return void @since 2.0.0 */
    public function failWork(
        ProcessWorkLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void;

    /** @return list<array<string, mixed>> Operator-visible process snapshots. @since 2.0.0 */
    public function recent(int $limit = 100): array;

    /** @return list<array<string, mixed>> Operator-visible work for one process. @since 2.0.0 */
    public function work(string $processId, int $limit = 100): array;
}
