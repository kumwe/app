<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use InvalidArgumentException;

/**
 * Trusted executable limits for one contributed durable queue.
 *
 * The contribution layer compiles signed queue declarations into this delivery-neutral value. Queue
 * producers, schedulers, workers and operator tooling then consume the same limits, so inventory cannot
 * drift away from the runtime behavior it describes.
 *
 * @since  2.0.0
 */
final readonly class QueueRuntimePolicy
{
    /**
     * Capture one active queue policy and the trusted runtime generation that supplied it.
     *
     * @param   string  $queue              Namespaced logical queue identifier.
     * @param   int     $leaseSeconds       Longest permitted claim or renewal lease.
     * @param   int     $maximumAttempts    Queue-wide delivery-attempt ceiling.
     * @param   int     $maximumInFlight    Durable cross-process in-flight ceiling.
     * @param   int     $retentionDays      Terminal job deletion and delivery-detail compaction retention.
     * @param   int     $runtimeGeneration  Trusted extension runtime generation.
     *
     * @throws  InvalidArgumentException  When a value falls outside the portable contribution bounds.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $queue,
        public int $leaseSeconds,
        public int $maximumAttempts,
        public int $maximumInFlight,
        public int $retentionDays,
        public int $runtimeGeneration,
    ) {
        if (
            strlen($queue) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){1,15}$/D', $queue) !== 1
            || $leaseSeconds < 5 || $leaseSeconds > 3_600
            || $maximumAttempts < 1 || $maximumAttempts > 100
            || $maximumInFlight < 1 || $maximumInFlight > 1_024
            || $retentionDays < 1 || $retentionDays > 3_650
            || $runtimeGeneration < 0
        ) {
            throw new InvalidArgumentException('A queue runtime policy is invalid.');
        }
    }

    /**
     * Export the policy for authenticated operator surfaces.
     *
     * @return  array{
     *              queue: string,
     *              lease_seconds: int,
     *              maximum_attempts: int,
     *              maximum_in_flight: int,
     *              retention_days: int,
     *              runtime_generation: int
     *          } Canonical policy document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'queue' => $this->queue,
            'lease_seconds' => $this->leaseSeconds,
            'maximum_attempts' => $this->maximumAttempts,
            'maximum_in_flight' => $this->maximumInFlight,
            'retention_days' => $this->retentionDays,
            'runtime_generation' => $this->runtimeGeneration,
        ];
    }
}
