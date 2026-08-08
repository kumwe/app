<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;

interface BusinessSchemaPlanRepository
{
    /** @return list<SchemaPlan> */
    public function all(SiteContext $site): array;

    /** @phpstan-impure */
    public function find(SiteContext $site, string $planId): ?SchemaPlan;

    public function latestForDefinition(SiteContext $site, string $definitionId): ?SchemaPlan;

    public function hasUnfinishedExecution(SiteContext $site, string $definitionId): bool;

    public function save(SchemaPlan $plan): void;

    public function replace(SchemaPlan $plan, int $expectedRevision, ?int $expectedFence = null): void;

    /** @return list<SchemaPlanStep> */
    public function steps(string $planId): array;

    public function saveStep(SchemaPlanStep $step): void;

    public function replaceStep(SchemaPlanStep $step, ?int $expectedFence): void;
}
