<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;

interface BusinessDefinitionRepository
{
    /** @return list<DefinitionCatalogEntry> */
    public function catalog(SiteContext $site): array;

    public function entry(SiteContext $site, string $identifier): ?DefinitionCatalogEntry;

    public function draft(SiteContext $site, string $identifier): ?DefinitionDraft;

    public function published(SiteContext $site, string $identifier, ?int $version = null): ?DefinitionVersionRecord;

    /** @return list<DefinitionVersionRecord> */
    public function history(SiteContext $site, string $identifier): array;

    public function saveDraft(
        EntityTypeDefinition $definition,
        string $actorId,
        DateTimeImmutable $now,
        ?int $expectedRevision,
    ): DefinitionDraft;

    public function publish(
        EntityTypeDefinition $definition,
        CompatibilityPlan $plan,
        string $actorId,
        DateTimeImmutable $now,
        int $expectedDraftRevision,
    ): DefinitionVersionRecord;

    public function changeStatus(
        SiteContext $site,
        string $identifier,
        int $version,
        DefinitionStatus $status,
        DateTimeImmutable $now,
    ): DefinitionVersionRecord;

    public function setOwnerActive(string $ownerIdentifier, bool $active, DateTimeImmutable $now): void;
}
