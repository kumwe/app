<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Infrastructure;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\ScopeMode;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\CMS\BusinessRecord\Application\Query\BusinessRecordQueryPurpose;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessReporting\Application\ExportPolicySnapshotProvider;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessController;

/**
 * Builds export snapshots from the same resolved business-record access plan used by query compilation.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordExportPolicySnapshotProvider implements ExportPolicySnapshotProvider
{
    /**
     * Wire definition resolution to the canonical access planner.
     *
     * @param  BusinessRecordDefinitionResolver  $definitions  Active installed definition resolver.
     * @param  BusinessRecordAccessController    $access       Row, field and relation policy planner.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessRecordDefinitionResolver $definitions,
        private BusinessRecordAccessController $access,
    ) {
    }

    /**
     * Fingerprint the exact plan, definition and scope that an export query would use now.
     *
     * @param   ExecutionContext            $context                 Current original-actor context.
     * @param   ReportDefinition            $report                  Exact report definition.
     * @param   ?string                     $organizationIdentifier  Authenticated organization scope.
     * @param   BusinessRecordQueryPurpose  $purpose                 Report or export policy usage.
     *
     * @return  string  Lowercase SHA-256 policy snapshot.
     *
     * @throws  InvalidArgumentException  When request scope differs from authenticated membership.
     *
     * @since   2.0.0
     */
    public function snapshot(
        ExecutionContext $context,
        ReportDefinition $report,
        ?string $organizationIdentifier,
        BusinessRecordQueryPurpose $purpose,
    ): string {
        if ($purpose === BusinessRecordQueryPurpose::Browse) {
            throw new InvalidArgumentException('An export policy snapshot requires report or export purpose.');
        }
        $resolved = $this->definitions->forCreate($context, $report->sourceDefinition);
        $organizationScoped = in_array(
            $resolved->definition->scope,
            [ScopeMode::Organization, ScopeMode::SiteOrganization],
            true,
        );
        $authenticatedOrganization = $context->organization()?->identifier();
        if (($organizationScoped && $authenticatedOrganization === null)
            || ($organizationScoped && $organizationIdentifier !== null
                && $organizationIdentifier !== $authenticatedOrganization)
            || (!$organizationScoped && $organizationIdentifier !== null)
        ) {
            throw new InvalidArgumentException('The export scope does not match authenticated membership.');
        }
        $scope = RecordScope::forDefinition(
            $resolved->definition->scope,
            $context->site(),
            $organizationScoped ? $authenticatedOrganization : null,
        );
        $operation = 'business.record.' . $purpose->value;
        $plan = $this->access->plan($context, $operation, $resolved, $scope);

        return CanonicalDefinitionJson::checksum([
            'operation' => $operation,
            'definition' => $resolved->definition->checksum(),
            'installation_version' => $resolved->installation->definitionVersion,
            'scope' => $scope->toArray(),
            'plan' => $plan->digest(),
        ]);
    }
}
