<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;

/** Keeps preserved physical data unavailable while an owning extension is disabled. */
final readonly class BusinessSchemaLifecycleManager implements BusinessSchemaLifecycleObserver
{
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private DefinitionPhysicalSchemaCompiler $compiler,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessSchemaPlanRepository $plans,
        private PhysicalSchemaGateway $physicalSchema,
    ) {
    }

    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $at): void
    {
        foreach ($this->installations->ownedByForUpdate($ownerIdentifier) as $installation) {
            if (!$active) {
                if ($installation->status === SchemaInstallationStatus::Active) {
                    $this->installations->save($installation->disable($at));
                } elseif ($installation->status === SchemaInstallationStatus::Installing) {
                    $this->installations->save($installation->preserve($at));
                }
                continue;
            }
            if (!in_array(
                $installation->status,
                [SchemaInstallationStatus::Disabled, SchemaInstallationStatus::Preserved],
                true,
            )) {
                continue;
            }
            $site = SiteContext::fromString($installation->siteIdentifier);
            if ($this->plans->hasUnfinishedExecution($site, $installation->definitionId)) {
                throw new BusinessSchemaConflict(
                    'An extension schema cannot reactivate while schema recovery is incomplete.',
                );
            }
            $record = $this->definitions->published(
                $site,
                $installation->definitionId,
                $installation->definitionVersion,
            )
                ?? throw new BusinessSchemaConflict(
                    'An extension schema cannot reactivate without its published definition.',
                );
            $target = $this->compiler->compile($record->definition, $site);
            $inspected = $this->physicalSchema->inspect($installation->blueprint);
            if ($record->definition->owner->identifier !== $ownerIdentifier
                || !hash_equals($target->checksum(), $installation->schemaChecksum)
                || $inspected === null
                || !hash_equals($inspected->checksum(), $installation->schemaChecksum)) {
                throw new BusinessSchemaConflict(
                    'An extension schema requires an approved synchronization plan before reactivation.',
                );
            }
            $this->installations->save($installation->reactivate($at));
        }
    }
}
