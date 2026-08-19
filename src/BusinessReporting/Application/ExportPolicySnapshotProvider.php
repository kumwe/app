<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;

/**
 * Resolves the current row, field, relation and authority decision for an export.
 *
 * @since  2.0.0
 */
interface ExportPolicySnapshotProvider
{
    /**
     * Fingerprint the exact access plan that would compile the report query now.
     *
     * @param   ExecutionContext            $context                 Current original-actor context.
     * @param   ReportDefinition            $report                  Exact report definition.
     * @param   ?string                     $organizationIdentifier  Authenticated organization scope.
     * @param   BusinessRecordQueryPurpose  $purpose                 Export or report policy usage.
     *
     * @return  string  Lowercase SHA-256 access-plan snapshot.
     *
     * @since   2.0.0
     */
    public function snapshot(
        ExecutionContext $context,
        ReportDefinition $report,
        ?string $organizationIdentifier,
        BusinessRecordQueryPurpose $purpose,
    ): string;
}
