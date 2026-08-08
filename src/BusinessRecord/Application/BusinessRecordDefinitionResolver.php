<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;

interface BusinessRecordDefinitionResolver
{
    /** @return list<ResolvedBusinessDefinition> */
    public function activeInstalled(ExecutionContext $context): array;

    public function forCreate(ExecutionContext $context, string $identifier): ResolvedBusinessDefinition;

    public function pinned(
        ExecutionContext $context,
        string $identifier,
        int $definitionVersion,
    ): ResolvedBusinessDefinition;

    /** Resolve preserved metadata for authorized history without enabling executable record behavior. */
    public function forHistory(
        ExecutionContext $context,
        string $identifier,
        ?int $definitionVersion = null,
    ): ResolvedBusinessDefinition;
}
