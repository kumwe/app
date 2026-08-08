<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordWriteRepository;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\CMS\BusinessRecord\Application\RecordValueCodec;
use Kumwe\CMS\BusinessRecord\Application\RelationshipWriteResult;
use Kumwe\CMS\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use LogicException;

final readonly class DoctrineBusinessRecordWriteRepository implements BusinessRecordWriteRepository
{
    public function __construct(private Connection $database, private RecordValueCodec $codec)
    {
    }

    public function insert(ResolvedBusinessDefinition $resolved, BusinessRecord $record): void
    {
        $this->assertTransaction();
        $table = $this->recordTable($resolved);
        $values = [
            $this->physical($table, 'record_id') => $record->recordKey,
            $this->physical($table, 'definition_version') => $record->definitionVersion,
            $this->physical($table, 'version') => $record->version,
            $this->physical($table, 'created_by') => $record->createdBy,
            $this->physical($table, 'created_at') => $record->createdAt,
            $this->physical($table, 'updated_by') => $record->updatedBy,
            $this->physical($table, 'updated_at') => $record->updatedAt,
            ...$this->optionalControlValues($table, $record),
            ...$this->codec->encodeColumns($resolved->definition, $table, $record->values()),
        ];
        try {
            $this->database->insert($table->physicalName, $values, $this->types($table, $values));
        } catch (DbalException $exception) {
            $this->map($exception);
        }
    }

    public function update(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void {
        $this->assertTransaction();
        $table = $this->recordTable($resolved);
        $values = [
            $this->physical($table, 'version') => $record->version,
            $this->physical($table, 'updated_by') => $record->updatedBy,
            $this->physical($table, 'updated_at') => $record->updatedAt,
            ...$this->optionalControlValues($table, $record),
            ...$this->codec->encodeColumns($resolved->definition, $table, $record->values()),
        ];
        $this->casUpdate($table, $record->recordKey, $expectedVersion, $values);
    }

    public function hardDelete(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void {
        $this->assertTransaction();
        $table = $this->recordTable($resolved);
        try {
            $affected = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE %s = ? AND %s = ?',
                $this->quote($table->physicalName),
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($this->physical($table, 'version')),
            ), [$record->recordKey, $expectedVersion], [
                $this->type($table, 'record_id'),
                Types::INTEGER,
            ]);
        } catch (DbalException $exception) {
            $this->map($exception);
        }
        if ($affected !== 1) {
            $this->conflict($table, $record->recordKey, $expectedVersion);
        }
    }

    public function relate(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        ?int $position,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
        ?BusinessRecord $target = null,
        ?EntityTypeDefinition $ownedLineDefinition = null,
        array $ownedLineValues = [],
    ): RelationshipWriteResult {
        $this->assertTransaction();
        $recordTable = $this->recordTable($resolved);
        $updated = $source->updated($source->values(), $actorId, $at);
        try {
            $sourceDirect = $recordTable->column('relation:' . $relationship->handle . '.target_id');
            if ($sourceDirect !== null) {
                if ($position !== null || $ownedLineValues !== []) {
                    throw new BusinessRelationshipRejected('A singular relationship does not accept line values.');
                }
                $this->casUpdate($recordTable, $source->recordKey, $expectedVersion, [
                    $sourceDirect->physicalName => $targetRecordKey,
                    $this->physical($recordTable, 'version') => $updated->version,
                    $this->physical($recordTable, 'updated_by') => $actorId,
                    $this->physical($recordTable, 'updated_at') => $at,
                ]);
                return new RelationshipWriteResult($updated);
            }

            $association = $this->associationTableOrNull($resolved, $relationship);
            if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                if ($association === null) {
                    throw new BusinessRecordSchemaUnavailable('The installed owned-line table is unavailable.');
                }
                if ($ownedLineDefinition === null) {
                    throw new BusinessRelationshipRejected('An owned line requires its pinned line definition.');
                }
                $values = [
                    $this->physical($association, 'owner_id') => $source->recordKey,
                    $this->physical($association, 'line_id') => $targetRecordKey,
                    $this->physical($association, 'position') =>
                        $this->position($association, 'owner_id', $source->recordKey, $position),
                    $this->physical($association, 'version') => 1,
                    $this->physical($association, 'created_by') => $actorId,
                    $this->physical($association, 'created_at') => $at,
                    $this->physical($association, 'updated_by') => $actorId,
                    $this->physical($association, 'updated_at') => $at,
                    ...$this->associationScopeValues($association, $source),
                    ...$this->codec->encodeColumns($ownedLineDefinition, $association, $ownedLineValues),
                ];
            } elseif ($association !== null) {
                if ($ownedLineValues !== []) {
                    throw new BusinessRelationshipRejected('Only an owned line accepts embedded target values.');
                }
                $values = [
                    $this->physical($association, 'source_id') => $source->recordKey,
                    $this->physical($association, 'target_id') => $targetRecordKey,
                    $this->physical($association, 'position') => $relationship->ordered
                        ? $this->position($association, 'source_id', $source->recordKey, $position)
                        : null,
                    $this->physical($association, 'version') => 1,
                    $this->physical($association, 'created_by') => $actorId,
                    $this->physical($association, 'created_at') => $at,
                    $this->physical($association, 'updated_by') => $actorId,
                    $this->physical($association, 'updated_at') => $at,
                    ...$this->associationScopeValues($association, $source),
                ];
            } else {
                [$targetResolved, $target, $inverse] = $this->inverseStorage(
                    $resolved,
                    $relationship,
                    $targetResolved,
                    $target,
                    $targetRecordKey,
                );
                $targetTable = $this->recordTable($targetResolved);
                $targetDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
                if ($targetDirect !== null) {
                    if ($position !== null || $ownedLineValues !== []) {
                        throw new BusinessRelationshipRejected('A singular inverse relationship accepts no position.');
                    }
                    $targetUpdated = $target->updated($target->values(), $actorId, $at);
                    $this->casUpdate($targetTable, $target->recordKey, $target->version, [
                        $targetDirect->physicalName => $source->recordKey,
                        $this->physical($targetTable, 'version') => $targetUpdated->version,
                        $this->physical($targetTable, 'updated_by') => $actorId,
                        $this->physical($targetTable, 'updated_at') => $at,
                    ]);
                    $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);

                    return new RelationshipWriteResult($updated, $targetUpdated, $inverse->handle);
                }
                $association = $targetResolved->installation->blueprint->table('relation:' . $inverse->handle)
                    ?? throw new BusinessRecordSchemaUnavailable('The canonical inverse junction is unavailable.');
                if ($ownedLineValues !== [] || ($position !== null && !$inverse->ordered)) {
                    throw new BusinessRelationshipRejected('The inverse junction does not accept these line values.');
                }
                $values = [
                    $this->physical($association, 'source_id') => $target->recordKey,
                    $this->physical($association, 'target_id') => $source->recordKey,
                    $this->physical($association, 'position') => $inverse->ordered
                        ? $this->position($association, 'source_id', $target->recordKey, $position)
                        : null,
                    $this->physical($association, 'version') => 1,
                    $this->physical($association, 'created_by') => $actorId,
                    $this->physical($association, 'created_at') => $at,
                    $this->physical($association, 'updated_by') => $actorId,
                    $this->physical($association, 'updated_at') => $at,
                    ...$this->associationScopeValues($association, $source),
                ];
            }
            $this->database->insert($association->physicalName, $values, $this->types($association, $values));
            $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);
        } catch (DbalException $exception) {
            $this->map($exception, $relationship->handle);
        }

        return new RelationshipWriteResult($updated);
    }

    public function unrelate(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
        ?BusinessRecord $target = null,
    ): RelationshipWriteResult {
        $this->assertTransaction();
        $recordTable = $this->recordTable($resolved);
        $updated = $source->updated($source->values(), $actorId, $at);
        try {
            $sourceDirect = $recordTable->column('relation:' . $relationship->handle . '.target_id');
            if ($sourceDirect !== null) {
                if ($relationship->required) {
                    throw new BusinessRelationshipRejected('A required relationship cannot be removed.');
                }
                $this->casClearRelationship(
                    $recordTable,
                    $source->recordKey,
                    $expectedVersion,
                    $sourceDirect->physicalName,
                    $targetRecordKey,
                    [
                        $this->physical($recordTable, 'version') => $updated->version,
                        $this->physical($recordTable, 'updated_by') => $actorId,
                        $this->physical($recordTable, 'updated_at') => $at,
                    ],
                );
                return new RelationshipWriteResult($updated);
            }

            $association = $this->associationTableOrNull($resolved, $relationship);
            $sourceLogical = $relationship->kind === RelationshipKind::OwnedLineCollection
                ? 'owner_id'
                : 'source_id';
            $targetLogical = $relationship->kind === RelationshipKind::OwnedLineCollection
                ? 'line_id'
                : 'target_id';
            $sourceKey = $source->recordKey;
            $storedTargetKey = $targetRecordKey;
            $targetUpdated = null;
            $targetRelationship = null;
            if ($association === null) {
                [$targetResolved, $target, $inverse] = $this->inverseStorage(
                    $resolved,
                    $relationship,
                    $targetResolved,
                    $target,
                    $targetRecordKey,
                );
                $targetTable = $this->recordTable($targetResolved);
                $targetDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
                if ($targetDirect !== null) {
                    if ($inverse->required) {
                        throw new BusinessRelationshipRejected('A required inverse relationship cannot be removed.');
                    }
                    $targetUpdated = $target->updated($target->values(), $actorId, $at);
                    $this->casClearRelationship(
                        $targetTable,
                        $target->recordKey,
                        $target->version,
                        $targetDirect->physicalName,
                        $source->recordKey,
                        [
                            $this->physical($targetTable, 'version') => $targetUpdated->version,
                            $this->physical($targetTable, 'updated_by') => $actorId,
                            $this->physical($targetTable, 'updated_at') => $at,
                        ],
                    );
                    $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);

                    return new RelationshipWriteResult($updated, $targetUpdated, $inverse->handle);
                }
                $association = $targetResolved->installation->blueprint->table('relation:' . $inverse->handle)
                    ?? throw new BusinessRecordSchemaUnavailable('The canonical inverse junction is unavailable.');
                $sourceLogical = 'source_id';
                $targetLogical = 'target_id';
                $sourceKey = $target->recordKey;
                $storedTargetKey = $source->recordKey;
                $targetRelationship = $inverse->handle;
            }
            $affected = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE %s = ? AND %s = ?',
                $this->quote($association->physicalName),
                $this->quote($this->physical($association, $sourceLogical)),
                $this->quote($this->physical($association, $targetLogical)),
            ), [$sourceKey, $storedTargetKey], [
                $this->type($association, $sourceLogical),
                $this->type($association, $targetLogical),
            ]);
            if ($affected !== 1) {
                throw new BusinessRelationshipRejected('The requested relationship does not exist.');
            }
            $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);
        } catch (DbalException $exception) {
            $this->map($exception, $relationship->handle);
        }

        return new RelationshipWriteResult($updated, $targetUpdated, $targetRelationship);
    }

    public function reorder(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        array $orderedRecordKeys,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
    ): BusinessRecord {
        $this->assertTransaction();
        if (
            !$relationship->ordered || in_array(
                $relationship->kind,
                [RelationshipKind::OneToOne, RelationshipKind::ManyToOne],
                true,
            )
        ) {
            throw new BusinessRelationshipRejected('Only an ordered collection relationship can be reordered.');
        }
        $association = $this->associationTableOrNull($resolved, $relationship);
        if ($association === null) {
            throw new BusinessRelationshipRejected(
                'Source-side ordering is unavailable when an inverse definition owns relationship storage.',
            );
        }
        $sourceLogical = $relationship->kind === RelationshipKind::OwnedLineCollection ? 'owner_id' : 'source_id';
        $targetLogical = $relationship->kind === RelationshipKind::OwnedLineCollection ? 'line_id' : 'target_id';
        $sourceColumn = $this->physical($association, $sourceLogical);
        $targetColumn = $this->physical($association, $targetLogical);
        $positionColumn = $this->physical($association, 'position');
        $rows = $this->database->fetchFirstColumn(sprintf(
            'SELECT %s FROM %s WHERE %s = ? ORDER BY %s, %s',
            $this->quote($targetColumn),
            $this->quote($association->physicalName),
            $this->quote($sourceColumn),
            $this->quote($positionColumn),
            $this->quote($targetColumn),
        ), [$source->recordKey], [$this->type($association, $sourceLogical)]);
        $stored = array_map(static fn (mixed $value): string => (string) $value, $rows);
        $expectedSet = $orderedRecordKeys;
        sort($stored, SORT_STRING);
        sort($expectedSet, SORT_STRING);
        if ($stored !== $expectedSet) {
            throw new BusinessRelationshipRejected('A reorder must contain every current relationship exactly once.');
        }

        try {
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s = -%s - 1 WHERE %s = ?',
                $this->quote($association->physicalName),
                $this->quote($positionColumn),
                $this->quote($positionColumn),
                $this->quote($sourceColumn),
            ), [$source->recordKey], [$this->type($association, $sourceLogical)]);
            foreach ($orderedRecordKeys as $position => $targetRecordKey) {
                $affected = $this->database->executeStatement(sprintf(
                    'UPDATE %s SET %s = ?, %s = %s + 1, %s = ?, %s = ? '
                    . 'WHERE %s = ? AND %s = ?',
                    $this->quote($association->physicalName),
                    $this->quote($positionColumn),
                    $this->quote($this->physical($association, 'version')),
                    $this->quote($this->physical($association, 'version')),
                    $this->quote($this->physical($association, 'updated_by')),
                    $this->quote($this->physical($association, 'updated_at')),
                    $this->quote($sourceColumn),
                    $this->quote($targetColumn),
                ), [$position, $actorId, $at, $source->recordKey, $targetRecordKey], [
                    Types::INTEGER,
                    Types::STRING,
                    Types::DATETIME_IMMUTABLE,
                    $this->type($association, $sourceLogical),
                    $this->type($association, $targetLogical),
                ]);
                if ($affected !== 1) {
                    throw new BusinessRelationshipRejected('A relationship changed while its order was updated.');
                }
            }
            $updated = $source->updated($source->values(), $actorId, $at);
            $this->bump($this->recordTable($resolved), $source->recordKey, $expectedVersion, $updated);
        } catch (DbalException $exception) {
            $this->map($exception, $relationship->handle);
        }

        return $updated;
    }

    /** @return array<string, mixed> */
    private function optionalControlValues(PhysicalTableBlueprint $table, BusinessRecord $record): array
    {
        $logical = [
            'site_identifier' => $record->scope->siteIdentifier,
            'organization_identifier' => $record->scope->organizationIdentifier,
            'workflow_state' => $record->workflowState,
            'archived_by' => $record->archivedBy,
            'archived_at' => $record->archivedAt,
            'deleted_by' => $record->deletedBy,
            'deleted_at' => $record->deletedAt,
        ];
        $values = [];
        foreach ($logical as $handle => $value) {
            $column = $table->column($handle);
            if ($column !== null) {
                $values[$column->physicalName] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, string|null> */
    private function associationScopeValues(PhysicalTableBlueprint $table, BusinessRecord $source): array
    {
        $values = [];
        foreach (
            [
            'site_identifier' => $source->scope->siteIdentifier,
            'organization_identifier' => $source->scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column !== null) {
                $values[$column->physicalName] = $value;
            } elseif ($value !== null) {
                throw new BusinessRecordSchemaUnavailable('Relationship scope disagrees with installed storage.');
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $values */
    private function casUpdate(
        PhysicalTableBlueprint $table,
        string $recordKey,
        int $expectedVersion,
        array $values,
    ): void {
        $assignments = [];
        $parameters = [];
        $types = [];
        $columnTypes = $this->types($table, $values);
        foreach ($values as $column => $value) {
            $assignments[] = $this->quote($column) . ' = ?';
            $parameters[] = $value;
            $types[] = $columnTypes[$column];
        }
        $parameters[] = $recordKey;
        $parameters[] = $expectedVersion;
        $types[] = $this->type($table, 'record_id');
        $types[] = Types::INTEGER;
        try {
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s WHERE %s = ? AND %s = ?',
                $this->quote($table->physicalName),
                implode(', ', $assignments),
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($this->physical($table, 'version')),
            ), $parameters, $types);
        } catch (DbalException $exception) {
            $this->map($exception);
        }
        if ($affected !== 1) {
            $this->conflict($table, $recordKey, $expectedVersion);
        }
    }

    /** @param array<string, mixed> $values */
    private function casClearRelationship(
        PhysicalTableBlueprint $table,
        string $recordKey,
        int $expectedVersion,
        string $relationshipColumn,
        string $expectedTargetKey,
        array $values,
    ): void {
        $assignments = [];
        $parameters = [];
        $types = [];
        $columnTypes = $this->types($table, $values);
        foreach ($values as $column => $value) {
            $assignments[] = $this->quote($column) . ' = ?';
            $parameters[] = $value;
            $types[] = $columnTypes[$column];
        }
        $assignments[] = $this->quote($relationshipColumn) . ' = NULL';
        $relationship = $this->columnByPhysical($table, $relationshipColumn);
        array_push($parameters, $recordKey, $expectedVersion, $expectedTargetKey);
        array_push($types, $this->type($table, 'record_id'), Types::INTEGER, $relationship->doctrineType);
        try {
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s WHERE %s = ? AND %s = ? AND %s = ?',
                $this->quote($table->physicalName),
                implode(', ', $assignments),
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($this->physical($table, 'version')),
                $this->quote($relationshipColumn),
            ), $parameters, $types);
        } catch (DbalException $exception) {
            $this->map($exception);
        }
        if ($affected === 1) {
            return;
        }
        $actual = $this->database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, 'record_id')),
        ), [$recordKey], [$this->type($table, 'record_id')]);
        if ($actual === false || (int) $actual !== $expectedVersion) {
            $this->conflict($table, $recordKey, $expectedVersion);
        }
        throw new BusinessRelationshipRejected('The requested singular relationship does not exist.');
    }

    private function bump(
        PhysicalTableBlueprint $table,
        string $recordKey,
        int $expectedVersion,
        BusinessRecord $updated,
    ): void {
        $this->casUpdate($table, $recordKey, $expectedVersion, [
            $this->physical($table, 'version') => $updated->version,
            $this->physical($table, 'updated_by') => $updated->updatedBy,
            $this->physical($table, 'updated_at') => $updated->updatedAt,
        ]);
    }

    private function position(
        PhysicalTableBlueprint $table,
        string $sourceLogical,
        string $sourceId,
        ?int $requested,
    ): int {
        if ($requested !== null) {
            return $requested;
        }
        $value = $this->database->fetchOne(sprintf(
            'SELECT MAX(%s) FROM %s WHERE %s = ?',
            $this->quote($this->physical($table, 'position')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, $sourceLogical)),
        ), [$sourceId], [$this->type($table, $sourceLogical)]);

        return $value === false || $value === null ? 0 : ((int) $value) + 1;
    }

    private function conflict(PhysicalTableBlueprint $table, string $recordKey, int $expectedVersion): never
    {
        $actual = $this->database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, 'record_id')),
        ), [$recordKey], [$this->type($table, 'record_id')]);
        if ($actual === false) {
            throw new BusinessRecordNotFound();
        }
        throw new BusinessRecordVersionConflict($expectedVersion, (int) $actual);
    }

    private function recordTable(ResolvedBusinessDefinition $resolved): PhysicalTableBlueprint
    {
        return $resolved->installation->blueprint->table('record')
            ?? throw new BusinessRecordSchemaUnavailable('The installed schema has no record table.');
    }

    private function associationTableOrNull(
        ResolvedBusinessDefinition $resolved,
        RelationshipDefinition $relationship,
    ): ?PhysicalTableBlueprint {
        $logical = $relationship->kind === RelationshipKind::OwnedLineCollection
            ? 'line:' . $relationship->handle
            : 'relation:' . $relationship->handle;

        return $resolved->installation->blueprint->table($logical);
    }

    /**
     * @return array{ResolvedBusinessDefinition, BusinessRecord, RelationshipDefinition}
     */
    private function inverseStorage(
        ResolvedBusinessDefinition $sourceResolved,
        RelationshipDefinition $relationship,
        ?ResolvedBusinessDefinition $targetResolved,
        ?BusinessRecord $target,
        string $targetRecordKey,
    ): array {
        if (
            $targetResolved === null || $target === null || $relationship->inverse === null
            || $target->definitionId !== $targetResolved->definition->id
            || !hash_equals($target->recordKey, $targetRecordKey)
        ) {
            throw new BusinessRecordSchemaUnavailable('Canonical inverse relationship storage cannot be resolved.');
        }
        $inverse = null;
        foreach ($targetResolved->definition->relationships() as $candidate) {
            if (
                $candidate->handle === $relationship->inverse
                && $candidate->target === $sourceResolved->definition->handle
            ) {
                $inverse = $candidate;
                break;
            }
        }
        if ($inverse === null) {
            throw new BusinessRecordSchemaUnavailable('The canonical inverse relationship definition is unavailable.');
        }

        return [$targetResolved, $target, $inverse];
    }

    private function physical(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)?->physicalName
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column is unavailable.');
    }

    private function type(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)?->doctrineType
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column type is unavailable.');
    }

    /** @param array<string, mixed> $values @return array<string, string> */
    private function types(PhysicalTableBlueprint $table, array $values): array
    {
        $types = [];
        foreach ($values as $physical => $_value) {
            $column = $this->columnByPhysical($table, $physical);
            $types[$physical] = $column->doctrineType;
        }

        return $types;
    }

    private function columnByPhysical(PhysicalTableBlueprint $table, string $physical): PhysicalColumnBlueprint
    {
        foreach ($table->columns() as $column) {
            if ($column->physicalName === $physical) {
                return $column;
            }
        }
        throw new BusinessRecordSchemaUnavailable('A physical business-record column is not in its blueprint.');
    }

    private function quote(string $identifier): string
    {
        return $this->database->getDatabasePlatform()->quoteIdentifier($identifier);
    }

    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Business-record writes require an active application transaction.');
        }
    }

    private function map(DbalException $exception, ?string $relationship = null): never
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            throw new BusinessRecordUniqueConflict($relationship);
        }
        if ($exception instanceof ForeignKeyConstraintViolationException) {
            throw new BusinessRecordReferenceConflict($relationship);
        }
        if ($exception instanceof RetryableException) {
            throw new BusinessRecordTemporarilyUnavailable($exception);
        }
        throw new BusinessRecordTemporarilyUnavailable($exception);
    }
}
