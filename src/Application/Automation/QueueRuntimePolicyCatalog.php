<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

/**
 * Read side of the active trusted queue and contributed-job policy graph.
 *
 * Core queues deliberately return no policy and retain the established queue defaults. A contributed
 * queue returns its signed limits, while `maximumAttempts()` also folds in a contributed job handler's
 * own attempt ceiling. Producers and scheduler dispatch therefore cannot persist a budget wider than
 * either active declaration.
 *
 * @since  2.0.0
 */
interface QueueRuntimePolicyCatalog
{
    /**
     * Resolve one active contributed queue.
     *
     * @param   string  $queue  Logical queue identifier.
     *
     * @return  ?QueueRuntimePolicy  Active trusted policy, or null for an undeclared core queue.
     *
     * @since   2.0.0
     */
    public function policy(string $queue): ?QueueRuntimePolicy;

    /**
     * Narrow a requested attempt budget through the active job-handler and queue declarations.
     *
     * @param   string  $queue      Destination queue.
     * @param   string  $jobType    Registered handler type.
     * @param   int     $requested  Producer or schedule attempt budget.
     *
     * @return  int  Budget no wider than the request, contributed handler, or contributed queue.
     *
     * @since   2.0.0
     */
    public function maximumAttempts(string $queue, string $jobType, int $requested): int;

    /**
     * List active contributed queue policies in deterministic queue order.
     *
     * @return  list<QueueRuntimePolicy>  Trusted active queue policies.
     *
     * @since   2.0.0
     */
    public function policies(): array;
}
