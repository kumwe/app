<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordReadRepository;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRelationView;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordView;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessRecord\Application\RecordBrowseResult;
use Kumwe\CMS\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\CMS\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\CMS\BusinessRecord\Application\RecordValueCodec;
use Kumwe\CMS\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\CMS\BusinessRecord\Application\StoredRecordIdentity;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessRecord\Query\CursorPosition;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;

final readonly class DoctrineBusinessRecordReadRepository implements BusinessRecordReadRepository
{
    public function __construct(
        private Connection $database,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
        private DoctrineBusinessRecordQueryCompiler $queries,
        private RecordCursorCodec $cursors,
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
        private BusinessRecordMutationFence $fence,
    ) {
    }

    public function identity(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        string $recordId,
        bool $includeDeleted = false,
    ): ?StoredRecordIdentity {
        $table = $this->recordTable($resolved);
        $identityPhysical = $this->identityPhysical($resolved, $table);
        /** @var list<mixed> $parameters */
        $parameters = [$recordId];
        /** @var list<string> $types */
        $types = [$this->physicalType($table, $identityPhysical)];
        $where = [$this->quote($identityPhysical) . ' = ?'];
        $this->scope($table, $scope, $where, $parameters, $types);
        if (!$includeDeleted && $table->column('deleted_at') !== null) {
            $where[] = $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT %s, %s, %s, %s FROM %s WHERE %s',
            $this->quote($this->physical($table, 'record_id')),
            $this->quote($identityPhysical),
            $this->quote($this->physical($table, 'definition_version')),
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            implode(' AND ', $where),
        ), $parameters, $types);
        if ($row === false) {
            return null;
        }

        return new StoredRecordIdentity(
            $this->string($row, $this->physical($table, 'record_id')),
            $this->string($row, $identityPhysical),
            $this->integer($row, $this->physical($table, 'definition_version')),
            $this->integer($row, $this->physical($table, 'version')),
        );
    }

    public function referencing(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        int $limit,
    ): array {
        if ($limit < 1 || $limit > 501) {
            throw new BusinessRecordSchemaUnavailable('An inbound relationship read has an invalid bound.');
        }
        $table = $this->recordTable($resolved);
        $relationshipColumn = $table->column('relation:' . $relationship->handle . '.target_id');
        if ($relationshipColumn === null) {
            return [];
        }
        $query = $this->database->createQueryBuilder();
        $query->select('*')
            ->from($this->quote($table->physicalName))
            ->where($this->quote($relationshipColumn->physicalName) . ' = :target')
            ->setParameter('target', $targetRecordKey, $relationshipColumn->doctrineType)
            ->orderBy($this->quote($this->physical($table, 'record_id')), 'ASC')
            ->setMaxResults($limit);
        foreach (
            [
            'site_identifier' => $scope->siteIdentifier,
            'organization_identifier' => $scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column === null) {
                if ($value !== null) {
                    throw new BusinessRecordSchemaUnavailable('Inbound relationship scope disagrees with storage.');
                }
                continue;
            }
            if ($value === null) {
                throw new BusinessRecordSchemaUnavailable('An inbound relationship requires a missing scope.');
            }
            $query->andWhere($this->quote($column->physicalName) . ' = :' . $logical)
                ->setParameter($logical, $value, $column->doctrineType);
        }
        $records = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $rowResolved = $this->pinnedForRow($resolved, $table, $row);
            $records[] = $this->map($rowResolved, $table, $row);
        }

        return $records;
    }

    public function get(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        string $recordId,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): ?BusinessRecord {
        $table = $this->recordTable($resolved);
        $identityPhysical = $this->identityPhysical($resolved, $table);
        /** @var list<mixed> $parameters */
        $parameters = [$recordId];
        /** @var list<string> $types */
        $types = [$this->physicalType($table, $identityPhysical)];
        $where = [$this->quote($identityPhysical) . ' = ?'];
        $this->scope($table, $scope, $where, $parameters, $types);
        if (!$includeArchived && $table->column('archived_at') !== null) {
            $where[] = $this->quote($this->physical($table, 'archived_at')) . ' IS NULL';
        }
        if (!$includeDeleted && $table->column('deleted_at') !== null) {
            $where[] = $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE %s',
            $this->quote($table->physicalName),
            implode(' AND ', $where),
        ), $parameters, $types);

        return $row === false ? null : $this->map($resolved, $table, $row);
    }

    public function view(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecord $record,
        array $projection = [],
    ): BusinessRecordView {
        $values = $this->publicReferenceValues($resolved, $scope, [$record]);

        return BusinessRecordView::fromRecord(
            $record,
            $projection,
            $resolved->definition,
            $values[$record->recordKey],
        );
    }

    public function browse(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
    ): RecordBrowseResult {
        $compiled = $this->queries->compile($resolved, $scope, $specification);
        $rows = $this->database->executeQuery(
            $compiled->sql,
            $compiled->parameters,
            $compiled->types,
        )->fetchAllAssociative();
        $hasMore = count($rows) > $specification->pageSize;
        if ($hasMore) {
            array_pop($rows);
        }
        $table = $this->recordTable($resolved);
        $requestedProjection = $specification->projection->fields;
        $records = [];
        $rowDefinitions = [];
        foreach ($rows as $row) {
            $rowResolved = $this->pinnedForRow($resolved, $table, $row);
            $record = $this->map($rowResolved, $table, $row);
            $records[] = $record;
            $rowDefinitions[] = $rowResolved->definition;
        }
        $views = [];
        $publicValues = $this->publicReferenceValues($resolved, $scope, $records, $rowDefinitions);
        foreach ($records as $index => $record) {
            $views[] = BusinessRecordView::fromRecord(
                $record,
                $requestedProjection,
                $rowDefinitions[$index],
                $publicValues[$record->recordKey],
            );
        }
        if ($specification->projection->includes !== [] && $records !== []) {
            $included = $this->includes(
                $resolved,
                $scope,
                $records,
                $specification->projection->includes,
                $specification->includeArchived,
                $specification->includeDeleted,
            );
            foreach ($views as $index => $view) {
                $views[$index] = $view->withIncludes($included[$view->recordKey] ?? []);
            }
        }
        $next = null;
        if ($hasMore && $rows !== []) {
            $last = $rows[array_key_last($rows)];
            $sortValues = [];
            foreach ($compiled->cursorColumns as $cursor) {
                $column = $table->physicalColumn($cursor['physical']);
                if ($column === null) {
                    throw new BusinessRecordSchemaUnavailable('A cursor sort column is absent from the schema.');
                }
                try {
                    $value = $this->values->cursorValue($column, $last[$cursor['physical']] ?? null);
                } catch (InvalidArgumentException) {
                    throw new BusinessRecordSchemaUnavailable(
                        'A stored cursor sort value does not match its physical schema type.',
                    );
                }
                $sortValues[] = $value;
            }
            $next = $this->cursors->encode(new CursorPosition(
                $compiled->cursorDigest,
                $sortValues,
                $this->string($last, $this->physical($table, 'record_id')),
            ));
        }

        $aggregates = [];
        if ($compiled->aggregateSql !== null) {
            $row = $this->database->executeQuery(
                $compiled->aggregateSql,
                $compiled->aggregateParameters,
                $compiled->aggregateTypes,
            )->fetchAssociative();
            if ($row !== false) {
                foreach ($row as $alias => $value) {
                    if (is_float($value) || (!is_int($value) && !is_string($value) && $value !== null)) {
                        throw new BusinessRecordSchemaUnavailable('An aggregate produced a non-exact database value.');
                    }
                    $aggregates[$alias] = $value;
                }
            }
        }

        return new RecordBrowseResult($views, $next, $aggregates);
    }

    /**
     * @param list<BusinessRecord> $records
     * @param list<EntityTypeDefinition>|null $pinnedDefinitions
     * @return array<string, array<string, mixed>>
     */
    private function publicReferenceValues(
        ResolvedBusinessDefinition $source,
        RecordScope $scope,
        array $records,
        ?array $pinnedDefinitions = null,
    ): array {
        if ($pinnedDefinitions !== null) {
            /** @var array<int, EntityTypeDefinition> $groupDefinitions */
            $groupDefinitions = [];
            /** @var array<int, list<BusinessRecord>> $groupRecords */
            $groupRecords = [];
            foreach ($records as $index => $record) {
                $definition = $pinnedDefinitions[$index] ?? null;
                if (!$definition instanceof EntityTypeDefinition) {
                    throw new BusinessRecordSchemaUnavailable('A browsed pinned definition is unavailable.');
                }
                $groupDefinitions[$definition->definitionVersion] = $definition;
                $groupRecords[$definition->definitionVersion][] = $record;
            }
            $groupedResult = [];
            foreach ($groupRecords as $version => $versionRecords) {
                $definition = $groupDefinitions[$version];
                $groupResolved = new ResolvedBusinessDefinition($definition, $source->installation);
                $groupedResult = [
                    ...$groupedResult,
                    ...$this->publicReferenceValues($groupResolved, $scope, $versionRecords),
                ];
            }

            return $groupedResult;
        }
        $result = [];
        foreach ($records as $record) {
            $result[$record->recordKey] = $record->values();
        }
        $groups = [];
        foreach ($source->definition->fields() as $field) {
            if ($field->type !== 'core.entity_reference') {
                continue;
            }
            $targetHandle = $field->configuration['target'] ?? null;
            if (!is_string($targetHandle)) {
                throw new BusinessRecordSchemaUnavailable('An entity-reference target is unavailable.');
            }
            $groups[$targetHandle][] = $field->handle;
        }
        foreach ($groups as $targetHandle => $fieldHandles) {
            $keys = [];
            foreach ($records as $record) {
                foreach ($fieldHandles as $fieldHandle) {
                    $value = $record->values()[$fieldHandle] ?? null;
                    if ($value !== null) {
                        if (!is_string($value) || !\Ramsey\Uuid\Uuid::isValid($value)) {
                            throw new BusinessRecordSchemaUnavailable('A stored entity reference is invalid.');
                        }
                        $keys[] = $value;
                    }
                }
            }
            $keys = array_values(array_unique($keys));
            if ($keys === []) {
                continue;
            }
            $target = $this->targetByHandle($source, $targetHandle);
            $table = $this->recordTable($target);
            $identity = $target->definition->identityStrategy === IdentityStrategy::Uuid
                ? $this->physical($table, 'record_id')
                : $this->physical($table, $this->identityHandle($target->definition));
            /** @var list<mixed> $parameters */
            $parameters = [$keys];
            /** @var list<ArrayParameterType> $types */
            $types = [ArrayParameterType::STRING];
            $where = ['t.' . $this->quote($this->physical($table, 'record_id')) . ' IN (?)'];
            $this->qualifiedScope($table, 't', $scope, $where, $parameters, $types);
            $rows = $this->database->executeQuery(sprintf(
                'SELECT t.%s AS record_key, t.%s AS public_id FROM %s t WHERE %s',
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($identity),
                $this->quote($table->physicalName),
                implode(' AND ', $where),
            ), $parameters, $types)->fetchAllAssociative();
            $public = [];
            foreach ($rows as $row) {
                $public[$this->string($row, 'record_key')] = $this->string($row, 'public_id');
            }
            foreach ($records as $record) {
                foreach ($fieldHandles as $fieldHandle) {
                    $key = $record->values()[$fieldHandle] ?? null;
                    if ($key !== null) {
                        if (!is_string($key)) {
                            throw new BusinessRecordSchemaUnavailable('A stored entity reference is invalid.');
                        }
                        $result[$record->recordKey][$fieldHandle] = $public[$key]
                            ?? throw new BusinessRecordSchemaUnavailable(
                                'A stored entity reference has no target in this scope.',
                            );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param list<BusinessRecord> $sources
     * @param list<string> $handles
     * @return array<string, array<string, list<BusinessRecordRelationView>>>
     */
    private function includes(
        ResolvedBusinessDefinition $source,
        RecordScope $scope,
        array $sources,
        array $handles,
        bool $includeArchived,
        bool $includeDeleted,
    ): array {
        $result = [];
        $sourceKeys = [];
        foreach ($sources as $record) {
            $sourceKeys[] = $record->recordKey;
            $result[$record->recordKey] = array_fill_keys($handles, []);
        }
        foreach ($handles as $handle) {
            $relationship = $this->relationship($source->definition, $handle);
            $target = $this->relationshipTarget($source, $relationship);
            [$rows, $targetTable, $ownedLine] = $this->includeRows(
                $source,
                $target,
                $scope,
                $relationship,
                $sourceKeys,
                $includeArchived,
                $includeDeleted,
            );
            foreach ($rows as $row) {
                $sourceKey = $this->string($row, '__source_key');
                if (!isset($result[$sourceKey])) {
                    throw new BusinessRecordSchemaUnavailable('An included relationship escaped its source page.');
                }
                $position = $this->nullableInteger($row['__position'] ?? null);
                if ($ownedLine) {
                    $recordKey = $this->string($row, $this->physical($targetTable, 'line_id'));
                    $values = $this->values->decodeColumns(
                        $target->definition,
                        $targetTable,
                        $row,
                        $target->definition->siteIdentifier,
                        $recordKey,
                    );
                    $values = $this->rules->materialize(
                        $target->definition,
                        $values,
                        $target->definition->siteIdentifier,
                        $recordKey,
                    );
                    $result[$sourceKey][$handle][] = new BusinessRecordRelationView(
                        $target->definition->id,
                        $target->definition->definitionVersion,
                        $recordKey,
                        $this->values->publicIdentity($target->definition, $recordKey, $values),
                        $this->integer($row, $this->physical($targetTable, 'version')),
                        $position,
                        $this->visibleValues($target->definition, $values),
                    );
                    continue;
                }
                $rowResolved = $this->pinnedForRow($target, $targetTable, $row);
                $record = $this->map($rowResolved, $targetTable, $row);
                $view = BusinessRecordView::fromRecord($record, [], $rowResolved->definition);
                $result[$sourceKey][$handle][] = new BusinessRecordRelationView(
                    $view->definitionId,
                    $view->definitionVersion,
                    $view->recordKey,
                    $view->recordId,
                    $view->version,
                    $position,
                    $view->values,
                );
            }
        }

        return $result;
    }

    /**
     * @param list<string> $sourceKeys
     * @return array{list<array<string, mixed>>, PhysicalTableBlueprint, bool}
     */
    private function includeRows(
        ResolvedBusinessDefinition $source,
        ResolvedBusinessDefinition $target,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        array $sourceKeys,
        bool $includeArchived,
        bool $includeDeleted,
    ): array {
        $sourceTable = $this->recordTable($source);
        /** @var list<mixed> $parameters */
        $parameters = [$sourceKeys];
        /** @var list<string|ArrayParameterType> $types */
        $types = [ArrayParameterType::STRING];
        if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
            $targetTable = $source->installation->blueprint->table('line:' . $relationship->handle)
                ?? throw new BusinessRecordSchemaUnavailable('An included owned-line table is unavailable.');
            $alias = 'l';
            $where = [sprintf(
                '%s.%s IN (?)',
                $alias,
                $this->quote($this->physical($targetTable, 'owner_id')),
            )];
            $this->qualifiedScope($targetTable, $alias, $scope, $where, $parameters, $types);
            $rows = $this->database->executeQuery(sprintf(
                'SELECT %s.*, %s.%s AS __source_key, %s.%s AS __position FROM %s %s '
                . 'WHERE %s ORDER BY %s.%s, %s.%s, %s.%s',
                $alias,
                $alias,
                $this->quote($this->physical($targetTable, 'owner_id')),
                $alias,
                $this->quote($this->physical($targetTable, 'position')),
                $this->quote($targetTable->physicalName),
                $alias,
                implode(' AND ', $where),
                $alias,
                $this->quote($this->physical($targetTable, 'owner_id')),
                $alias,
                $this->quote($this->physical($targetTable, 'position')),
                $alias,
                $this->quote($this->physical($targetTable, 'line_id')),
            ), $parameters, $types)->fetchAllAssociative();

            return [$rows, $targetTable, true];
        }

        $targetTable = $this->recordTable($target);
        /** @var list<mixed> $parameters */
        $parameters = [];
        /** @var list<string|ArrayParameterType> $types */
        $types = [];
        $targetAlias = 't';
        $sourceAlias = 's';
        $direct = $sourceTable->column('relation:' . $relationship->handle . '.target_id');
        $junction = $source->installation->blueprint->table('relation:' . $relationship->handle);
        $from = '';
        $sourceExpression = '';
        $positionExpression = 'NULL';
        $where = [];
        if ($direct !== null) {
            $from = sprintf(
                '%s %s INNER JOIN %s %s ON %s.%s = %s.%s',
                $this->quote($targetTable->physicalName),
                $targetAlias,
                $this->quote($sourceTable->physicalName),
                $sourceAlias,
                $sourceAlias,
                $this->quote($direct->physicalName),
                $targetAlias,
                $this->quote($this->physical($targetTable, 'record_id')),
            );
            $sourceExpression = $sourceAlias . '.' . $this->quote($this->physical($sourceTable, 'record_id'));
        } elseif ($junction !== null) {
            $junctionAlias = 'j';
            $from = sprintf(
                '%s %s INNER JOIN %s %s ON %s.%s = %s.%s',
                $this->quote($targetTable->physicalName),
                $targetAlias,
                $this->quote($junction->physicalName),
                $junctionAlias,
                $junctionAlias,
                $this->quote($this->physical($junction, 'target_id')),
                $targetAlias,
                $this->quote($this->physical($targetTable, 'record_id')),
            );
            $sourceExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'source_id'));
            $positionExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'position'));
            $this->qualifiedScope($junction, $junctionAlias, $scope, $where, $parameters, $types);
        } else {
            $inverse = $this->inverseRelationship($source->definition, $target->definition, $relationship);
            $inverseDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
            if ($inverseDirect !== null) {
                $from = $this->quote($targetTable->physicalName) . ' ' . $targetAlias;
                $sourceExpression = $targetAlias . '.' . $this->quote($inverseDirect->physicalName);
            } else {
                $junction = $target->installation->blueprint->table('relation:' . $inverse->handle)
                    ?? throw new BusinessRecordSchemaUnavailable(
                        'An included canonical inverse junction is unavailable.',
                    );
                $junctionAlias = 'j';
                $from = sprintf(
                    '%s %s INNER JOIN %s %s ON %s.%s = %s.%s',
                    $this->quote($targetTable->physicalName),
                    $targetAlias,
                    $this->quote($junction->physicalName),
                    $junctionAlias,
                    $junctionAlias,
                    $this->quote($this->physical($junction, 'source_id')),
                    $targetAlias,
                    $this->quote($this->physical($targetTable, 'record_id')),
                );
                $sourceExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'target_id'));
                $positionExpression = $junctionAlias . '.' . $this->quote($this->physical($junction, 'position'));
                $this->qualifiedScope($junction, $junctionAlias, $scope, $where, $parameters, $types);
            }
        }
        $where[] = $sourceExpression . ' IN (?)';
        $parameters[] = $sourceKeys;
        $types[] = ArrayParameterType::STRING;
        $this->qualifiedScope($targetTable, $targetAlias, $scope, $where, $parameters, $types);
        if (!$includeArchived && $targetTable->column('archived_at') !== null) {
            $where[] = $targetAlias . '.' . $this->quote($this->physical($targetTable, 'archived_at')) . ' IS NULL';
        }
        if (!$includeDeleted && $targetTable->column('deleted_at') !== null) {
            $where[] = $targetAlias . '.' . $this->quote($this->physical($targetTable, 'deleted_at')) . ' IS NULL';
        }
        $rows = $this->database->executeQuery(sprintf(
            'SELECT %s.*, %s AS __source_key, %s AS __position FROM %s WHERE %s '
            . 'ORDER BY __source_key, __position, %s.%s',
            $targetAlias,
            $sourceExpression,
            $positionExpression,
            $from,
            implode(' AND ', $where),
            $targetAlias,
            $this->quote($this->physical($targetTable, 'record_id')),
        ), $parameters, $types)->fetchAllAssociative();

        return [$rows, $targetTable, false];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function visibleValues(EntityTypeDefinition $definition, array $values): array
    {
        $visible = [];
        foreach ($definition->fields() as $field) {
            if (!$field->readVisible || !array_key_exists($field->handle, $values)) {
                continue;
            }
            $visible[$field->handle] = $field->type === 'core.entity_reference'
                || in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
                ? ['redacted' => true]
                : $values[$field->handle];
        }

        return $visible;
    }

    private function relationship(
        EntityTypeDefinition $definition,
        string $handle,
    ): RelationshipDefinition {
        return $definition->runtimeRelationship($handle)
            ?? throw new BusinessRecordSchemaUnavailable(
                'An included relationship is unavailable in the definition.',
            );
    }

    private function inverseRelationship(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): RelationshipDefinition {
        if ($relationship->inverse !== null) {
            foreach ($target->relationships() as $candidate) {
                if ($candidate->handle === $relationship->inverse && $candidate->target === $source->handle) {
                    return $candidate;
                }
            }
        }
        throw new BusinessRecordSchemaUnavailable('An included canonical inverse relationship is unavailable.');
    }

    private function relationshipTarget(
        ResolvedBusinessDefinition $source,
        RelationshipDefinition $relationship,
    ): ResolvedBusinessDefinition {
        return $this->targetByHandle($source, $relationship->target);
    }

    private function targetByHandle(
        ResolvedBusinessDefinition $source,
        string $targetHandle,
    ): ResolvedBusinessDefinition {
        $site = SiteContext::fromString($source->definition->siteIdentifier);
        $generation = $this->fence->shared($site, $targetHandle);
        $entry = $this->definitions->entry($site, $targetHandle);
        if ($entry === null || !$entry->ownerActive) {
            throw new BusinessRecordSchemaUnavailable('An included relationship target is unavailable.');
        }
        $installation = $this->installations->find($entry->id);
        if (
            $installation === null || $installation->status !== SchemaInstallationStatus::Active
            || $installation->siteIdentifier !== $source->definition->siteIdentifier
        ) {
            throw new BusinessRecordSchemaUnavailable('An included relationship target schema is unavailable.');
        }
        $version = SchemaEvolutionHints::fromDefinition($source->definition)->repin($targetHandle)
            ?? $installation->definitionVersion;
        if ($version > $installation->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('An included relationship target is newer than its schema.');
        }
        $published = $this->definitions->published($site, $entry->id, $version);
        if ($published === null) {
            throw new BusinessRecordSchemaUnavailable('An included relationship pinned definition is unavailable.');
        }

        $resolved = new ResolvedBusinessDefinition($published->definition, $installation);
        $generation->assertMatches($resolved);

        return $resolved;
    }

    /**
     * @param list<string> $where
     * @param list<mixed> $parameters
     * @param list<string|ArrayParameterType> $types
     */
    private function qualifiedScope(
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        array &$where,
        array &$parameters,
        array &$types,
    ): void {
        foreach (
            [
            'site_identifier' => $scope->siteIdentifier,
            'organization_identifier' => $scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column === null) {
                if ($value !== null) {
                    throw new BusinessRecordSchemaUnavailable('Included relationship scope disagrees with storage.');
                }
                continue;
            }
            if ($value === null) {
                throw new BusinessRecordSchemaUnavailable('An included relationship requires a missing scope.');
            }
            $where[] = $alias . '.' . $this->quote($column->physicalName) . ' = ?';
            $parameters[] = $value;
            $types[] = $column->doctrineType;
        }
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            throw new BusinessRecordSchemaUnavailable('An included relationship position is invalid.');
        }

        return (int) $value;
    }

    public function ownedLineIdentity(
        ResolvedBusinessDefinition $owner,
        BusinessRecord $ownerRecord,
        RelationshipDefinition $relationship,
        EntityTypeDefinition $lineDefinition,
        string $lineId,
    ): ?StoredRecordIdentity {
        $table = $owner->installation->blueprint->table('line:' . $relationship->handle)
            ?? throw new BusinessRecordSchemaUnavailable('The installed owned-line table is unavailable.');
        $identityPhysical = $lineDefinition->identityStrategy === IdentityStrategy::Uuid
            ? $this->physical($table, 'line_id')
            : $this->physical($table, $this->identityHandle($lineDefinition));
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT %s, %s, %s FROM %s WHERE %s = ? AND %s = ?',
            $this->quote($this->physical($table, 'line_id')),
            $this->quote($identityPhysical),
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, 'owner_id')),
            $this->quote($identityPhysical),
        ), [$ownerRecord->recordKey, $lineId], [
            $this->type($table, 'owner_id'),
            $this->physicalType($table, $identityPhysical),
        ]);
        if ($row === false) {
            return null;
        }

        return new StoredRecordIdentity(
            $this->string($row, $this->physical($table, 'line_id')),
            $this->string($row, $identityPhysical),
            $lineDefinition->definitionVersion,
            $this->integer($row, $this->physical($table, 'version')),
        );
    }

    /** @param array<string, mixed> $row */
    private function map(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        array $row,
    ): BusinessRecord {
        $recordKey = $this->string($row, $this->physical($table, 'record_id'));
        $definitionVersion = $this->integer($row, $this->physical($table, 'definition_version'));
        if ($definitionVersion !== $resolved->definition->definitionVersion) {
            throw new BusinessRecordSchemaUnavailable('A row was decoded with the wrong pinned definition version.');
        }
        $site = $this->optionalString($row, $table, 'site_identifier');
        $organization = $this->optionalString($row, $table, 'organization_identifier');
        $scope = RecordScope::reconstitute($resolved->definition->scope, $site, $organization);
        $values = $this->values->decodeColumns(
            $resolved->definition,
            $table,
            $row,
            $resolved->definition->siteIdentifier,
            $recordKey,
        );
        $values = $this->rules->materialize(
            $resolved->definition,
            $values,
            $resolved->definition->siteIdentifier,
            $recordKey,
        );
        $recordId = $this->values->publicIdentity($resolved->definition, $recordKey, $values);

        return new BusinessRecord(
            $resolved->definition->id,
            $definitionVersion,
            $recordKey,
            $recordId,
            $scope,
            $this->integer($row, $this->physical($table, 'version')),
            $this->optionalString($row, $table, 'workflow_state'),
            $values,
            $this->string($row, $this->physical($table, 'created_by')),
            $this->date($row[$this->physical($table, 'created_at')] ?? null),
            $this->string($row, $this->physical($table, 'updated_by')),
            $this->date($row[$this->physical($table, 'updated_at')] ?? null),
            $this->optionalString($row, $table, 'archived_by'),
            $this->optionalDate($row, $table, 'archived_at'),
            $this->optionalString($row, $table, 'deleted_by'),
            $this->optionalDate($row, $table, 'deleted_at'),
        );
    }

    /**
     * @param list<string> $where
     * @param list<mixed> $parameters
     * @param list<string> $types
     */
    private function scope(
        PhysicalTableBlueprint $table,
        RecordScope $scope,
        array &$where,
        array &$parameters,
        array &$types,
    ): void {
        foreach (
            [
            'site_identifier' => $scope->siteIdentifier,
            'organization_identifier' => $scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column === null) {
                if ($value !== null) {
                    throw new BusinessRecordSchemaUnavailable('Request scope disagrees with installed schema.');
                }
                continue;
            }
            if ($value === null) {
                throw new BusinessRecordSchemaUnavailable('The installed schema requires a missing scope.');
            }
            $where[] = $this->quote($column->physicalName) . ' = ?';
            $parameters[] = $value;
            $types[] = $column->doctrineType;
        }
    }

    /** @param array<string, mixed> $row */
    private function optionalString(
        array $row,
        PhysicalTableBlueprint $table,
        string $logical,
    ): ?string {
        $column = $table->column($logical);
        if ($column === null) {
            return null;
        }
        $value = $row[$column->physicalName] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record string is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function optionalDate(
        array $row,
        PhysicalTableBlueprint $table,
        string $logical,
    ): ?DateTimeImmutable {
        $column = $table->column($logical);
        if ($column === null || ($row[$column->physicalName] ?? null) === null) {
            return null;
        }

        return $this->date($row[$column->physicalName]);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $physical): string
    {
        $value = $row[$physical] ?? null;
        if (!is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record string is invalid.');
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $physical): int
    {
        $value = $row[$physical] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record integer is invalid.');
        }

        return (int) $value;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface || is_string($value)) {
            return new DateTimeImmutable(
                $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value,
                new DateTimeZone('UTC'),
            );
        }
        throw new BusinessRecordSchemaUnavailable('A stored business-record timestamp is invalid.');
    }

    private function recordTable(ResolvedBusinessDefinition $resolved): PhysicalTableBlueprint
    {
        return $resolved->installation->blueprint->table('record')
            ?? throw new BusinessRecordSchemaUnavailable('The installed schema has no record table.');
    }

    /** @param array<string, mixed> $row */
    private function pinnedForRow(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        array $row,
    ): ResolvedBusinessDefinition {
        $version = $this->integer($row, $this->physical($table, 'definition_version'));
        if ($version === $resolved->definition->definitionVersion) {
            return $resolved;
        }
        $published = $this->definitions->published(
            SiteContext::fromString($resolved->definition->siteIdentifier),
            $resolved->definition->id,
            $version,
        );
        if ($published === null) {
            throw new BusinessRecordSchemaUnavailable('A browsed row\'s pinned definition is unavailable.');
        }

        return new ResolvedBusinessDefinition($published->definition, $resolved->installation);
    }

    private function physical(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->physicalName
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column is unavailable.');
    }

    private function type(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->doctrineType
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column type is unavailable.');
    }

    private function identityPhysical(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
    ): string {
        if ($resolved->definition->identityStrategy === IdentityStrategy::Uuid) {
            return $this->physical($table, 'record_id');
        }
        $handle = $table->options['identity_field'] ?? null;
        if (!is_string($handle)) {
            throw new BusinessRecordSchemaUnavailable('A reference identity field is absent from schema metadata.');
        }

        return $this->physical($table, $handle);
    }

    private function identityHandle(EntityTypeDefinition $definition): string
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field->handle;
            }
        }
        throw new BusinessRecordSchemaUnavailable('A line definition identity field is unavailable.');
    }

    private function physicalType(PhysicalTableBlueprint $table, string $physical): string
    {
        foreach ($table->columns() as $column) {
            if ($column->physicalName === $physical) {
                return $column->doctrineType;
            }
        }
        throw new BusinessRecordSchemaUnavailable('A physical identity column is absent from its blueprint.');
    }

    private function quote(string $identifier): string
    {
        return $this->database->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }
}
