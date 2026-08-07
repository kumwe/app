<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\CompatibilityPlan;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionStatus;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;

final readonly class DefinitionVersionRecord
{
    public function __construct(
        public EntityTypeDefinition $definition,
        public CompatibilityPlan $compatibility,
        public DefinitionStatus $status,
        public string $publishedBy,
        public DateTimeImmutable $publishedAt,
    ) {
        if (!hash_equals($definition->checksum(), $compatibility->toChecksum)) {
            throw new InvalidBusinessDefinition('A stored definition version and compatibility plan disagree.');
        }
        if (
            $definition->status !== DefinitionStatus::Published
            || $definition->definitionVersion !== $compatibility->toVersion
        ) {
            throw new InvalidBusinessDefinition('A stored definition version has inconsistent canonical state.');
        }
        if ($status === DefinitionStatus::Draft) {
            throw new InvalidBusinessDefinition('A stored definition version cannot have draft status.');
        }
    }
}
