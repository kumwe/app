<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\App\BusinessSchema\Domain\SchemaDocument;

/**
 * Result of one finished business-schema execution, as recorded on the plan and reported to callers.
 *
 * `BusinessSchemaExecutor` builds this only after the last step has been journalled and — for anything
 * but a purge — the resulting physical schema has been inspected and matched against the approved
 * blueprint checksum, so an instance is evidence that a plan ran to completion rather than a progress
 * report. The two counters separate what this attempt applied from what an earlier interrupted attempt
 * had already journalled, which is how an operator tells a clean run from a resumed one. The value is
 * stored verbatim as the plan's `outcome` and is what `BusinessSchemaApiPresenter` returns to a machine
 * client.
 *
 * @since  2.0.0
 */
final readonly class SchemaExecutionOutcome
{
    /**
     * Capture what one execution achieved, refusing identities and counters that cannot be true.
     *
     * @param   string             $planId          UUID of the schema plan this run applied.
     * @param   int                $fence           Durable fence the execution lock issued for this run.
     * @param   int                $completedSteps  Steps this run applied itself.
     * @param   int                $skippedSteps    Steps an earlier interrupted run had already completed.
     * @param   string             $schemaChecksum  Checksum of the schema the run left behind, or the
     *          sentinel purged checksum when the plan dropped the installation.
     * @param   DateTimeImmutable  $completedAt     Instant the final step finished.
     * @param   bool               $resumed         Whether the run continued an interrupted or paused execution.
     *
     * @throws  \InvalidArgumentException  When the fence is below one or either step counter is negative.
     * @throws  \Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema  When the plan ID is not a canonical
     *          UUID or the schema checksum is not a lowercase SHA-256 digest.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $planId,
        public int $fence,
        public int $completedSteps,
        public int $skippedSteps,
        public string $schemaChecksum,
        public DateTimeImmutable $completedAt,
        public bool $resumed,
    ) {
        SchemaDocument::assertUuid($planId, 'The schema execution plan ID');
        SchemaDocument::assertChecksum($schemaChecksum, 'The completed schema checksum');
        if ($fence < 1 || $completedSteps < 0 || $skippedSteps < 0) {
            throw new \InvalidArgumentException('A schema execution outcome contains an invalid counter.');
        }
    }

    /**
     * Export the outcome in the shape persisted on the plan and served by the REST presenter.
     *
     * @return  array<string, mixed>  Keyed `plan_id`, `fence`, `completed_steps`, `skipped_steps`,
     *          `schema_checksum`, `completed_at` and `resumed`, with the instant rendered in the one
     *          fixed-width UTC format every schema document uses.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'fence' => $this->fence,
            'completed_steps' => $this->completedSteps,
            'skipped_steps' => $this->skippedSteps,
            'schema_checksum' => $this->schemaChecksum,
            'completed_at' => SchemaDocument::formatDate($this->completedAt),
            'resumed' => $this->resumed,
        ];
    }
}
