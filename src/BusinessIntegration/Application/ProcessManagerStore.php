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
    /**
     * Persist a new process instance and its initial work.
     *
     * @param   ProcessInstance            $process  Current process instance being read or transitioned.
     * @param   iterable<ProcessWorkItem>  $work     Process work emitted by the transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function create(ProcessInstance $process, iterable $work = []): void;

    /**
     * Load the requested durable record when it exists.
     *
     * @param   string  $processId  Stable identifier of the process instance.
     *
     * @return  ?ProcessInstance
     *
     * @since   2.0.0
     */
    public function load(string $processId): ?ProcessInstance;

    /**
     * Find the process instance matching the supplied correlation key.
     *
     * @param   string  $processType     Stable process-manager type used to scope correlation.
     * @param   string  $siteIdentifier  Owning site that isolates the correlation namespace.
     * @param   string  $correlationId   Stable correlation key joining related process events.
     *
     * @return  ?ProcessInstance
     *
     * @since   2.0.0
     */
    public function findByCorrelation(
        string $processType,
        string $siteIdentifier,
        string $correlationId,
    ): ?ProcessInstance;

    /**
     * Persist the supplied state with optimistic concurrency protection.
     *
     * @param   ProcessInstance            $process          Current process instance being read or transitioned.
     * @param   int                        $expectedVersion  Version required for optimistic concurrency.
     * @param   iterable<ProcessWorkItem>  $work             Process work emitted by the transition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(ProcessInstance $process, int $expectedVersion, iterable $work = []): void;

    /**
     * Claim the next eligible process work item for the named worker.
     *
     * @param   string  $workerId           Stable identity of the claiming worker.
     * @param   string  $runtimeGeneration  Trusted runtime generation that owns the lease.
     * @param   int     $leaseSeconds       Number of seconds before the worker lease expires.
     *
     * @return  ?ProcessWorkLease
     *
     * @since   2.0.0
     */
    public function claimWork(string $workerId, string $runtimeGeneration, int $leaseSeconds): ?ProcessWorkLease;

    /**
     * Renew the supplied process-work lease.
     *
     * @param   ProcessWorkLease  $lease         Fenced lease proving ownership of the durable item.
     * @param   int               $leaseSeconds  Number of seconds before the worker lease expires.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function renewWork(ProcessWorkLease $lease, int $leaseSeconds): void;

    /**
     * Mark the supplied process-work lease complete.
     *
     * @param   ProcessWorkLease  $lease  Fenced lease proving ownership of the durable item.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function completeWork(ProcessWorkLease $lease): void;

    /**
     * Record failed process work and its retry decision.
     *
     * @param   ProcessWorkLease       $lease           Fenced lease proving ownership of the durable item.
     * @param   FailureClassification  $classification  Failure class controlling retry or quarantine behavior.
     * @param   Throwable              $failure         Failure whose retry classification is being recorded.
     * @param   ?DateTimeImmutable     $retryAt         Next eligible attempt timestamp, or null for quarantine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function failWork(
        ProcessWorkLease $lease,
        FailureClassification $classification,
        Throwable $failure,
        ?DateTimeImmutable $retryAt,
    ): void;

    /**
     * Return the most recent operator-visible records.
     *
     * @param   int  $limit  Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible process snapshots.
     *
     * @since   2.0.0
     */
    public function recent(int $limit = 100): array;

    /**
     * Return operator-visible work for the requested process.
     *
     * @param   string  $processId  Stable identifier of the process instance.
     * @param   int     $limit      Maximum number of records the operation may return or change.
     *
     * @return  list<array<string, mixed>>  Operator-visible work for one process.
     *
     * @since   2.0.0
     */
    public function work(string $processId, int $limit = 100): array;
}
