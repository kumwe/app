<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Delivery\Api;

use Kumwe\CMS\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\CMS\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityPlan;

/**
 * Renders business-definition application results as stable REST documents.
 *
 * Every delivery surface reads definitions through the same application service, so this
 * presenter only shapes already-authorized results; it makes no decisions of its own.
 */
final readonly class BusinessDefinitionApiPresenter
{
    /** @return array<string, mixed> */
    public function catalogEntry(DefinitionCatalogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'handle' => $entry->handle,
            'site' => $entry->siteIdentifier,
            'owner' => $entry->owner->toArray(),
            'owner_active' => $entry->ownerActive,
            'draft_revision' => $entry->draftRevision,
            'published_version' => $entry->publishedVersion,
            'status' => $entry->status->value,
        ];
    }

    /** @return array<string, mixed> */
    public function draft(DefinitionDraft $draft): array
    {
        return [
            'revision' => $draft->revision,
            'checksum' => $draft->checksum,
            'updated_by' => $draft->updatedBy,
            'updated_at' => $draft->updatedAt->format(DATE_ATOM),
            'definition' => $draft->definition->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function version(DefinitionVersionRecord $record): array
    {
        return [
            'version' => $record->definition->definitionVersion,
            'status' => $record->status->value,
            'checksum' => $record->definition->checksum(),
            'published_by' => $record->publishedBy,
            'published_at' => $record->publishedAt->format(DATE_ATOM),
            'compatibility' => $this->compatibility($record->compatibility),
            'definition' => $record->definition->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function compatibility(CompatibilityPlan $plan): array
    {
        return $plan->toArray();
    }
}
