<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Api;

use Kumwe\CMS\BusinessSchema\Application\SchemaExecutionOutcome;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;

/**
 * Renders schema-plan application results as stable REST documents.
 *
 * The canonical plan checksum is surfaced on every plan because approval binds to it: a
 * caller inspects a plan, then approves that exact checksum. Presenting it here is what
 * lets a machine client reproduce the administrator screen's inspect-then-approve flow.
 */
final readonly class BusinessSchemaApiPresenter
{
    /** @return array<string, mixed> */
    public function plan(SchemaPlan $plan): array
    {
        return [...$plan->toArray(), 'checksum' => $plan->checksum()];
    }

    /** @return array<string, mixed> */
    public function step(SchemaPlanStep $step): array
    {
        return $step->toArray();
    }

    /** @return array<string, mixed> */
    public function outcome(SchemaExecutionOutcome $outcome): array
    {
        return $outcome->toArray();
    }
}
