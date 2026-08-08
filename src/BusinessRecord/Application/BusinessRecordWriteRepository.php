<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;

interface BusinessRecordWriteRepository
{
    public function insert(ResolvedBusinessDefinition $resolved, BusinessRecord $record): void;

    public function update(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void;

    public function hardDelete(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void;

    /** @param array<string, mixed> $ownedLineValues */
    public function relate(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        ?int $position,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
        ?BusinessRecord $target = null,
        ?EntityTypeDefinition $ownedLineDefinition = null,
        array $ownedLineValues = [],
    ): RelationshipWriteResult;

    public function unrelate(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
        ?BusinessRecord $target = null,
    ): RelationshipWriteResult;

    /** @param list<string> $orderedRecordKeys */
    public function reorder(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        array $orderedRecordKeys,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
    ): BusinessRecord;
}
