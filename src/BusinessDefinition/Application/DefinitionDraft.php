<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;

final readonly class DefinitionDraft
{
    public function __construct(
        public EntityTypeDefinition $definition,
        public int $revision,
        public string $checksum,
        public string $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($revision < 1 || !hash_equals($definition->checksum(), $checksum)) {
            throw new InvalidBusinessDefinition('A stored business-definition draft is inconsistent.');
        }
    }
}
