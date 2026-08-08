<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;

/** Validates and rewrites pinned records before advancing their immutable definition version. */
interface BusinessSchemaRecordRepinGateway
{
    /** @param array<string, bool|int|string>|null $cursor */
    public function repinChunk(
        EntityTypeDefinition $definition,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult;
}
