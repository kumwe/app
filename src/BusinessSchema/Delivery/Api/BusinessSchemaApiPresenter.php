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
 *
 * Presentation is the whole of its job: it holds no state, reaches no store, and decides nothing about
 * a plan, so `BusinessSchemaApiHandler` can map any application value onto a response body without
 * acquiring an opinion of its own.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaApiPresenter
{
    /**
     * Render one schema plan as the document the plan endpoints return.
     *
     * @param   SchemaPlan  $plan  Plan as the application layer loaded or produced it.
     *
     * @return  array<string, mixed>  The persisted plan document with `checksum` added; the digest an
     *          approval must echo back, repeated from the document's own `plan_checksum`.
     *
     * @since   2.0.0
     */
    public function plan(SchemaPlan $plan): array
    {
        return [...$plan->toArray(), 'checksum' => $plan->checksum()];
    }

    /**
     * Render one plan step as an entry of the `steps` collection on a plan document.
     *
     * @param   SchemaPlanStep  $step  Journalled step belonging to the plan being read.
     *
     * @return  array<string, mixed>  The step's persisted fields: its ordinal, operation kind and checksum,
     *          risk, state, attempt, fence, cursor, surrounding schema checksums, and timestamps.
     *
     * @since   2.0.0
     */
    public function step(SchemaPlanStep $step): array
    {
        return $step->toArray();
    }

    /**
     * Render the result of an execution or a recovery as the document those endpoints return.
     *
     * @param   SchemaExecutionOutcome  $outcome  Outcome of a run that reached completion.
     *
     * @return  array<string, mixed>  The outcome's persisted fields, including the counters that separate
     *          steps this run applied from steps an earlier interrupted run had already journalled.
     *
     * @since   2.0.0
     */
    public function outcome(SchemaExecutionOutcome $outcome): array
    {
        return $outcome->toArray();
    }
}
