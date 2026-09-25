<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation\Job;

use InvalidArgumentException;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\Capability;
use Kumwe\App\Application\Retention\RetentionCatalogue;
use Kumwe\App\Application\Retention\RetentionDrain;
use Kumwe\App\Application\Retention\RetentionStore;
use Kumwe\Automation\JobHandler;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Scheduled, time-budgeted drain of one declared hot store.
 *
 * One job type serves every store the catalogue declares as drainable, with the store named in the
 * payload, so an installation has one schedule row per ledger and the same adaptive batching behind
 * each. The payload may only narrow the declared budget — a shorter time budget, a lower batch ceiling
 * or a cap on batches — never widen it, and a store the catalogue declares as retained for good is
 * refused rather than silently skipped, so a mistyped schedule cannot delete history or hide the fact
 * that it is doing nothing.
 *
 * @since  2.0.0
 */
final readonly class DrainRetentionStoreHandler implements JobHandler
{
    /**
     * Bind the handler to the drain, the catalogue and the gateway that guards it.
     *
     * @param  RetentionDrain        $drain          Drain that removes one store's expired rows in budget.
     * @param  RetentionCatalogue    $catalogue      Declarations the payload's store is resolved against.
     * @param  AuthorizationGateway  $authorization  Decides whether the job context may run maintenance.
     *
     * @since  2.0.0
     */
    public function __construct(
        private RetentionDrain $drain,
        private RetentionCatalogue $catalogue,
        private AuthorizationGateway $authorization,
    ) {
    }

    /**
     * Report the job type a schedule names this handler by.
     *
     * @return  string  The constant `system.retention.drain`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return RetentionCatalogue::DRAIN_JOB_TYPE;
    }

    /**
     * Drain the payload's store within its declared budget, narrowed by any payload override.
     *
     * @param   array<string, mixed>  $payload  Required string `store` naming a `RetentionStore` value;
     *          optional integers `time_budget_seconds`, `batch_size` and `maximum_batches`, each of which
     *          may only narrow the declared budget.
     * @param   ExecutionContext      $context  System context the automation capability is checked against.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the store is missing, unknown or not drainable, or an override
     *          is not an integer, widens the budget or falls outside its range.
     * @throws  \Kumwe\Access\AuthorizationDenied  When the job context may not manage this installation-wide
     *          job type.
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('automation.manage'),
            AuthorizationResource::item('automation_installation', $this->type()),
        );
        $name = $payload['store'] ?? null;
        $store = is_string($name) ? RetentionStore::tryFrom($name) : null;
        if ($store === null) {
            throw new InvalidArgumentException('A retention drain payload must name a declared store.');
        }
        $policy = $this->catalogue->policy($store);
        if (!$policy->drainable() || $policy->drainJobType !== $this->type()) {
            throw new InvalidArgumentException('The named store is not drained by the generic retention job.');
        }
        $overrides = [];
        foreach (['time_budget_seconds', 'batch_size', 'maximum_batches'] as $key) {
            $value = $payload[$key] ?? null;
            if ($value !== null && (!is_int($value) || $value < 1)) {
                throw new InvalidArgumentException('A retention drain override must be a positive integer.');
            }
            $overrides[$key] = $value;
        }
        $budget = $policy->budget()->narrowed(
            $overrides['time_budget_seconds'],
            $overrides['batch_size'],
            $overrides['maximum_batches'],
        );
        $this->drain->drain($store, $budget, $context);
    }
}
