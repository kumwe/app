<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Delivery\Api;

use Kumwe\App\BusinessDefinition\Application\DefinitionCatalogEntry;
use Kumwe\App\BusinessDefinition\Application\DefinitionDraft;
use Kumwe\App\BusinessDefinition\Application\DefinitionVersionRecord;
use Kumwe\App\BusinessDefinition\Domain\CompatibilityPlan;

/**
 * Renders business-definition application results as stable REST documents.
 *
 * Every delivery surface reads definitions through the same application service, so this
 * presenter only shapes already-authorized results; it makes no decisions of its own.
 *
 * @since  2.0.0
 */
final readonly class BusinessDefinitionApiPresenter
{
    /**
     * Render one catalog row as a collection item.
     *
     * @param   DefinitionCatalogEntry  $entry  Row read from the application catalog.
     *
     * @return  array<string, mixed>  Identity, ownership and revision counters; no definition body.
     *
     * @since   2.0.0
     */
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

    /**
     * Render a draft with the revision metadata a caller needs to write it back safely.
     *
     * @param   DefinitionDraft  $draft  Draft read from the application service.
     *
     * @return  array<string, mixed>  The whole definition under `definition`, beside revision and checksum.
     *
     * @since   2.0.0
     */
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

    /**
     * Render one published version together with the compatibility plan that produced it.
     *
     * @param   DefinitionVersionRecord  $record  Version read from the application service.
     *
     * @return  array<string, mixed>  The whole definition, its lifecycle status and its plan.
     *
     * @since   2.0.0
     */
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

    /**
     * Render a compatibility plan on its own, as the preview endpoint answers it.
     *
     * The plan already knows its own document shape, so this delegates rather than restating it, and the
     * version documents above reuse it so a preview and a publication describe a plan identically.
     *
     * @param   CompatibilityPlan  $plan  Plan analysed between the published head and the draft.
     *
     * @return  array<string, mixed>  Version bounds, checksums, risk flags and the ordered change list.
     *
     * @since   2.0.0
     */
    public function compatibility(CompatibilityPlan $plan): array
    {
        return $plan->toArray();
    }
}
