<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessSchema\Domain\SchemaDocument;

final readonly class SchemaExecutionOutcome
{
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

    /** @return array<string, mixed> */
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
