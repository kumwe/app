<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Application;

use Kumwe\Extension\Spi\Application\ExecutionContext;
use KumweExample\AssetInspection\Integration\IntegrationLedger;

/**
 * Builds safe transport-neutral models for the administrator and explicitly opted-in portal pages.
 *
 * @since  2.0.0
 */
final readonly class InspectionOverviewService
{
    /**
     * Bind overview models to bounded handler diagnostics.
     *
     * @param  IntegrationLedger  $ledger  Non-authoritative integration evidence.
     *
     * @since  2.0.0
     */
    public function __construct(private IntegrationLedger $ledger)
    {
    }

    /**
     * Build the graphical manager dashboard model.
     *
     * @param   ExecutionContext  $context  Authorized administrator execution context.
     *
     * @return  array<string, mixed>  Safe component inventory, policy proof, and site-filtered diagnostics.
     *
     * @since   2.0.0
     */
    public function administrator(ExecutionContext $context): array
    {
        return $this->model($context) + [
            'surface_label' => 'Administrator proof dashboard',
            'activity' => $this->ledger->snapshot($context->siteIdentifier()),
        ];
    }

    /**
     * Build the deliberately read-only portal status model.
     *
     * @param   ExecutionContext  $context  Authorized portal execution context.
     *
     * @return  array<string, mixed>  Safe site-filtered status with no restricted field values.
     *
     * @since   2.0.0
     */
    public function portal(ExecutionContext $context): array
    {
        return $this->model($context) + [
            'surface_label' => 'Read-only portal proof',
            'activity' => $this->ledger->snapshot($context->siteIdentifier()),
        ];
    }

    /**
     * Build a shared neutral proof model after host route authorization.
     *
     * @param   ExecutionContext  $context  Context selecting the authorized site and fields.
     *
     * @return  array<string, mixed>  Shared renderer data.
     *
     * @since   2.0.0
     */
    private function model(ExecutionContext $context): array
    {
        $site = $context->siteIdentifier();
        $summaries = [[
            'reference' => 'EXAMPLE-INSPECTION-001',
            'risk_score' => 82,
        ]];

        return [
            'heading' => 'Asset inspection example',
            'notice' => 'Neutral extensibility proof only; this component is not an ERP module.',
            'site_identifier' => $site,
            'entities' => ['Location', 'Asset', 'Inspection', 'Finding', 'Measurement'],
            'integrations' => [
                'Atomic core record-mutation listener',
                'Inbox-deduplicated aggregate-ordered consumer',
                'Daily site-scoped job',
                'Rebuildable mutation projection',
                'Policy-aware report and export definition',
            ],
            'summaries' => $summaries,
            'restricted_disclosed' => false,
        ];
    }
}
