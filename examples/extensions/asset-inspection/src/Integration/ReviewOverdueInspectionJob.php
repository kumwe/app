<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Integration;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Application\Automation\JobHandler;
use Kumwe\Extension\Spi\Application\ExecutionContext;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\JobContributionDefinition;

/**
 * Evaluates the bounded overdue-review window scheduled by the proof component.
 *
 * The neutral example has no notification or ERP side effect. Successful execution writes only a
 * process-local digest for the graphical proof page; the durable job ledger remains core-owned.
 *
 * @since  2.0.0
 */
final readonly class ReviewOverdueInspectionJob implements JobHandler
{
    /**
     * Bind scheduled review evidence to the bounded diagnostic ledger.
     *
     * @param  IntegrationLedger  $ledger  Non-authoritative diagnostic sink.
     *
     * @since  2.0.0
     */
    public function __construct(private IntegrationLedger $ledger)
    {
    }

    /**
     * Validate the closed site payload and record an idempotent diagnostic digest.
     *
     * @param   JobContributionDefinition  $definition  Host-validated signed job declaration.
     * @param   array<string, mixed>        $payload     Schema-one site and minimum-age arguments.
     * @param   ExecutionContext            $context     Fresh worker-owned site context.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When payload keys, bounds, or site ownership are invalid.
     *
     * @since   2.0.0
     */
    public function handle(
        JobContributionDefinition $definition,
        array $payload,
        ExecutionContext $context,
    ): void {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $site = $payload['site_identifier'] ?? null;
        $days = $payload['minimum_age_days'] ?? null;
        if (
            $definition->identifier() !== 'kumwe.asset-inspection-example.review-overdue'
            || $keys !== ['minimum_age_days', 'site_identifier']
            || !is_string($site)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $site) !== 1
            || !is_int($days)
            || $days < 1
            || $days > 365
            || $context->siteIdentifier() !== $site
        ) {
            throw new InvalidArgumentException('The overdue inspection review payload is invalid.');
        }
        $this->ledger->recordJob($site, $days);
    }
}
