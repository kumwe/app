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
 * Time-budgeted, adaptively batched retention driver for the shared delivery idempotency ledger.
 *
 * Every keyed HTTP and machine mutation writes a record, so the ledger's enterprise ingress is the same
 * order as the business ledger's. A run is bounded by the wall-clock budget the retention catalogue
 * declares; the job type is installation-global and sweeps the shared ledger once per installation.
 *
 * @since  2.0.0
 */
final readonly class PurgeIdempotencyRecordsHandler implements JobHandler
{
    /**
     * Bind the handler to the drain, the catalogue and the gateway that guards it.
     *
     * @param  RetentionDrain        $drain          Drain that removes expired entries inside the budget.
     * @param  RetentionCatalogue    $catalogue      Declared budget the payload may only narrow.
     * @param  AuthorizationGateway  $authorization  Decides whether the job context may run the sweep.
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
     * Report the job type a schedule or queued job names this handler by.
     *
     * @return  string  The constant `system.idempotency.purge`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return 'system.idempotency.purge';
    }

    /**
     * Drain expired entries within the declared budget, narrowed by any payload override.
     *
     * The capability is re-asserted against this job type rather than trusted from whoever created the
     * schedule, because a queued job outlives the request that scheduled it. The payload's historical
     * `batch_size` and `maximum_batches` keys remain honoured, but only as caps inside the declared budget.
     *
     * @param   array<string, mixed>  $payload  Optional positive integers `batch_size` (batch ceiling, at most
     *          10000), `maximum_batches` (cap per run, at most 100) and `time_budget_seconds` (at most the
     *          declared budget).
     * @param   ExecutionContext      $context  System context the automation capability is checked against.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When an override is not a positive integer or would widen the budget.
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
        $overrides = [];
        foreach (['batch_size', 'maximum_batches', 'time_budget_seconds'] as $key) {
            $value = $payload[$key] ?? null;
            if ($value !== null && (!is_int($value) || $value < 1)) {
                throw new InvalidArgumentException('Idempotency purge limits are invalid.');
            }
            $overrides[$key] = $value;
        }
        if ($overrides['maximum_batches'] !== null && $overrides['maximum_batches'] > 100) {
            throw new InvalidArgumentException('Idempotency purge limits are invalid.');
        }
        try {
            $budget = $this->catalogue->policy(RetentionStore::DeliveryIdempotency)->budget()->narrowed(
                $overrides['time_budget_seconds'],
                $overrides['batch_size'],
                $overrides['maximum_batches'],
            );
        } catch (InvalidArgumentException $widened) {
            throw new InvalidArgumentException('Idempotency purge limits are invalid.', 0, $widened);
        }
        $this->drain->drain(RetentionStore::DeliveryIdempotency, $budget, $context);
    }
}
