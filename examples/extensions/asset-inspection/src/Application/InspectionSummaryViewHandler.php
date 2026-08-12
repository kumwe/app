<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Application;

use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\CMS\BusinessRecord\Query\RecordProjection;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewResult;

/**
 * Projects a bounded inspection summary through the canonical record-service policy boundary.
 *
 * The handler narrows the caller's already-validated record query to the selected bounded row count and two
 * declared result fields. `BusinessRecordService` applies the generated surface's canonical browse capability,
 * scope, row, and field policy before any value reaches the extension, so denied records cannot enter it.
 *
 * @since  2.0.0
 */
final readonly class InspectionSummaryViewHandler implements CustomBusinessViewHandler
{
    /**
     * Bind the generated custom view to the canonical policy-enforcing record service.
     *
     * @param  BusinessRecordService  $records  Canonical policy-enforcing record facade.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordService $records,
    ) {
    }

    /**
     * Return only policy-admitted, site-scoped summary fields for the authenticated caller.
     *
     * @param   CustomBusinessViewQuery  $query  Validated generated-view request and actor context.
     *
     * @return  CustomBusinessViewResult  Contract-bounded policy-filtered summary.
     *
     * @since  2.0.0
     */
    public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
    {
        $requested = $query->records;
        $specification = new RecordQuerySpecification(
            $requested->filter,
            $requested->search,
            $requested->sorts,
            null,
            $requested->pageSize,
            new RecordProjection(['reference', 'risk_score']),
            $requested->includeArchived,
            $requested->includeDeleted,
        );
        $page = $this->records->browse(new BrowseRecordsQuery(
            $query->context,
            $query->definitionIdentifier,
            $specification,
            $query->organizationIdentifier,
        ));
        $rows = [];
        foreach ($page->records as $record) {
            $reference = $record->values['reference'] ?? null;
            $riskScore = $record->values['risk_score'] ?? null;
            if (is_string($reference) && is_int($riskScore)) {
                $rows[] = ['reference' => $reference, 'risk_score' => $riskScore];
            }
        }

        return new CustomBusinessViewResult([
            'heading' => 'Policy-filtered inspection risk summary',
            'inspections' => $rows,
            'restricted_fields_disclosed' => false,
        ]);
    }
}
