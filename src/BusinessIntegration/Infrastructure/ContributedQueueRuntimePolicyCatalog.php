<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Infrastructure;

use Kumwe\CMS\Application\Automation\QueueRuntimePolicy;
use Kumwe\CMS\Application\Automation\QueueRuntimePolicyCatalog;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Runtime\RuntimeMaterializationState;
use RuntimeException;

/**
 * Compiles active owner-bound queue and job declarations into executable runtime policy.
 *
 * The contribution registries contain only definitions loaded from the verified runtime publication.
 * This adapter deliberately reads them live rather than copying them during container construction, so
 * it observes the completed contribution phase while the worker generation guard remains responsible
 * for stopping a long-lived process as soon as that publication becomes stale.
 *
 * @since  2.0.0
 */
final readonly class ContributedQueueRuntimePolicyCatalog implements QueueRuntimePolicyCatalog
{
    /**
     * Bind policy compilation to the active contribution graph and its immutable loaded generation.
     *
     * @param  ExtensionContributionRegistrySet  $contributions  Active trusted contribution registries.
     * @param  RuntimeMaterializationState       $runtime        Runtime publication loaded by this process.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionContributionRegistrySet $contributions,
        private RuntimeMaterializationState $runtime,
    ) {
    }

    /**
     * Resolve one active contributed queue declaration.
     *
     * @param   string  $queue  Logical queue identifier.
     *
     * @return  ?QueueRuntimePolicy  Executable signed policy, or null for an undeclared core queue.
     *
     * @throws  RuntimeException  When the trusted registry contains an unexpected definition type.
     *
     * @since   2.0.0
     */
    public function policy(string $queue): ?QueueRuntimePolicy
    {
        foreach ($this->policies() as $policy) {
            if ($policy->queue === $queue) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * Intersect producer, handler and queue delivery-attempt budgets.
     *
     * @param   string  $queue      Destination queue.
     * @param   string  $jobType    Registered handler type.
     * @param   int     $requested  Producer or schedule budget.
     *
     * @return  int  Effective attempt ceiling.
     *
     * @throws  RuntimeException  When the trusted registry contains an unexpected definition type.
     *
     * @since   2.0.0
     */
    public function maximumAttempts(string $queue, string $jobType, int $requested): int
    {
        $maximum = $requested;
        foreach ($this->contributions->jobs()->definitions() as $definition) {
            if (!$definition instanceof JobContributionDefinition) {
                throw new RuntimeException('The trusted job registry contains an invalid definition.');
            }
            if ($definition->identifier() === $jobType) {
                $maximum = min($maximum, $definition->maximumAttempts());
                break;
            }
        }
        $policy = $this->policy($queue);

        return $policy === null ? $maximum : min($maximum, $policy->maximumAttempts);
    }

    /**
     * Compile every active queue declaration in identifier order.
     *
     * @return  list<QueueRuntimePolicy>  Active executable queue policies.
     *
     * @throws  RuntimeException  When the runtime is untrusted or a registry entry has the wrong type.
     *
     * @since   2.0.0
     */
    public function policies(): array
    {
        $definitions = $this->contributions->queues()->definitions();
        if ($definitions !== [] && (!$this->runtime->trusted || $this->runtime->generation < 0)) {
            throw new RuntimeException('Contributed queue policy requires a trusted runtime generation.');
        }
        $policies = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof QueueContributionDefinition) {
                throw new RuntimeException('The trusted queue registry contains an invalid definition.');
            }
            $policies[] = new QueueRuntimePolicy(
                $definition->identifier(),
                $definition->leaseSeconds(),
                $definition->maximumAttempts(),
                $definition->maximumInFlight(),
                $definition->retentionDays(),
                $this->runtime->generation,
            );
        }

        return $policies;
    }
}
