<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Infrastructure;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\CMS\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\CMS\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessReporting\Application\BusinessRecordReportReader;

/**
 * Adapter forcing every reporting read through `BusinessRecordService::browse()`.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordServiceReportReader implements BusinessRecordReportReader
{
    /**
     * Wire the reporting seam to the canonical policy-enforcing service.
     *
     * @param  BusinessRecordService  $records  Canonical business record use case.
     *
     * @since  2.0.0
     */
    public function __construct(private BusinessRecordService $records)
    {
    }

    /**
     * Read one policy-filtered and disclosure-safe page.
     *
     * @param   ExecutionContext            $context                 Authenticated actor and scope.
     * @param   string                      $definitionIdentifier    Source business entity handle.
     * @param   RecordQuerySpecification    $specification           Bounded typed query AST.
     * @param   ?string                     $organizationIdentifier  Requested organization scope.
     * @param   BusinessRecordQueryPurpose  $purpose                 Report or export usage.
     *
     * @return  RecordBrowseResult  Safe page from the record service.
     *
     * @since   2.0.0
     */
    public function browse(
        ExecutionContext $context,
        string $definitionIdentifier,
        RecordQuerySpecification $specification,
        ?string $organizationIdentifier,
        BusinessRecordQueryPurpose $purpose,
    ): RecordBrowseResult {
        return $this->records->browse(new BrowseRecordsQuery(
            $context,
            $definitionIdentifier,
            $specification,
            $organizationIdentifier,
            $purpose,
        ));
    }
}
