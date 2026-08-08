<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;

interface PhysicalSchemaGateway
{
    /** Returns the expected logical blueprint projected onto objects that physically exist. */
    public function inspect(PhysicalSchemaBlueprint $expected): ?PhysicalSchemaBlueprint;

    public function operationSatisfied(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
    ): bool;

    public function execute(SchemaOperation $operation, PhysicalSchemaBlueprint $target): void;

    /** Drop a plan-created table only after proving its exact shape and emptiness. */
    public function compensateCreateTable(SchemaOperation $operation): bool;

    public function hasRowsPinnedBefore(PhysicalSchemaBlueprint $installed, int $definitionVersion): bool;

    /** @param array<string, bool|int|string>|null $cursor */
    public function backfillChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult;

    /** @param array<string, bool|int|string>|null $cursor */
    public function transformChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult;

}
