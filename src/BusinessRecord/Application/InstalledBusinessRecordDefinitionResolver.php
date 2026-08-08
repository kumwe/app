<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordDefinitionUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;

final readonly class InstalledBusinessRecordDefinitionResolver implements BusinessRecordDefinitionResolver
{
    public function __construct(
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
    ) {
    }

    public function activeInstalled(ExecutionContext $context): array
    {
        $resolved = [];
        foreach ($this->definitions->catalog($context->site()) as $entry) {
            if (!$entry->ownerActive) {
                continue;
            }
            $installation = $this->installations->find($entry->id);
            if ($installation === null
                || $installation->status !== SchemaInstallationStatus::Active
                || $installation->siteIdentifier !== $context->site()->identifier()
                || $installation->ownerIdentifier !== $entry->owner->identifier) {
                continue;
            }
            $version = $this->definitions->published(
                $context->site(),
                $entry->id,
                $installation->definitionVersion,
            );
            if ($version === null || $version->status === DefinitionStatus::Rejected
                || !hash_equals($version->definition->checksum(), $installation->definitionChecksum)) {
                throw new BusinessRecordSchemaUnavailable(
                    'An active installed definition disagrees with its immutable catalog version.',
                );
            }
            $resolved[] = new ResolvedBusinessDefinition($version->definition, $installation);
        }
        usort(
            $resolved,
            static fn (ResolvedBusinessDefinition $left, ResolvedBusinessDefinition $right): int =>
                $left->definition->handle <=> $right->definition->handle,
        );

        return $resolved;
    }

    public function forCreate(ExecutionContext $context, string $identifier): ResolvedBusinessDefinition
    {
        [$definitionId, $installation] = $this->installation($context, $identifier);
        $version = $this->definitions->published(
            $context->site(),
            $definitionId,
            $installation->definitionVersion,
        );
        if ($version === null || $version->status === DefinitionStatus::Rejected) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        if (!hash_equals($version->definition->checksum(), $installation->definitionChecksum)) {
            throw new BusinessRecordSchemaUnavailable('The installed schema and published definition disagree.');
        }

        return new ResolvedBusinessDefinition($version->definition, $installation);
    }

    public function pinned(
        ExecutionContext $context,
        string $identifier,
        int $definitionVersion,
    ): ResolvedBusinessDefinition {
        [$definitionId, $installation] = $this->installation($context, $identifier);
        if ($definitionVersion > $installation->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('The record definition version is newer than installed schema.');
        }
        $version = $this->definitions->published($context->site(), $definitionId, $definitionVersion);
        if ($version === null || $version->status === DefinitionStatus::Rejected) {
            throw new BusinessRecordDefinitionUnavailable('The record\'s pinned definition version is unavailable.');
        }

        return new ResolvedBusinessDefinition($version->definition, $installation);
    }

    public function forHistory(
        ExecutionContext $context,
        string $identifier,
        ?int $definitionVersion = null,
    ): ResolvedBusinessDefinition {
        [$definitionId, $installation] = $this->installation($context, $identifier, true);
        $definitionVersion ??= $installation->definitionVersion;
        if ($definitionVersion > $installation->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('The historical definition is newer than installed schema.');
        }
        $version = $this->definitions->published($context->site(), $definitionId, $definitionVersion);
        if ($version === null || $version->status === DefinitionStatus::Rejected) {
            throw new BusinessRecordDefinitionUnavailable('A historical pinned definition is unavailable.');
        }

        return new ResolvedBusinessDefinition($version->definition, $installation);
    }

    /** @return array{string, SchemaInstallation} */
    private function installation(
        ExecutionContext $context,
        string $identifier,
        bool $historyOnly = false,
    ): array
    {
        $entry = $this->definitions->entry($context->site(), $identifier);
        if ($entry === null) {
            throw new BusinessRecordDefinitionUnavailable();
        }
        if (!$historyOnly && !$entry->ownerActive) {
            throw new BusinessRecordDefinitionUnavailable('The business-definition owner is disabled.');
        }
        $installation = $this->installations->find($entry->id);
        if (
            $installation === null
            || (!$historyOnly && $installation->status !== SchemaInstallationStatus::Active)
            || ($historyOnly && !in_array($installation->status, [
                SchemaInstallationStatus::Active,
                SchemaInstallationStatus::Installing,
                SchemaInstallationStatus::Disabled,
                SchemaInstallationStatus::Preserved,
            ], true))
            || $installation->siteIdentifier !== $context->site()->identifier()
            || $installation->ownerIdentifier !== $entry->owner->identifier
        ) {
            throw new BusinessRecordSchemaUnavailable();
        }

        return [$entry->id, $installation];
    }
}
