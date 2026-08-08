<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class SchemaPlan
{
    /** @var list<SchemaOperation> */
    private array $operations;

    /** @var array<string, mixed>|null */
    public ?array $outcome;

    public DateTimeImmutable $updatedAt;

    /**
     * @param list<SchemaOperation> $operations
     * @param array<string, mixed>|null $outcome
     */
    public function __construct(
        public string $id,
        public string $definitionId,
        public string $siteIdentifier,
        public ?int $fromDefinitionVersion,
        public int $toDefinitionVersion,
        public ?string $fromDefinitionChecksum,
        public string $toDefinitionChecksum,
        public ?string $fromSchemaChecksum,
        public string $targetSchemaChecksum,
        array $operations,
        public SchemaRisk $risk,
        public SchemaPlanStatus $status,
        public int $revision,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public ?SchemaPlanApproval $approval = null,
        public ?string $recoveryEvidenceId = null,
        public ?int $executionFence = null,
        ?array $outcome = null,
        ?DateTimeImmutable $updatedAt = null,
    ) {
        SchemaDocument::assertUuid($id, 'The schema-plan ID');
        SchemaDocument::assertUuid($definitionId, 'The schema-plan definition ID');
        SchemaDocument::assertIdentifier($siteIdentifier, 'The schema-plan site');
        if (
            $toDefinitionVersion < 1
            || ($fromDefinitionVersion !== null && $fromDefinitionVersion < 1)
            || ($fromDefinitionVersion !== null && $toDefinitionVersion <= $fromDefinitionVersion)
        ) {
            throw new InvalidBusinessSchema('A schema plan has invalid definition version bounds.');
        }
        if (($fromDefinitionVersion === null) !== ($fromDefinitionChecksum === null)) {
            throw new InvalidBusinessSchema('A schema plan must pair its prior definition version and checksum.');
        }
        SchemaDocument::assertChecksum($fromDefinitionChecksum, 'The prior definition checksum', true);
        SchemaDocument::assertChecksum($toDefinitionChecksum, 'The target definition checksum');
        SchemaDocument::assertChecksum($fromSchemaChecksum, 'The prior physical schema checksum', true);
        SchemaDocument::assertChecksum($targetSchemaChecksum, 'The target physical schema checksum');
        if (count($operations) > 10_000) {
            throw new InvalidBusinessSchema('A schema plan contains too many operations.');
        }
        usort($operations, static fn (SchemaOperation $left, SchemaOperation $right): int =>
            $left->ordinal <=> $right->ordinal);
        foreach ($operations as $offset => $operation) {
            if ($operation->ordinal !== $offset + 1) {
                throw new InvalidBusinessSchema(
                    'Schema-plan operations must have contiguous ordinals starting at one.',
                );
            }
        }
        $calculatedRisk = SchemaRisk::highest(array_map(
            static fn (SchemaOperation $operation): SchemaRisk => $operation->risk,
            $operations,
        ));
        if ($risk !== $calculatedRisk) {
            throw new InvalidBusinessSchema('A schema plan risk must equal its highest operation risk.');
        }
        if ($revision < 1) {
            throw new InvalidBusinessSchema('A schema-plan persistence revision must be positive.');
        }
        SchemaDocument::assertBoundedText($createdBy, 'The schema-plan creator');
        SchemaDocument::assertUuid($recoveryEvidenceId ?? $id, 'The schema-plan recovery-evidence ID');
        if ($executionFence !== null && $executionFence < 1) {
            throw new InvalidBusinessSchema('A schema-plan execution fence must be positive.');
        }
        if ($outcome !== null) {
            SchemaDocument::assertObjectValue($outcome, 'A schema-plan outcome');
            CanonicalDefinitionJson::encode($outcome);
        }
        $this->assertState($status, $approval, $recoveryEvidenceId, $executionFence, $outcome);
        if ($approval !== null && !hash_equals($approval->approvedChecksum, $this->checksumFor($operations))) {
            throw new InvalidBusinessSchema('A schema-plan approval is bound to a different canonical plan.');
        }
        $this->operations = $operations;
        $this->outcome = $outcome;
        $this->updatedAt = $updatedAt ?? $createdAt;
        if ($this->updatedAt < $createdAt) {
            throw new InvalidBusinessSchema('A schema plan cannot be updated before it is created.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'id', 'definition_id', 'site_identifier', 'from_definition_version', 'to_definition_version',
                'from_definition_checksum', 'to_definition_checksum', 'from_schema_checksum',
                'target_schema_checksum', 'operations', 'risk', 'status', 'revision', 'created_by', 'created_at',
                'approval', 'recovery_evidence_id', 'execution_fence', 'outcome', 'updated_at', 'plan_checksum',
            ],
            'A schema plan',
        );
        $risk = SchemaRisk::tryFrom(SchemaDocument::string($document, 'risk'))
            ?? throw new InvalidBusinessSchema('A schema plan risk is invalid.');
        $status = SchemaPlanStatus::tryFrom(SchemaDocument::string($document, 'status'))
            ?? throw new InvalidBusinessSchema('A schema plan status is invalid.');
        $approval = SchemaDocument::object($document, 'approval', true);
        $plan = new self(
            SchemaDocument::string($document, 'id'),
            SchemaDocument::string($document, 'definition_id'),
            SchemaDocument::string($document, 'site_identifier'),
            SchemaDocument::nullableInteger($document, 'from_definition_version'),
            SchemaDocument::integer($document, 'to_definition_version'),
            SchemaDocument::nullableString($document, 'from_definition_checksum'),
            SchemaDocument::string($document, 'to_definition_checksum'),
            SchemaDocument::nullableString($document, 'from_schema_checksum'),
            SchemaDocument::string($document, 'target_schema_checksum'),
            array_map(SchemaOperation::fromArray(...), SchemaDocument::objects($document, 'operations')),
            $risk,
            $status,
            SchemaDocument::integer($document, 'revision'),
            SchemaDocument::string($document, 'created_by'),
            SchemaDocument::date(SchemaDocument::string($document, 'created_at'), 'The schema-plan creation time'),
            $approval === null ? null : SchemaPlanApproval::fromArray($approval),
            SchemaDocument::nullableString($document, 'recovery_evidence_id'),
            SchemaDocument::nullableInteger($document, 'execution_fence'),
            SchemaDocument::object($document, 'outcome', true),
            SchemaDocument::date(SchemaDocument::string($document, 'updated_at'), 'The schema-plan update time'),
        );
        $checksum = $document['plan_checksum'] ?? null;
        if ($checksum !== null && (!is_string($checksum) || !hash_equals($plan->checksum(), $checksum))) {
            throw new InvalidBusinessSchema('A persisted schema-plan checksum does not match its canonical plan.');
        }

        return $plan;
    }

    /** @return list<SchemaOperation> */
    public function operations(): array
    {
        return $this->operations;
    }

    public function approve(
        string $actorIdentifier,
        DateTimeImmutable $approvedAt,
        string $expectedChecksum,
        ?string $confirmationDigest = null,
        ?string $recoveryEvidenceId = null,
    ): self {
        if ($this->status !== SchemaPlanStatus::PendingApproval) {
            throw new InvalidBusinessSchema('Only a pending schema plan can be approved.');
        }
        if (!hash_equals($this->checksum(), $expectedChecksum)) {
            throw new InvalidBusinessSchema('The schema plan changed after it was inspected.');
        }
        if ($this->risk->requiresHighImpactAuthorization() && $confirmationDigest === null) {
            throw new InvalidBusinessSchema('A high-impact schema plan requires a confirmation digest.');
        }
        if ($this->risk->requiresRecoveryEvidence() && $recoveryEvidenceId === null) {
            throw new InvalidBusinessSchema('A locking or destructive schema plan requires recovery evidence.');
        }

        return $this->withState(
            SchemaPlanStatus::Approved,
            $this->revision + 1,
            new SchemaPlanApproval($actorIdentifier, $approvedAt, $expectedChecksum, $confirmationDigest),
            $recoveryEvidenceId,
            null,
            null,
            $approvedAt,
        );
    }

    public function begin(int $fence, DateTimeImmutable $at): self
    {
        if ($this->status !== SchemaPlanStatus::Approved || $fence < 1) {
            throw new InvalidBusinessSchema('Only an approved schema plan can begin under a positive fence.');
        }

        return $this->withState(
            SchemaPlanStatus::Executing,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $fence,
            null,
            $at,
        );
    }

    public function resume(int $fence, DateTimeImmutable $at): self
    {
        if (
            !in_array(
                $this->status,
                [SchemaPlanStatus::Executing, SchemaPlanStatus::Failed, SchemaPlanStatus::RecoveryRequired],
                true,
            ) || $fence < 1
        ) {
            throw new InvalidBusinessSchema('Only an interrupted schema plan can resume under a positive fence.');
        }

        return $this->withState(
            SchemaPlanStatus::Executing,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $fence,
            null,
            $at,
        );
    }

    /** @param array<string, mixed> $outcome */
    public function complete(array $outcome, DateTimeImmutable $at): self
    {
        $this->assertExecuting();

        return $this->withState(
            SchemaPlanStatus::Completed,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            $outcome,
            $at,
        );
    }

    /** @param array<string, mixed> $outcome */
    public function fail(string $errorCode, array $outcome, DateTimeImmutable $at): self
    {
        $this->assertExecuting();
        self::assertErrorCode($errorCode);

        return $this->withState(
            SchemaPlanStatus::Failed,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            [...$outcome, 'error_code' => $errorCode],
            $at,
        );
    }

    /** @param array<string, mixed> $outcome */
    public function recoveryRequired(string $errorCode, array $outcome, DateTimeImmutable $at): self
    {
        $this->assertExecuting();
        self::assertErrorCode($errorCode);

        return $this->withState(
            SchemaPlanStatus::RecoveryRequired,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            [...$outcome, 'error_code' => $errorCode],
            $at,
        );
    }

    /** @param array<string, mixed> $outcome */
    public function compensate(array $outcome, DateTimeImmutable $at): self
    {
        if (!in_array($this->status, [SchemaPlanStatus::Failed, SchemaPlanStatus::RecoveryRequired], true)) {
            throw new InvalidBusinessSchema('Only a failed schema plan can be recorded as compensated.');
        }

        return $this->withState(
            SchemaPlanStatus::Compensated,
            $this->revision + 1,
            $this->approval,
            $this->recoveryEvidenceId,
            $this->executionFence,
            $outcome,
            $at,
        );
    }

    /** @return array<string, mixed> */
    public function canonicalPlan(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'site_identifier' => $this->siteIdentifier,
            'from_definition_version' => $this->fromDefinitionVersion,
            'to_definition_version' => $this->toDefinitionVersion,
            'from_definition_checksum' => $this->fromDefinitionChecksum,
            'to_definition_checksum' => $this->toDefinitionChecksum,
            'from_schema_checksum' => $this->fromSchemaChecksum,
            'target_schema_checksum' => $this->targetSchemaChecksum,
            'operations' => array_map(
                static fn (SchemaOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
            'risk' => $this->risk->value,
        ];
    }

    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->canonicalPlan());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            ...$this->canonicalPlan(),
            'status' => $this->status->value,
            'revision' => $this->revision,
            'created_by' => $this->createdBy,
            'created_at' => SchemaDocument::formatDate($this->createdAt),
            'approval' => $this->approval?->toArray(),
            'recovery_evidence_id' => $this->recoveryEvidenceId,
            'execution_fence' => $this->executionFence,
            'outcome' => $this->outcome,
            'updated_at' => SchemaDocument::formatDate($this->updatedAt),
            'plan_checksum' => $this->checksum(),
        ];
    }

    /** @param list<SchemaOperation> $operations */
    private function checksumFor(array $operations): string
    {
        return CanonicalDefinitionJson::checksum([
            'definition_id' => $this->definitionId,
            'site_identifier' => $this->siteIdentifier,
            'from_definition_version' => $this->fromDefinitionVersion,
            'to_definition_version' => $this->toDefinitionVersion,
            'from_definition_checksum' => $this->fromDefinitionChecksum,
            'to_definition_checksum' => $this->toDefinitionChecksum,
            'from_schema_checksum' => $this->fromSchemaChecksum,
            'target_schema_checksum' => $this->targetSchemaChecksum,
            'operations' => array_map(
                static fn (SchemaOperation $operation): array => $operation->toArray(),
                $operations,
            ),
            'risk' => $this->risk->value,
        ]);
    }

    /** @param array<string, mixed>|null $outcome */
    private function withState(
        SchemaPlanStatus $status,
        int $revision,
        ?SchemaPlanApproval $approval,
        ?string $recoveryEvidenceId,
        ?int $executionFence,
        ?array $outcome,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $this->id,
            $this->definitionId,
            $this->siteIdentifier,
            $this->fromDefinitionVersion,
            $this->toDefinitionVersion,
            $this->fromDefinitionChecksum,
            $this->toDefinitionChecksum,
            $this->fromSchemaChecksum,
            $this->targetSchemaChecksum,
            $this->operations,
            $this->risk,
            $status,
            $revision,
            $this->createdBy,
            $this->createdAt,
            $approval,
            $recoveryEvidenceId,
            $executionFence,
            $outcome,
            $updatedAt,
        );
    }

    /** @param array<string, mixed>|null $outcome */
    private function assertState(
        SchemaPlanStatus $status,
        ?SchemaPlanApproval $approval,
        ?string $recoveryEvidenceId,
        ?int $executionFence,
        ?array $outcome,
    ): void {
        if ($status === SchemaPlanStatus::PendingApproval) {
            if ($approval !== null || $recoveryEvidenceId !== null || $executionFence !== null || $outcome !== null) {
                throw new InvalidBusinessSchema('A pending schema plan cannot contain execution state.');
            }
            return;
        }
        if ($status === SchemaPlanStatus::Cancelled) {
            if ($executionFence !== null) {
                throw new InvalidBusinessSchema('A cancelled schema plan cannot contain an execution fence.');
            }
            return;
        }
        if ($approval === null) {
            throw new InvalidBusinessSchema('An active or completed schema plan requires its approval evidence.');
        }
        if ($this->risk->requiresRecoveryEvidence() && $recoveryEvidenceId === null) {
            throw new InvalidBusinessSchema('A locking or destructive schema plan requires recovery evidence.');
        }
        if ($status === SchemaPlanStatus::Approved && ($executionFence !== null || $outcome !== null)) {
            throw new InvalidBusinessSchema('An approved schema plan cannot contain execution outcome state.');
        }
        if ($status === SchemaPlanStatus::Executing && ($executionFence === null || $outcome !== null)) {
            throw new InvalidBusinessSchema('An executing schema plan requires only a positive execution fence.');
        }
        if (
            in_array(
                $status,
                [
                    SchemaPlanStatus::Completed,
                    SchemaPlanStatus::Failed,
                    SchemaPlanStatus::RecoveryRequired,
                    SchemaPlanStatus::Compensated,
                ],
                true,
            )
            && ($executionFence === null || $outcome === null)
        ) {
            throw new InvalidBusinessSchema('A terminal executed schema plan requires its fence and outcome.');
        }
    }

    private function assertExecuting(): void
    {
        if ($this->status !== SchemaPlanStatus::Executing) {
            throw new InvalidBusinessSchema('A schema plan must be executing to record an execution outcome.');
        }
    }

    private static function assertErrorCode(string $errorCode): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $errorCode) !== 1) {
            throw new InvalidBusinessSchema('A schema execution error code is invalid.');
        }
    }
}
