<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaPlanRepository;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineBusinessSchemaPlanRepository implements BusinessSchemaPlanRepository
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    public function all(SiteContext $site): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? ORDER BY created_at DESC, id DESC',
            $this->tables->quoted('business_schema_plans'),
        ), [$site->identifier()]);

        return array_map($this->mapPlan(...), $rows);
    }

    public function find(SiteContext $site, string $planId): ?SchemaPlan
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND id = ?',
            $this->tables->quoted('business_schema_plans'),
        ), [$site->identifier(), $planId]);

        return $row === false ? null : $this->mapPlan($row);
    }

    public function latestForDefinition(SiteContext $site, string $definitionId): ?SchemaPlan
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE site_identifier = ? AND definition_id = ? '
            . 'ORDER BY created_at DESC, id DESC',
            $this->tables->quoted('business_schema_plans'),
        ), [$site->identifier(), $definitionId]);

        return $row === false ? null : $this->mapPlan($row);
    }

    public function hasUnfinishedExecution(SiteContext $site, string $definitionId): bool
    {
        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE site_identifier = ? AND definition_id = ? '
                . 'AND status IN (?, ?, ?) LIMIT 1',
            $this->tables->quoted('business_schema_plans'),
        ), [
            $site->identifier(),
            $definitionId,
            'executing',
            'failed',
            'recovery_required',
        ]) !== false;
    }

    public function save(SchemaPlan $plan): void
    {
        try {
            $this->database->insert(
                $this->tables->raw('business_schema_plans'),
                $this->values($plan),
                $this->types(),
            );
        } catch (UniqueConstraintViolationException $exception) {
            throw new BusinessSchemaConflict('An identical or colliding schema plan already exists.', 0, $exception);
        }
    }

    public function replace(SchemaPlan $plan, int $expectedRevision, ?int $expectedFence = null): void
    {
        if ($plan->revision !== $expectedRevision + 1) {
            throw new BusinessSchemaConflict('A schema plan replacement must advance exactly one revision.');
        }
        $values = $this->values($plan);
        unset(
            $values['id'],
            $values['definition_id'],
            $values['site_identifier'],
            $values['created_by'],
            $values['created_at'],
        );
        $types = $this->types();
        unset(
            $types['id'],
            $types['definition_id'],
            $types['site_identifier'],
            $types['created_by'],
            $types['created_at'],
        );
        $criteria = ['id' => $plan->id, 'site_identifier' => $plan->siteIdentifier, 'revision' => $expectedRevision];
        if ($expectedFence !== null) {
            $criteria['execution_fence'] = $expectedFence;
        } else {
            $currentFence = $this->database->fetchOne(sprintf(
                'SELECT execution_fence FROM %s WHERE id = ? AND site_identifier = ? AND revision = ?',
                $this->tables->quoted('business_schema_plans'),
            ), [$plan->id, $plan->siteIdentifier, $expectedRevision]);
            if ($currentFence !== null) {
                throw new BusinessSchemaConflict('The schema plan execution fence changed concurrently.');
            }
        }
        $affected = $this->database->update(
            $this->tables->raw('business_schema_plans'),
            $values,
            $criteria,
            $types,
        );
        if ($affected !== 1) {
            throw new BusinessSchemaConflict('The schema plan changed concurrently.');
        }
    }

    public function steps(string $planId): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE plan_id = ? ORDER BY ordinal',
            $this->tables->quoted('business_schema_plan_steps'),
        ), [$planId]);

        return array_map(fn (array $row): SchemaPlanStep => SchemaPlanStep::fromArray([
            'plan_id' => $this->string($row, 'plan_id'),
            'ordinal' => $this->integer($row, 'ordinal'),
            'operation_checksum' => $this->string($row, 'operation_checksum'),
            'operation_kind' => $this->string($row, 'operation_kind'),
            'risk' => $this->string($row, 'risk'),
            'state' => $this->string($row, 'state'),
            'attempt' => $this->integer($row, 'attempt'),
            'execution_fence' => $this->nullableInteger($row, 'execution_fence'),
            'cursor' => $this->nullableJsonObject($row['cursor'] ?? null, 'schema step cursor'),
            'before_schema_checksum' => $this->nullableString($row, 'before_schema_checksum'),
            'after_schema_checksum' => $this->nullableString($row, 'after_schema_checksum'),
            'outcome' => $this->nullableJsonObject($row['outcome'] ?? null, 'schema step outcome'),
            'error_code' => $this->nullableString($row, 'error_code'),
            'started_at' => $this->nullableStringValue($row['started_at'] ?? null),
            'completed_at' => $this->nullableStringValue($row['completed_at'] ?? null),
            'updated_at' => $this->nullableStringValue($row['updated_at'] ?? null),
        ]), $rows);
    }

    public function saveStep(SchemaPlanStep $step): void
    {
        $values = [
            'operation_checksum' => $step->operationChecksum,
            'operation_kind' => $step->operationKind->value,
            'risk' => $step->risk->value,
            'state' => $step->state->value,
            'attempt' => $step->attempt,
            'execution_fence' => $step->executionFence,
            'cursor' => $step->cursor,
            'before_schema_checksum' => $step->beforeSchemaChecksum,
            'after_schema_checksum' => $step->afterSchemaChecksum,
            'outcome' => $step->outcome,
            'error_code' => $step->errorCode,
            'started_at' => $step->startedAt,
            'completed_at' => $step->completedAt,
            'updated_at' => $step->updatedAt
                ?? throw new RuntimeException('A persisted schema-plan step requires an update timestamp.'),
        ];
        $types = [
            'attempt' => Types::INTEGER,
            'execution_fence' => Types::BIGINT,
            'cursor' => Types::JSON,
            'outcome' => Types::JSON,
            'started_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $affected = $this->database->update(
            $this->tables->raw('business_schema_plan_steps'),
            $values,
            ['plan_id' => $step->planId, 'ordinal' => $step->ordinal],
            $types,
        );
        if ($affected !== 0) {
            return;
        }
        $exists = $this->database->fetchOne(sprintf(
            'SELECT plan_id FROM %s WHERE plan_id = ? AND ordinal = ?',
            $this->tables->quoted('business_schema_plan_steps'),
        ), [$step->planId, $step->ordinal]);
        if ($exists !== false) {
            return;
        }
        try {
            $this->database->insert($this->tables->raw('business_schema_plan_steps'), [
                'plan_id' => $step->planId,
                'ordinal' => $step->ordinal,
                ...$values,
            ], ['ordinal' => Types::INTEGER, ...$types]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new BusinessSchemaConflict('The schema-plan step changed concurrently.', 0, $exception);
        }
    }

    public function replaceStep(SchemaPlanStep $step, ?int $expectedFence): void
    {
        $values = [
            'operation_checksum' => $step->operationChecksum,
            'operation_kind' => $step->operationKind->value,
            'risk' => $step->risk->value,
            'state' => $step->state->value,
            'attempt' => $step->attempt,
            'execution_fence' => $step->executionFence,
            'cursor' => $step->cursor,
            'before_schema_checksum' => $step->beforeSchemaChecksum,
            'after_schema_checksum' => $step->afterSchemaChecksum,
            'outcome' => $step->outcome,
            'error_code' => $step->errorCode,
            'started_at' => $step->startedAt,
            'completed_at' => $step->completedAt,
            'updated_at' => $step->updatedAt
                ?? throw new RuntimeException('A replaced schema-plan step requires an update timestamp.'),
        ];
        $types = [
            'attempt' => Types::INTEGER,
            'execution_fence' => Types::BIGINT,
            'cursor' => Types::JSON,
            'outcome' => Types::JSON,
            'started_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
        $set = [];
        $parameters = [];
        $parameterTypes = [];
        foreach ($values as $column => $value) {
            $set[] = $column . ' = ?';
            $parameters[] = $value;
            $parameterTypes[] = $types[$column] ?? Types::STRING;
        }
        $parameters[] = $step->planId;
        $parameters[] = $step->ordinal;
        $parameterTypes[] = Types::GUID;
        $parameterTypes[] = Types::INTEGER;
        $fencePredicate = 'execution_fence IS NULL';
        if ($expectedFence !== null) {
            $fencePredicate = 'execution_fence = ?';
            $parameters[] = $expectedFence;
            $parameterTypes[] = Types::BIGINT;
        }
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET %s WHERE plan_id = ? AND ordinal = ? AND %s',
            $this->tables->quoted('business_schema_plan_steps'),
            implode(', ', $set),
            $fencePredicate,
        ), $parameters, $parameterTypes);
        if ($affected !== 1) {
            throw new BusinessSchemaConflict('The schema-plan step fence changed concurrently.');
        }
    }

    /** @return array<string, mixed> */
    private function values(SchemaPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'definition_id' => $plan->definitionId,
            'site_identifier' => $plan->siteIdentifier,
            'from_definition_version' => $plan->fromDefinitionVersion,
            'to_definition_version' => $plan->toDefinitionVersion,
            'from_definition_checksum' => $plan->fromDefinitionChecksum,
            'to_definition_checksum' => $plan->toDefinitionChecksum,
            'from_schema_checksum' => $plan->fromSchemaChecksum,
            'target_schema_checksum' => $plan->targetSchemaChecksum,
            'plan_checksum' => $plan->checksum(),
            'risk' => $plan->risk->value,
            'status' => $plan->status->value,
            'revision' => $plan->revision,
            'canonical_plan' => $plan->toArray(),
            'created_by' => $plan->createdBy,
            'created_at' => $plan->createdAt,
            'approved_by' => $plan->approval?->actorIdentifier,
            'approved_at' => $plan->approval?->approvedAt,
            'approval_checksum' => $plan->approval?->approvedChecksum,
            'confirmation_digest' => $plan->approval?->confirmationDigest,
            'recovery_evidence_id' => $plan->recoveryEvidenceId,
            'execution_fence' => $plan->executionFence,
            'outcome' => $plan->outcome,
            'updated_at' => $plan->updatedAt,
        ];
    }

    /** @return array<string, string> */
    private function types(): array
    {
        return [
            'from_definition_version' => Types::INTEGER,
            'to_definition_version' => Types::INTEGER,
            'revision' => Types::INTEGER,
            'canonical_plan' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'approved_at' => Types::DATETIME_IMMUTABLE,
            'execution_fence' => Types::BIGINT,
            'outcome' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, string $subject): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Stored ' . $subject . ' JSON is invalid.', 0, $exception);
            }
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException('Stored ' . $subject . ' must be a JSON object.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function mapPlan(array $row): SchemaPlan
    {
        $plan = SchemaPlan::fromArray($this->jsonObject($row['canonical_plan'] ?? null, 'schema plan'));
        $stringChecks = [
            'id' => $plan->id,
            'definition_id' => $plan->definitionId,
            'site_identifier' => $plan->siteIdentifier,
            'from_definition_checksum' => $plan->fromDefinitionChecksum,
            'to_definition_checksum' => $plan->toDefinitionChecksum,
            'from_schema_checksum' => $plan->fromSchemaChecksum,
            'target_schema_checksum' => $plan->targetSchemaChecksum,
            'plan_checksum' => $plan->checksum(),
            'risk' => $plan->risk->value,
            'status' => $plan->status->value,
            'created_by' => $plan->createdBy,
            'approved_by' => $plan->approval?->actorIdentifier,
            'approval_checksum' => $plan->approval?->approvedChecksum,
            'confirmation_digest' => $plan->approval?->confirmationDigest,
            'recovery_evidence_id' => $plan->recoveryEvidenceId,
        ];
        foreach ($stringChecks as $column => $expected) {
            $this->assertLedgerString($row, $column, $expected);
        }
        $integerChecks = [
            'from_definition_version' => $plan->fromDefinitionVersion,
            'to_definition_version' => $plan->toDefinitionVersion,
            'revision' => $plan->revision,
            'execution_fence' => $plan->executionFence,
        ];
        foreach ($integerChecks as $column => $expected) {
            $this->assertLedgerInteger($row, $column, $expected);
        }
        $this->assertLedgerDate($row, 'created_at', $plan->createdAt);
        $this->assertLedgerDate($row, 'approved_at', $plan->approval?->approvedAt);
        $this->assertLedgerDate($row, 'updated_at', $plan->updatedAt);
        $this->assertLedgerDocument($row, 'outcome', $plan->outcome);

        return $plan;
    }

    /** @param array<string, mixed> $row */
    private function assertLedgerString(array $row, string $column, ?string $expected): void
    {
        $actual = $row[$column] ?? null;
        if (
            ($expected === null && $actual !== null)
            || ($expected !== null && (!is_string($actual) || !hash_equals($expected, $actual)))
        ) {
            $this->ledgerMismatch($column);
        }
    }

    /** @param array<string, mixed> $row */
    private function assertLedgerInteger(array $row, string $column, ?int $expected): void
    {
        $actual = $this->nullableInteger($row, $column);
        if ($actual !== $expected) {
            $this->ledgerMismatch($column);
        }
    }

    /** @param array<string, mixed> $row */
    private function assertLedgerDate(array $row, string $column, ?DateTimeInterface $expected): void
    {
        $actual = $row[$column] ?? null;
        if ($expected === null) {
            if ($actual !== null) {
                $this->ledgerMismatch($column);
            }

            return;
        }
        if ($actual === null || $this->ledgerDate($actual) !== $this->ledgerDate($expected)) {
            $this->ledgerMismatch($column);
        }
    }

    /** @param array<string, mixed> $row */
    private function assertLedgerDocument(array $row, string $column, ?array $expected): void
    {
        $actual = $row[$column] ?? null;
        if ($expected === null) {
            if ($actual !== null) {
                $this->ledgerMismatch($column);
            }

            return;
        }
        if ($actual === null) {
            $this->ledgerMismatch($column);
        }
        $document = $this->jsonObject($actual, 'schema plan ' . $column);
        if (!hash_equals(CanonicalDefinitionJson::encode($expected), CanonicalDefinitionJson::encode($document))) {
            $this->ledgerMismatch($column);
        }
    }

    private function ledgerDate(mixed $value): string
    {
        try {
            $date = $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable((string) $value);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Stored schema plan contains an invalid ledger timestamp.', 0, $exception);
        }

        // Kumwe's registered temporal types preserve six fractional digits on every supported engine.
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private function ledgerMismatch(string $column): never
    {
        throw new RuntimeException('Stored schema plan disagrees with its ' . $column . ' ledger value.');
    }

    /** @return array<string, mixed>|null */
    private function nullableJsonObject(mixed $value, string $subject): ?array
    {
        return $value === null ? null : $this->jsonObject($value, $subject);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored schema-plan property ' . $key . ' is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && (!is_string($value) || $value === '')) {
            throw new RuntimeException('Stored schema-plan property ' . $key . ' is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }
        throw new RuntimeException('Stored schema-plan property ' . $key . ' is invalid.');
    }

    /** @param array<string, mixed> $row */
    private function nullableInteger(array $row, string $key): ?int
    {
        if (($row[$key] ?? null) === null) {
            return null;
        }

        return $this->integer($row, $key);
    }

    private function nullableStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A stored schema-plan timestamp is invalid.');
        }

        return $value;
    }
}
