<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;

final readonly class DefinitionCatalogEntry
{
    public function __construct(
        public string $id,
        public string $siteIdentifier,
        public string $handle,
        public DefinitionOwner $owner,
        public bool $ownerActive,
        public int $draftRevision,
        public ?int $publishedVersion,
        public DefinitionStatus $status,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
