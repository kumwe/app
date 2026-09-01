<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Application;

use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReader;
use Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReadRequest;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordProjection;
use Kumwe\Extension\Spi\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewResult;

/**
 * Projects a bounded inspection summary through the canonical record-service policy boundary.
 *
 * The handler narrows the caller's already-validated record query to its two declared result fields before
 * handing it to `BusinessRecordReader`: a summary view never forwards a caller-chosen projection, so a
 * request naming a field outside the view's contract cannot reach the policy compiler in its name. The host
 * port then applies capability, scope, row and field policy before values cross the extension boundary, so
 * denied records cannot enter the handler.
 *
 * @since  2.0.0
 */
final readonly class InspectionSummaryViewHandler implements CustomBusinessViewHandler
{
    /**
     * Bind the generated custom view to the canonical policy-enforcing record service.
     *
     * @param  BusinessRecordReader  $records  Canonical policy-enforcing record port.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordReader $records,
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
        $page = $this->records->readPage(new BusinessRecordReadRequest(
            $query->context,
            $query->definitionIdentifier,
            $specification,
            $query->organizationIdentifier,
        ));
        $rows = [];
        foreach ($page->records() as $record) {
            $values = $record->values();
            $reference = $values['reference'] ?? null;
            $riskScore = $values['risk_score'] ?? null;
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
