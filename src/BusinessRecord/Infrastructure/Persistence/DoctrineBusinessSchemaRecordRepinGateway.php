<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\CMS\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\CMS\BusinessRecord\Application\RecordValueCodec;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaRecordRepinGateway;
use Kumwe\CMS\BusinessSchema\Application\SchemaChunkResult;
use Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperationKind;

/** Revalidates and rewrites exact typed rows under the schema executor's database fence. */
final readonly class DoctrineBusinessSchemaRecordRepinGateway implements BusinessSchemaRecordRepinGateway
{
    public function __construct(
        private Connection $database,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
    ) {
    }

    public function repinChunk(
        EntityTypeDefinition $definition,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult {
        if ($operation->kind !== SchemaOperationKind::RepinRecords || $limit < 1 || $limit > 1_000) {
            throw new InvalidBusinessSchema('A schema record-repin chunk request is invalid.');
        }
        if ($definition->id !== $target->definitionId
            || $definition->definitionVersion !== $target->definitionVersion
            || !hash_equals($definition->checksum(), $target->definitionChecksum)) {
            throw new InvalidBusinessSchema('A record-repin definition differs from its target blueprint.');
        }
        $table = $target->table($operation->table)
            ?? throw new InvalidBusinessSchema('A record-repin operation has no target table.');
        if (count($table->primaryKey) !== 1) {
            throw new InvalidBusinessSchema('Chunked record repinning requires one canonical identity column.');
        }
        $toVersion = $operation->after['definition_version'] ?? null;
        if (!is_int($toVersion) || $toVersion < 2 || $toVersion !== $definition->definitionVersion) {
            throw new InvalidBusinessSchema('A record-repin target version is invalid.');
        }
        $identity = $this->physicalColumn($table, $table->primaryKey[0]);
        $definitionVersion = $table->column('definition_version')
            ?? throw new InvalidBusinessSchema('A record-repin table has no definition-version column.');
        $recordVersion = $table->column('version')
            ?? throw new InvalidBusinessSchema('A record-repin table has no optimistic-version column.');
        $last = $cursor['last_identity'] ?? null;
        if ($cursor !== null && !is_int($last) && !is_string($last)) {
            throw new InvalidBusinessSchema('A record-repin cursor is invalid.');
        }

        $parameters = [$toVersion];
        $types = [$definitionVersion->doctrineType];
        $cursorPredicate = '';
        if ($last !== null) {
            $cursorPredicate = sprintf(' AND %s > ?', $this->quote($identity->physicalName));
            $parameters[] = $last;
            $types[] = $identity->doctrineType;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE %s < ?%s ORDER BY %s LIMIT %d',
            $this->quote($table->physicalName),
            $this->quote($definitionVersion->physicalName),
            $cursorPredicate,
            $this->quote($identity->physicalName),
            $limit,
        ), $parameters, $types);

        $processed = 0;
        foreach ($rows as $row) {
            $identityValue = $row[$identity->physicalName] ?? null;
            $optimisticVersion = $row[$recordVersion->physicalName] ?? null;
            if ((!is_int($identityValue) && !is_string($identityValue))
                || (!is_int($optimisticVersion)
                    && (!is_string($optimisticVersion)
                        || preg_match('/^[1-9][0-9]*$/D', $optimisticVersion) !== 1))) {
                throw new BusinessSchemaConflict('A record-repin row has invalid concurrency metadata.');
            }
            $recordKey = (string) $identityValue;
            try {
                $decoded = $this->values->decodeColumns(
                    $definition,
                    $table,
                    $row,
                    $definition->siteIdentifier,
                    $recordKey,
                );
                $recordId = $this->values->publicIdentity($definition, $recordKey, $decoded);
                $validated = $this->rules->repin(
                    $definition,
                    $decoded,
                    $definition->siteIdentifier,
                    $recordKey,
                    $recordId,
                );
                $this->assertWorkflowState($definition, $table, $row);
            } catch (BusinessRecordValidationFailed | InvalidBusinessSchema | \InvalidArgumentException $failure) {
                throw new BusinessSchemaConflict(
                    'A pinned record violates the target definition and cannot be repinned.',
                    0,
                    $failure,
                );
            }
            $encoded = $this->values->encodeColumns($definition, $table, $validated);
            $encoded[$definitionVersion->physicalName] = $toVersion;
            ksort($encoded, SORT_STRING);
            $assignments = [];
            $updateValues = [];
            $updateTypes = [];
            foreach ($encoded as $columnName => $value) {
                $column = $this->physicalColumn($table, $columnName);
                $assignments[] = $this->quote($columnName) . ' = ?';
                $updateValues[] = $value;
                $updateTypes[] = $column->doctrineType;
            }
            $updateValues[] = $identityValue;
            $updateTypes[] = $identity->doctrineType;
            $updateValues[] = $toVersion;
            $updateTypes[] = $definitionVersion->doctrineType;
            $updateValues[] = (int) $optimisticVersion;
            $updateTypes[] = $recordVersion->doctrineType;
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s WHERE %s = ? AND %s < ? AND %s = ?',
                $this->quote($table->physicalName),
                implode(', ', $assignments),
                $this->quote($identity->physicalName),
                $this->quote($definitionVersion->physicalName),
                $this->quote($recordVersion->physicalName),
            ), $updateValues, $updateTypes);
            if ($affected !== 1) {
                throw new BusinessSchemaConflict('A record changed concurrently during explicit schema repinning.');
            }
            $last = $identityValue;
            ++$processed;
        }

        return new SchemaChunkResult(
            $last === null ? $cursor : ['last_identity' => $last],
            $processed,
            count($rows) < $limit,
        );
    }

    /** @param array<string, mixed> $row */
    private function assertWorkflowState(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $row,
    ): void {
        $column = $table->column('workflow_state');
        if ($column === null) {
            return;
        }
        $state = $row[$column->physicalName] ?? null;
        if ($state === null && $definition->workflow === null) {
            return;
        }
        if (!is_string($state) || $definition->workflow === null
            || !in_array($state, $definition->workflow->states, true)) {
            throw new InvalidBusinessSchema('A pinned record uses a workflow state absent from the target.');
        }
    }

    private function physicalColumn(
        PhysicalTableBlueprint $table,
        string $physicalName,
    ): PhysicalColumnBlueprint {
        foreach ($table->columns() as $column) {
            if ($column->physicalName === $physicalName) {
                return $column;
            }
        }

        throw new InvalidBusinessSchema('A record-repin physical column is unavailable.');
    }

    private function quote(string $identifier): string
    {
        return $this->database->quoteSingleIdentifier($identifier);
    }
}
