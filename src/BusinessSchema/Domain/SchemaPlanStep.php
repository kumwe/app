<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class SchemaPlanStep
{
    /** @var array<string, mixed>|null */
    public ?array $cursor;

    /** @var array<string, mixed>|null */
    public ?array $outcome;

    /**
     * @param array<string, mixed>|null $cursor
     * @param array<string, mixed>|null $outcome
     */
    public function __construct(
        public string $planId,
        public int $ordinal,
        public string $operationChecksum,
        public SchemaOperationKind $operationKind,
        public SchemaRisk $risk,
        public SchemaStepStatus $state,
        public int $attempt = 0,
        public ?int $executionFence = null,
        ?array $cursor = null,
        public ?string $beforeSchemaChecksum = null,
        public ?string $afterSchemaChecksum = null,
        ?array $outcome = null,
        public ?string $errorCode = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        SchemaDocument::assertUuid($planId, 'The schema-plan step plan ID');
        if ($ordinal < 1 || $ordinal > 100_000 || $attempt < 0 || $attempt > 1_000) {
            throw new InvalidBusinessSchema('A schema-plan step ordinal or attempt is outside the supported bounds.');
        }
        SchemaDocument::assertChecksum($operationChecksum, 'The schema operation checksum');
        if ($executionFence !== null && $executionFence < 1) {
            throw new InvalidBusinessSchema('A schema-plan step execution fence must be positive.');
        }
        SchemaDocument::assertChecksum($beforeSchemaChecksum, 'The prior step schema checksum', true);
        SchemaDocument::assertChecksum($afterSchemaChecksum, 'The resulting step schema checksum', true);
        if ($errorCode !== null && preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $errorCode) !== 1) {
            throw new InvalidBusinessSchema('A schema-plan step error code is invalid.');
        }
        foreach ([$cursor, $outcome] as $document) {
            if ($document !== null) {
                SchemaDocument::assertObjectValue($document, 'A schema-plan cursor or outcome');
                CanonicalDefinitionJson::encode($document);
            }
        }
        $this->cursor = $cursor;
        $this->outcome = $outcome;
        $this->assertState();
        if ($completedAt !== null && $startedAt !== null && $completedAt < $startedAt) {
            throw new InvalidBusinessSchema('A schema-plan step cannot complete before it starts.');
        }
        if ($updatedAt !== null && $startedAt !== null && $updatedAt < $startedAt) {
            throw new InvalidBusinessSchema('A schema-plan step cannot be updated before it starts.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'plan_id', 'ordinal', 'operation_checksum', 'operation_kind', 'risk', 'state', 'attempt', 'cursor',
                'execution_fence', 'before_schema_checksum', 'after_schema_checksum', 'outcome', 'error_code',
                'started_at',
                'completed_at', 'updated_at',
            ],
            'A schema-plan step',
        );
        $kind = SchemaOperationKind::tryFrom(SchemaDocument::string($document, 'operation_kind'))
            ?? throw new InvalidBusinessSchema('A schema-plan step operation kind is invalid.');
        $risk = SchemaRisk::tryFrom(SchemaDocument::string($document, 'risk'))
            ?? throw new InvalidBusinessSchema('A schema-plan step risk is invalid.');
        $state = SchemaStepStatus::tryFrom(SchemaDocument::string($document, 'state'))
            ?? throw new InvalidBusinessSchema('A schema-plan step state is invalid.');

        return new self(
            SchemaDocument::string($document, 'plan_id'),
            SchemaDocument::integer($document, 'ordinal'),
            SchemaDocument::string($document, 'operation_checksum'),
            $kind,
            $risk,
            $state,
            SchemaDocument::integer($document, 'attempt'),
            SchemaDocument::nullableInteger($document, 'execution_fence'),
            SchemaDocument::object($document, 'cursor', true),
            SchemaDocument::nullableString($document, 'before_schema_checksum'),
            SchemaDocument::nullableString($document, 'after_schema_checksum'),
            SchemaDocument::object($document, 'outcome', true),
            SchemaDocument::nullableString($document, 'error_code'),
            self::optionalDate($document, 'started_at'),
            self::optionalDate($document, 'completed_at'),
            self::optionalDate($document, 'updated_at'),
        );
    }

    public static function pending(
        string $planId,
        SchemaOperation $operation,
        ?DateTimeImmutable $updatedAt = null,
    ): self {
        return new self(
            $planId,
            $operation->ordinal,
            $operation->checksum(),
            $operation->kind,
            $operation->risk,
            SchemaStepStatus::Pending,
            updatedAt: $updatedAt,
        );
    }

    public function start(int $executionFence, string $beforeSchemaChecksum, DateTimeImmutable $at): self
    {
        if (!in_array($this->state, [SchemaStepStatus::Pending, SchemaStepStatus::Failed], true)) {
            throw new InvalidBusinessSchema('Only a pending or failed schema-plan step can start an attempt.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Running,
            $this->attempt + 1,
            $executionFence,
            $this->cursor,
            $beforeSchemaChecksum,
            null,
            null,
            null,
            $at,
            null,
            $at,
        );
    }

    public function resume(int $executionFence, DateTimeImmutable $at): self
    {
        if (!in_array($this->state, [SchemaStepStatus::Running, SchemaStepStatus::Failed], true)) {
            throw new InvalidBusinessSchema('Only an interrupted schema-plan step can resume.');
        }
        if ($this->beforeSchemaChecksum === null) {
            throw new InvalidBusinessSchema('An interrupted schema-plan step has no prior checksum.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Running,
            $this->attempt + 1,
            $executionFence,
            $this->cursor,
            $this->beforeSchemaChecksum,
            null,
            null,
            null,
            $at,
            null,
            $at,
        );
    }

    /** @param array<string, mixed> $cursor */
    public function checkpoint(array $cursor, DateTimeImmutable $at): self
    {
        if ($this->state !== SchemaStepStatus::Running) {
            throw new InvalidBusinessSchema('Only a running schema-plan step can record a cursor checkpoint.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            $this->state,
            $this->attempt,
            $this->executionFence,
            $cursor,
            $this->beforeSchemaChecksum,
            null,
            null,
            null,
            $this->startedAt,
            null,
            $at,
        );
    }

    /** @param array<string, mixed> $outcome */
    public function complete(string $afterSchemaChecksum, array $outcome, DateTimeImmutable $at): self
    {
        if ($this->state !== SchemaStepStatus::Running) {
            throw new InvalidBusinessSchema('Only a running schema-plan step can complete.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Completed,
            $this->attempt,
            $this->executionFence,
            $this->cursor,
            $this->beforeSchemaChecksum,
            $afterSchemaChecksum,
            $outcome,
            null,
            $this->startedAt,
            $at,
            $at,
        );
    }

    /** @param array<string, mixed> $outcome */
    public function fail(string $errorCode, array $outcome, DateTimeImmutable $at): self
    {
        if ($this->state !== SchemaStepStatus::Running) {
            throw new InvalidBusinessSchema('Only a running schema-plan step can fail.');
        }

        return new self(
            $this->planId,
            $this->ordinal,
            $this->operationChecksum,
            $this->operationKind,
            $this->risk,
            SchemaStepStatus::Failed,
            $this->attempt,
            $this->executionFence,
            $this->cursor,
            $this->beforeSchemaChecksum,
            null,
            $outcome,
            $errorCode,
            $this->startedAt,
            $at,
            $at,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'ordinal' => $this->ordinal,
            'operation_checksum' => $this->operationChecksum,
            'operation_kind' => $this->operationKind->value,
            'risk' => $this->risk->value,
            'state' => $this->state->value,
            'attempt' => $this->attempt,
            'execution_fence' => $this->executionFence,
            'cursor' => $this->cursor,
            'before_schema_checksum' => $this->beforeSchemaChecksum,
            'after_schema_checksum' => $this->afterSchemaChecksum,
            'outcome' => $this->outcome,
            'error_code' => $this->errorCode,
            'started_at' => $this->startedAt === null ? null : SchemaDocument::formatDate($this->startedAt),
            'completed_at' => $this->completedAt === null ? null : SchemaDocument::formatDate($this->completedAt),
            'updated_at' => $this->updatedAt === null ? null : SchemaDocument::formatDate($this->updatedAt),
        ];
    }

    private function assertState(): void
    {
        if ($this->state === SchemaStepStatus::Pending) {
            if (
                $this->attempt !== 0 || $this->executionFence !== null || $this->cursor !== null
                || $this->beforeSchemaChecksum !== null
                || $this->afterSchemaChecksum !== null || $this->outcome !== null || $this->errorCode !== null
                || $this->startedAt !== null || $this->completedAt !== null
            ) {
                throw new InvalidBusinessSchema('A pending schema-plan step cannot contain execution state.');
            }
            return;
        }
        if (
            $this->attempt < 1 || $this->executionFence === null
            || $this->startedAt === null || $this->beforeSchemaChecksum === null
        ) {
            throw new InvalidBusinessSchema('An attempted schema-plan step requires its start evidence.');
        }
        if ($this->state === SchemaStepStatus::Running) {
            if (
                $this->completedAt !== null || $this->afterSchemaChecksum !== null
                || $this->outcome !== null || $this->errorCode !== null
            ) {
                throw new InvalidBusinessSchema('A running schema-plan step contains terminal outcome state.');
            }
            return;
        }
        if ($this->completedAt === null) {
            throw new InvalidBusinessSchema('A terminal schema-plan step requires a completion time.');
        }
        if ($this->state === SchemaStepStatus::Completed) {
            if ($this->afterSchemaChecksum === null || $this->outcome === null || $this->errorCode !== null) {
                throw new InvalidBusinessSchema(
                    'A completed schema-plan step requires a resulting checksum and outcome.',
                );
            }
            return;
        }
        if ($this->state === SchemaStepStatus::Failed && ($this->outcome === null || $this->errorCode === null)) {
            throw new InvalidBusinessSchema('A failed schema-plan step requires an error code and outcome.');
        }
    }

    /** @param array<string, mixed> $document */
    private static function optionalDate(array $document, string $key): ?DateTimeImmutable
    {
        $value = SchemaDocument::nullableString($document, $key);

        return $value === null ? null : SchemaDocument::date($value, 'The schema-plan step ' . $key);
    }
}
