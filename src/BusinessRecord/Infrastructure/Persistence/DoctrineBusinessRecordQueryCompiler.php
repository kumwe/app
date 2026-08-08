<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionRepository;
use Kumwe\CMS\BusinessDefinition\Domain\ComputationMode;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\IdentityStrategy;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\CMS\BusinessRecord\Application\RecordCursorCodec;
use Kumwe\CMS\BusinessRecord\Application\RecordValueCodec;
use Kumwe\CMS\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessRecord\Query\AggregateFunction;
use Kumwe\CMS\BusinessRecord\Query\BooleanFilter;
use Kumwe\CMS\BusinessRecord\Query\BooleanOperator;
use Kumwe\CMS\BusinessRecord\Query\ComparisonFilter;
use Kumwe\CMS\BusinessRecord\Query\ComparisonOperator;
use Kumwe\CMS\BusinessRecord\Query\NullFilter;
use Kumwe\CMS\BusinessRecord\Query\RecordAggregate;
use Kumwe\CMS\BusinessRecord\Query\RecordFilter;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\CMS\BusinessRecord\Query\RecordSort;
use Kumwe\CMS\BusinessRecord\Query\RelationFilter;
use Kumwe\CMS\BusinessRecord\Query\RelationQuantifier;
use Kumwe\CMS\BusinessRecord\Query\SetFilter;
use Kumwe\CMS\BusinessRecord\Query\SortDirection;
use Kumwe\CMS\BusinessRecord\Query\TextFilter;
use Kumwe\CMS\BusinessRecord\Query\TextOperator;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaEvolutionHints;
use Kumwe\CMS\BusinessSchema\Domain\SchemaInstallationStatus;
use Ramsey\Uuid\Uuid;

/** Compiles only metadata-validated identifiers and always binds caller-supplied values. */
final readonly class DoctrineBusinessRecordQueryCompiler
{
    public function __construct(
        private Connection $database,
        private BusinessDefinitionRepository $definitions,
        private BusinessSchemaInstallationRepository $installations,
        private RecordValueCodec $values,
        private RecordCursorCodec $cursors,
        private BusinessRecordMutationFence $fence,
    ) {
    }

    public function compile(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
    ): CompiledRecordQuery {
        try {
            return $this->doCompile($resolved, $scope, $specification);
        } catch (InvalidBusinessRecordQuery $exception) {
            throw $exception;
        } catch (InvalidArgumentException $exception) {
            throw new InvalidBusinessRecordQuery($exception->getMessage());
        }
    }

    private function doCompile(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
    ): CompiledRecordQuery {
        $table = $this->recordTable($resolved);
        $alias = 'r0';
        $parameters = [];
        $types = [];
        $where = $this->scopePredicates($table, $alias, $scope, $parameters, $types);
        $this->lifecyclePredicates($table, $alias, $specification, $where);
        $counter = 0;
        if ($specification->filter !== null) {
            $where[] = $this->filter(
                $resolved,
                $table,
                $alias,
                $scope,
                $specification->filter,
                $parameters,
                $types,
                $counter,
            );
        }
        if ($specification->search !== null) {
            $search = [];
            foreach ($specification->search->fields as $handle) {
                $field = $this->field($resolved->definition, $handle);
                if (!$field->searchable || !$this->queryVisible($field)) {
                    throw new InvalidBusinessRecordQuery('A requested search field is not searchable.');
                }
                $columns = $this->fieldColumns($resolved->definition, $table, $field);
                if (
                    count($columns) !== 1
                    || !in_array($columns[0]->doctrineType, ['string', 'text', 'ascii_string'], true)
                ) {
                    throw new InvalidBusinessRecordQuery('Search requires a single textual physical field.');
                }
                $search[] = sprintf(
                    'LOWER(%s.%s) LIKE ? ESCAPE \'!\'',
                    $alias,
                    $this->quote($columns[0]->physicalName),
                );
                $parameters[] = '%' . $this->escapeLike(mb_strtolower($specification->search->term)) . '%';
                $types[] = Types::STRING;
            }
            $where[] = '(' . implode(' OR ', $search) . ')';
        }
        $aggregateWhere = $where;
        $aggregateParameters = $parameters;
        $aggregateTypes = $types;

        [$order, $cursorColumns] = $this->sorts($resolved, $table, $alias, $specification);
        $cursorDigest = $this->cursorDigest($resolved, $scope, $specification);
        if ($specification->after !== null) {
            $position = $this->cursors->decode($specification->after);
            if (!hash_equals($cursorDigest, $position->specificationDigest)) {
                throw new InvalidBusinessRecordQuery('The cursor belongs to a different query specification.');
            }
            $where[] = $this->cursorPredicate(
                $resolved,
                $table,
                $alias,
                $specification,
                $position->sortValues,
                $position->recordKey,
                $parameters,
                $types,
            );
        }

        $projection = $this->projection($resolved->definition, $table, $specification);
        $select = $this->selectedPhysicalColumns($resolved->definition, $table, $projection);
        foreach ($cursorColumns as $cursorColumn) {
            $select[] = $cursorColumn['physical'];
        }
        $select = array_values(array_unique($select));
        $sql = sprintf(
            'SELECT %s FROM %s %s WHERE %s ORDER BY %s LIMIT %d',
            implode(', ', array_map(
                fn (string $physical): string => $alias . '.' . $this->quote($physical),
                $select,
            )),
            $this->quote($table->physicalName),
            $alias,
            implode(' AND ', $where),
            implode(', ', $order),
            $specification->pageSize + 1,
        );

        $aggregateSql = $this->aggregateSql(
            $resolved,
            $table,
            $alias,
            $specification->projection->aggregates,
            $aggregateWhere,
        );

        return new CompiledRecordQuery(
            $sql,
            $cursorDigest,
            $parameters,
            $types,
            $projection,
            $cursorColumns,
            $aggregateSql,
            $aggregateParameters,
            $aggregateTypes,
        );
    }

    /**
     * @param list<mixed> $parameters
     * @param list<string> $types
     * @return list<string>
     */
    private function scopePredicates(
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        array &$parameters,
        array &$types,
    ): array {
        $where = [];
        foreach (
            [
            'site_identifier' => $scope->siteIdentifier,
            'organization_identifier' => $scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column === null) {
                if ($value !== null) {
                    throw new InvalidBusinessRecordQuery('The requested scope is absent from the installed schema.');
                }
                continue;
            }
            if ($value === null) {
                throw new InvalidBusinessRecordQuery('The installed schema requires a missing request scope.');
            }
            $where[] = $alias . '.' . $this->quote($column->physicalName) . ' = ?';
            $parameters[] = $value;
            $types[] = $column->doctrineType;
        }

        return $where;
    }

    /** @param list<string> $where */
    private function lifecyclePredicates(
        PhysicalTableBlueprint $table,
        string $alias,
        RecordQuerySpecification $specification,
        array &$where,
    ): void {
        if (!$specification->includeArchived && $table->column('archived_at') !== null) {
            $where[] = $alias . '.' . $this->quote($this->physical($table, 'archived_at')) . ' IS NULL';
        }
        if (!$specification->includeDeleted && $table->column('deleted_at') !== null) {
            $where[] = $alias . '.' . $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        if ($where === []) {
            $where[] = '1 = 1';
        }
    }

    /**
     * @param list<mixed> $parameters
     * @param list<string> $types
     */
    private function filter(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        RecordFilter $filter,
        array &$parameters,
        array &$types,
        int &$counter,
    ): string {
        ++$counter;
        if ($counter > 64) {
            throw new InvalidBusinessRecordQuery('A query exceeds 64 compiled filter operations.');
        }
        if ($filter instanceof ComparisonFilter) {
            return $this->comparison($resolved, $table, $alias, $scope, $filter, $parameters, $types);
        }
        if ($filter instanceof SetFilter) {
            $field = $this->filterable($resolved->definition, $filter->field);
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            if (count($columns) !== 1) {
                throw new InvalidBusinessRecordQuery('A set predicate requires a single physical field.');
            }
            if (!$this->equalityComparable($columns[0])) {
                throw new InvalidBusinessRecordQuery(
                    'A set predicate requires a portably equality-comparable scalar field.',
                );
            }
            $placeholders = [];
            foreach ($filter->values as $value) {
                $encoded = $this->queryColumns($resolved, $table, $field, $value, $scope);
                $parameters[] = array_values($encoded)[0];
                $types[] = $columns[0]->doctrineType;
                $placeholders[] = '?';
            }
            $predicate = sprintf(
                '%s.%s IN (%s)',
                $alias,
                $this->quote($columns[0]->physicalName),
                implode(', ', $placeholders),
            );

            return $filter->negated ? $this->notTrue($predicate) : $predicate;
        }
        if ($filter instanceof NullFilter) {
            $field = $this->filterable($resolved->definition, $filter->field);
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            $parts = array_map(
                fn (PhysicalColumnBlueprint $column): string => sprintf(
                    '%s.%s IS %sNULL',
                    $alias,
                    $this->quote($column->physicalName),
                    $filter->isNull ? '' : 'NOT ',
                ),
                $columns,
            );
            return '(' . implode(' AND ', $parts) . ')';
        }
        if ($filter instanceof TextFilter) {
            $field = $this->filterable($resolved->definition, $filter->field);
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            if (
                count($columns) !== 1
                || !in_array($columns[0]->doctrineType, ['string', 'text', 'ascii_string'], true)
            ) {
                throw new InvalidBusinessRecordQuery('A text predicate requires a single textual field.');
            }
            $text = $this->escapeLike(mb_strtolower($filter->text));
            $parameters[] = match ($filter->operator) {
                TextOperator::Contains => '%' . $text . '%',
                TextOperator::StartsWith => $text . '%',
                TextOperator::EndsWith => '%' . $text,
            };
            $types[] = Types::STRING;
            return sprintf(
                'LOWER(%s.%s) LIKE ? ESCAPE \'!\'',
                $alias,
                $this->quote($columns[0]->physicalName),
            );
        }
        if ($filter instanceof BooleanFilter) {
            $parts = [];
            foreach ($filter->children as $child) {
                $parts[] = $this->filter(
                    $resolved,
                    $table,
                    $alias,
                    $scope,
                    $child,
                    $parameters,
                    $types,
                    $counter,
                );
            }
            if ($filter->operator === BooleanOperator::Not) {
                return '(' . $this->notTrue($parts[0]) . ')';
            }
            return '(' . implode($filter->operator === BooleanOperator::All ? ' AND ' : ' OR ', $parts) . ')';
        }
        if ($filter instanceof RelationFilter) {
            return $this->relation(
                $resolved,
                $table,
                $alias,
                $scope,
                $filter,
                $parameters,
                $types,
                $counter,
            );
        }

        throw new InvalidBusinessRecordQuery('A business-record filter node is unsupported.');
    }

    /** @param list<mixed> $parameters @param list<string> $types */
    private function comparison(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordScope $scope,
        ComparisonFilter $filter,
        array &$parameters,
        array &$types,
    ): string {
        $field = $this->filterable($resolved->definition, $filter->field);
        $columns = $this->fieldColumns($resolved->definition, $table, $field);
        $encoded = $this->queryColumns($resolved, $table, $field, $filter->value, $scope);
        $operator = match ($filter->operator) {
            ComparisonOperator::Equal => '=',
            ComparisonOperator::NotEqual => '<>',
            ComparisonOperator::LessThan => '<',
            ComparisonOperator::LessThanOrEqual => '<=',
            ComparisonOperator::GreaterThan => '>',
            ComparisonOperator::GreaterThanOrEqual => '>=',
        };
        if (
            count($columns) > 1 && !in_array(
                $filter->operator,
                [ComparisonOperator::Equal, ComparisonOperator::NotEqual],
                true,
            )
        ) {
            throw new InvalidBusinessRecordQuery('Ordered comparison is ambiguous for a composite field.');
        }
        $comparable = in_array($filter->operator, [ComparisonOperator::Equal, ComparisonOperator::NotEqual], true)
            ? $this->allEqualityComparable($columns)
            : count($columns) === 1 && $this->orderedComparable($columns[0]);
        if (!$comparable) {
            throw new InvalidBusinessRecordQuery(
                'A comparison requires physical fields with portable operator semantics.',
            );
        }
        $parts = [];
        foreach ($columns as $column) {
            $predicate = sprintf('%s.%s %s ?', $alias, $this->quote($column->physicalName), $operator);
            $parts[] = $filter->operator === ComparisonOperator::NotEqual
                ? $this->notTrue(sprintf('%s.%s = ?', $alias, $this->quote($column->physicalName)))
                : $predicate;
            $parameters[] = $encoded[$column->physicalName];
            $types[] = $column->doctrineType;
        }
        $join = $filter->operator === ComparisonOperator::NotEqual ? ' OR ' : ' AND ';

        return '(' . implode($join, $parts) . ')';
    }

    private function equalityComparable(PhysicalColumnBlueprint $column): bool
    {
        return in_array($column->doctrineType, [
            'ascii_string', 'bigint', 'boolean', 'date_immutable', 'datetime_immutable', 'datetimetz_immutable',
            'decimal', 'guid', 'integer', 'smallint', 'string', 'text', 'time_immutable',
        ], true);
    }

    /** @param list<PhysicalColumnBlueprint> $columns */
    private function allEqualityComparable(array $columns): bool
    {
        if ($columns === []) {
            return false;
        }
        foreach ($columns as $column) {
            if (!$this->equalityComparable($column)) {
                return false;
            }
        }

        return true;
    }

    private function orderedComparable(PhysicalColumnBlueprint $column): bool
    {
        return in_array($column->doctrineType, [
            'ascii_string', 'bigint', 'date_immutable', 'datetime_immutable', 'datetimetz_immutable',
            'decimal', 'integer', 'smallint', 'string', 'text', 'time_immutable',
        ], true);
    }

    /**
     * @param list<mixed> $parameters
     * @param list<string> $types
     */
    private function relation(
        ResolvedBusinessDefinition $source,
        PhysicalTableBlueprint $sourceTable,
        string $sourceAlias,
        RecordScope $scope,
        RelationFilter $filter,
        array &$parameters,
        array &$types,
        int &$counter,
    ): string {
        $relationship = $this->relationship($source->definition, $filter->relationship);
        $target = $this->target($source->definition, $relationship);
        $number = ++$counter;
        $targetAlias = 'r' . $number;
        if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
            $targetTable = $source->installation->blueprint->table('line:' . $relationship->handle)
                ?? throw new InvalidBusinessRecordQuery('The requested owned-line table is unavailable.');
            $join = sprintf(
                '%s.%s = %s.%s',
                $targetAlias,
                $this->quote($this->physical($targetTable, 'owner_id')),
                $sourceAlias,
                $this->quote($this->physical($sourceTable, 'record_id')),
            );
        } else {
            $targetTable = $this->recordTable($target);
            $direct = $sourceTable->column('relation:' . $relationship->handle . '.target_id');
            $junction = $source->installation->blueprint->table('relation:' . $relationship->handle);
            if ($direct !== null) {
                $join = sprintf(
                    '%s.%s = %s.%s',
                    $targetAlias,
                    $this->quote($this->physical($targetTable, 'record_id')),
                    $sourceAlias,
                    $this->quote($direct->physicalName),
                );
            } elseif ($junction !== null) {
                $junctionAlias = 'j' . $number;
                $junctionWhere = [sprintf(
                    '%s.%s = %s.%s',
                    $junctionAlias,
                    $this->quote($this->physical($junction, 'source_id')),
                    $sourceAlias,
                    $this->quote($this->physical($sourceTable, 'record_id')),
                ), sprintf(
                    '%s.%s = %s.%s',
                    $junctionAlias,
                    $this->quote($this->physical($junction, 'target_id')),
                    $targetAlias,
                    $this->quote($this->physical($targetTable, 'record_id')),
                ), ...$this->scopePredicates($junction, $junctionAlias, $scope, $parameters, $types)];
                $join = sprintf(
                    'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
                    $this->quote($junction->physicalName),
                    $junctionAlias,
                    implode(' AND ', $junctionWhere),
                );
            } else {
                $inverse = $this->inverseRelationship($source->definition, $target->definition, $relationship);
                $inverseDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
                if ($inverseDirect !== null) {
                    $join = sprintf(
                        '%s.%s = %s.%s',
                        $targetAlias,
                        $this->quote($inverseDirect->physicalName),
                        $sourceAlias,
                        $this->quote($this->physical($sourceTable, 'record_id')),
                    );
                } else {
                    $junction = $target->installation->blueprint->table('relation:' . $inverse->handle)
                        ?? throw new InvalidBusinessRecordQuery('The canonical inverse junction is unavailable.');
                    $junctionAlias = 'j' . $number;
                    $junctionWhere = [sprintf(
                        '%s.%s = %s.%s',
                        $junctionAlias,
                        $this->quote($this->physical($junction, 'source_id')),
                        $targetAlias,
                        $this->quote($this->physical($targetTable, 'record_id')),
                    ), sprintf(
                        '%s.%s = %s.%s',
                        $junctionAlias,
                        $this->quote($this->physical($junction, 'target_id')),
                        $sourceAlias,
                        $this->quote($this->physical($sourceTable, 'record_id')),
                    ), ...$this->scopePredicates($junction, $junctionAlias, $scope, $parameters, $types)];
                    $join = sprintf(
                        'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
                        $this->quote($junction->physicalName),
                        $junctionAlias,
                        implode(' AND ', $junctionWhere),
                    );
                }
            }
        }

        $targetWhere = [$join];
        if ($targetTable->column('deleted_at') !== null) {
            $targetWhere[] = $targetAlias . '.'
                . $this->quote($this->physical($targetTable, 'deleted_at')) . ' IS NULL';
        }
        if ($relationship->kind !== RelationshipKind::OwnedLineCollection) {
            array_push(
                $targetWhere,
                ...$this->scopePredicates($targetTable, $targetAlias, $scope, $parameters, $types),
            );
        }
        $targetPredicate = $this->filter(
            $target,
            $targetTable,
            $targetAlias,
            $scope,
            $filter->target,
            $parameters,
            $types,
            $counter,
        );
        $exists = fn (string $predicate): string => sprintf(
            'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
            $this->quote($targetTable->physicalName),
            $targetAlias,
            implode(' AND ', [...$targetWhere, $predicate]),
        );
        if ($filter->quantifier === RelationQuantifier::None) {
            return 'NOT (' . $exists($targetPredicate) . ')';
        }
        if ($filter->quantifier === RelationQuantifier::All) {
            return 'NOT (' . $exists($this->notTrue($targetPredicate)) . ')';
        }

        return $exists($targetPredicate);
    }

    private function notTrue(string $predicate): string
    {
        return 'CASE WHEN (' . $predicate . ') THEN 0 ELSE 1 END = 1';
    }

    /** @return array{list<string>, list<array{field: ?string, physical: string}>} */
    private function sorts(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordQuerySpecification $specification,
    ): array {
        $order = [];
        $cursor = [];
        if ($specification->sorts === []) {
            $updated = $this->physical($table, 'updated_at');
            $order[] = $alias . '.' . $this->quote($updated) . ' DESC';
            $cursor[] = ['field' => null, 'physical' => $updated];
        } else {
            foreach ($specification->sorts as $sort) {
                $field = $this->field($resolved->definition, $sort->field);
                if (!$field->sortable || !$this->queryVisible($field)) {
                    throw new InvalidBusinessRecordQuery('A requested sort field is not sortable.');
                }
                $columns = $this->fieldColumns($resolved->definition, $table, $field);
                if (count($columns) !== 1) {
                    throw new InvalidBusinessRecordQuery('Sorting a composite field is ambiguous.');
                }
                if (in_array($columns[0]->doctrineType, ['binary', 'blob', 'json', 'text'], true)) {
                    throw new InvalidBusinessRecordQuery(
                        'Sorting requires a scalar physical field with portable keyset semantics.',
                    );
                }
                $physical = $columns[0]->physicalName;
                $qualified = $alias . '.' . $this->quote($physical);
                $nullRank = $sort->nullsLast ? '1' : '0';
                $nonNullRank = $sort->nullsLast ? '0' : '1';
                $order[] = sprintf('CASE WHEN %s IS NULL THEN %s ELSE %s END ASC', $qualified, $nullRank, $nonNullRank);
                $order[] = $qualified . ' ' . strtoupper($sort->direction->value);
                $cursor[] = ['field' => $field->handle, 'physical' => $physical];
            }
        }
        $order[] = $alias . '.' . $this->quote($this->physical($table, 'record_id')) . ' ASC';

        return [$order, $cursor];
    }

    /** @param list<mixed> $cursorValues @param list<mixed> $parameters @param list<string> $types */
    private function cursorPredicate(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        RecordQuerySpecification $specification,
        array $cursorValues,
        string $recordKey,
        array &$parameters,
        array &$types,
    ): string {
        $sorts = $specification->sorts;
        if (count($cursorValues) !== max(1, count($sorts))) {
            throw new InvalidBusinessRecordQuery('The cursor sort-value count does not match the query.');
        }
        $keys = [];
        if ($sorts === []) {
            $value = $cursorValues[0];
            if (!is_string($value)) {
                throw new InvalidBusinessRecordQuery('The default cursor timestamp is invalid.');
            }
            $keys[] = [
                'column' => $this->physical($table, 'updated_at'),
                'type' => Types::DATETIME_IMMUTABLE,
                'value' => new DateTimeImmutable($value, new DateTimeZone('UTC')),
                'direction' => SortDirection::Descending,
                'nulls_last' => true,
            ];
        } else {
            foreach ($sorts as $index => $sort) {
                $field = $this->field($resolved->definition, $sort->field);
                $columns = $this->fieldColumns($resolved->definition, $table, $field);
                $encoded = $this->values->cursorStorageValue($columns[0], $cursorValues[$index]);
                $keys[] = [
                    'column' => $columns[0]->physicalName,
                    'type' => $columns[0]->doctrineType,
                    'value' => $encoded,
                    'direction' => $sort->direction,
                    'nulls_last' => $sort->nullsLast,
                ];
            }
        }
        $parts = [];
        foreach ($keys as $index => $key) {
            $branch = [];
            for ($prefixIndex = 0; $prefixIndex < $index; ++$prefixIndex) {
                $prefixKey = $keys[$prefixIndex];
                $prefixColumn = $alias . '.' . $this->quote($prefixKey['column']);
                if ($prefixKey['value'] === null) {
                    $branch[] = $prefixColumn . ' IS NULL';
                } else {
                    $branch[] = $prefixColumn . ' = ?';
                    $parameters[] = $prefixKey['value'];
                    $types[] = $prefixKey['type'];
                }
            }
            $qualified = $alias . '.' . $this->quote($key['column']);
            $seek = $this->seek(
                $qualified,
                $key['value'],
                $key['direction'],
                $key['nulls_last'],
                $parameters,
                $types,
                $key['type'],
            );
            if ($seek !== null) {
                $branch[] = $seek;
                $parts[] = '(' . implode(' AND ', $branch) . ')';
            }
        }
        $tie = [];
        foreach ($keys as $key) {
            $qualified = $alias . '.' . $this->quote($key['column']);
            if ($key['value'] === null) {
                $tie[] = $qualified . ' IS NULL';
            } else {
                $tie[] = $qualified . ' = ?';
                $parameters[] = $key['value'];
                $types[] = $key['type'];
            }
        }
        $tie[] = $alias . '.' . $this->quote($this->physical($table, 'record_id')) . ' > ?';
        $parameters[] = $recordKey;
        $types[] = $this->type($table, 'record_id');
        $parts[] = '(' . implode(' AND ', $tie) . ')';

        return '(' . implode(' OR ', $parts) . ')';
    }

    /** @param list<mixed> $parameters @param list<string> $types */
    private function seek(
        string $column,
        mixed $value,
        SortDirection $direction,
        bool $nullsLast,
        array &$parameters,
        array &$types,
        string $type,
    ): ?string {
        if ($value === null) {
            return $nullsLast ? null : $column . ' IS NOT NULL';
        }
        $comparison = $column . ($direction === SortDirection::Ascending ? ' > ?' : ' < ?');
        $parameters[] = $value;
        $types[] = $type;

        return $nullsLast ? '(' . $column . ' IS NULL OR ' . $comparison . ')' : $comparison;
    }

    /** @return list<string> */
    private function projection(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        RecordQuerySpecification $specification,
    ): array {
        $projection = $specification->projection->fields;
        if ($projection === []) {
            $projection = array_map(
                static fn (FieldDefinition $field): string => $field->handle,
                array_values(array_filter(
                    $definition->fields(),
                    static fn (FieldDefinition $field): bool => $field->readVisible,
                )),
            );
        }
        for ($index = 0; $index < count($projection); ++$index) {
            $handle = $projection[$index];
            $field = $this->field($definition, $handle);
            if (!$field->readVisible) {
                throw new InvalidBusinessRecordQuery('A requested projection field is not readable.');
            }
            foreach ($field->formula?->dependencies() ?? [] as $dependency) {
                if (!in_array($dependency, $projection, true)) {
                    $projection[] = $dependency;
                }
            }
        }
        foreach ($specification->projection->includes as $relationship) {
            $this->relationship($definition, $relationship);
        }

        return array_values(array_unique($projection));
    }

    /** @param list<string> $projection @return list<string> */
    private function selectedPhysicalColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $projection,
    ): array {
        $logicalControls = [
            'record_id', 'definition_version', 'site_identifier', 'organization_identifier', 'version',
            'workflow_state', 'created_by', 'created_at', 'updated_by', 'updated_at', 'archived_by',
            'archived_at', 'deleted_by', 'deleted_at',
        ];
        $physical = [];
        foreach ($logicalControls as $logical) {
            $column = $table->column($logical);
            if ($column !== null) {
                $physical[] = $column->physicalName;
            }
        }
        foreach ($projection as $handle) {
            $field = $this->field($definition, $handle);
            if (
                $field->type === 'core.ordered_lines'
                || ($field->computed && $field->computationMode === ComputationMode::Virtual)
            ) {
                continue;
            }
            foreach ($this->fieldColumns($definition, $table, $field) as $column) {
                $physical[] = $column->physicalName;
            }
        }

        return array_values(array_unique($physical));
    }

    /** @param list<RecordAggregate> $aggregates @param list<string> $where */
    private function aggregateSql(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        string $alias,
        array $aggregates,
        array $where,
    ): ?string {
        if ($aggregates === []) {
            return null;
        }
        $select = [];
        foreach ($aggregates as $aggregate) {
            if ($aggregate->function === AggregateFunction::Count) {
                $select[] = 'COUNT(*) AS ' . $this->quote($aggregate->alias);
                continue;
            }
            $field = $this->field($resolved->definition, (string) $aggregate->field);
            if (!$field->reportable || !$this->queryVisible($field)) {
                throw new InvalidBusinessRecordQuery('An aggregate field is not reportable.');
            }
            $columns = $this->fieldColumns($resolved->definition, $table, $field);
            if (count($columns) !== 1) {
                throw new InvalidBusinessRecordQuery('An aggregate requires one physical field.');
            }
            if (
                in_array($aggregate->function, [AggregateFunction::Sum, AggregateFunction::Average], true)
                && !in_array($columns[0]->doctrineType, ['integer', 'smallint', 'bigint', 'decimal'], true)
            ) {
                throw new InvalidBusinessRecordQuery('Sum and average require an exact numeric field.');
            }
            if (
                in_array($aggregate->function, [AggregateFunction::Minimum, AggregateFunction::Maximum], true)
                && !in_array($columns[0]->doctrineType, [
                    'ascii_string', 'bigint', 'date_immutable', 'datetime_immutable', 'datetimetz_immutable',
                    'decimal', 'integer', 'smallint', 'string', 'text', 'time_immutable',
                ], true)
            ) {
                throw new InvalidBusinessRecordQuery(
                    'Minimum and maximum require a portably comparable scalar field.',
                );
            }
            $select[] = sprintf(
                '%s(%s.%s) AS %s',
                strtoupper($aggregate->function->value),
                $alias,
                $this->quote($columns[0]->physicalName),
                $this->quote($aggregate->alias),
            );
        }

        return sprintf(
            'SELECT %s FROM %s %s WHERE %s',
            implode(', ', $select),
            $this->quote($table->physicalName),
            $alias,
            implode(' AND ', $where),
        );
    }

    private function filterable(EntityTypeDefinition $definition, string $handle): FieldDefinition
    {
        $field = $this->field($definition, $handle);
        if (!$field->filterable || !$this->queryVisible($field)) {
            throw new InvalidBusinessRecordQuery('A requested filter field is not filterable.');
        }

        return $field;
    }

    private function queryVisible(FieldDefinition $field): bool
    {
        return $field->readVisible
            && !in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true);
    }

    /** @return array<string, mixed> */
    private function queryColumns(
        ResolvedBusinessDefinition $resolved,
        PhysicalTableBlueprint $table,
        FieldDefinition $field,
        mixed $value,
        ?RecordScope $scope = null,
    ): array {
        $identity = $this->identityField($resolved->definition);
        if ($field === $identity && $resolved->definition->identityStrategy === IdentityStrategy::Uuid) {
            if (!is_string($value)) {
                throw new InvalidBusinessRecordQuery('An identity query value must be a string.');
            }
            $normalized = $this->values->identity($resolved->definition, [$field->handle => $value], null);
            return [$this->physical($table, 'record_id') => $normalized];
        }
        if ($field->type === 'core.entity_reference') {
            if (!is_string($value)) {
                throw new InvalidBusinessRecordQuery('An entity-reference query value must be a string.');
            }
            if ($scope === null) {
                if (!Uuid::isValid($value)) {
                    throw new InvalidBusinessRecordQuery('A signed entity-reference cursor key is invalid.');
                }
                $recordKey = strtolower($value);
            } else {
                $recordKey = $this->referenceRecordKey($resolved->definition, $field, $scope, $value);
            }

            return [$this->physical($table, $field->handle) => $recordKey];
        }
        $normalized = $this->values->normalize(
            $field,
            $value,
            $resolved->definition->siteIdentifier,
            $resolved->definition->id,
            'query-value',
        );
        $encoded = $this->values->encodeColumns($resolved->definition, $table, [$field->handle => $normalized]);
        if ($encoded === []) {
            throw new InvalidBusinessRecordQuery('The queried field has no installed physical storage.');
        }

        return $encoded;
    }

    private function referenceRecordKey(
        EntityTypeDefinition $source,
        FieldDefinition $field,
        ?RecordScope $scope,
        string $publicIdentity,
    ): string {
        if ($scope === null) {
            throw new InvalidBusinessRecordQuery('A public entity-reference cursor cannot be resolved without scope.');
        }
        $targetHandle = $field->configuration['target'] ?? null;
        if (!is_string($targetHandle)) {
            throw new InvalidBusinessRecordQuery('An entity-reference target is unavailable.');
        }
        $target = $this->targetHandle($source, $targetHandle);
        $identity = $this->identityField($target->definition);
        $normalized = $this->values->identity(
            $target->definition,
            [$identity->handle => $publicIdentity],
            null,
        );
        $table = $this->recordTable($target);
        $identityColumn = $target->definition->identityStrategy === IdentityStrategy::Uuid
            ? $this->physical($table, 'record_id')
            : $this->physical($table, $identity->handle);
        $parameters = [$normalized];
        $types = [$table->physicalColumn($identityColumn)?->doctrineType ?? Types::STRING];
        $where = ['x.' . $this->quote($identityColumn) . ' = ?'];
        array_push($where, ...$this->scopePredicates($table, 'x', $scope, $parameters, $types));
        if ($table->column('deleted_at') !== null) {
            $where[] = 'x.' . $this->quote($this->physical($table, 'deleted_at')) . ' IS NULL';
        }
        $recordKey = $this->database->fetchOne(sprintf(
            'SELECT x.%s FROM %s x WHERE %s',
            $this->quote($this->physical($table, 'record_id')),
            $this->quote($table->physicalName),
            implode(' AND ', $where),
        ), $parameters, $types);

        return is_string($recordKey) ? $recordKey : '00000000-0000-0000-0000-000000000000';
    }

    /** @return list<PhysicalColumnBlueprint> */
    private function fieldColumns(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        FieldDefinition $field,
    ): array {
        if ($field === $this->identityField($definition)) {
            $logical = $definition->identityStrategy === IdentityStrategy::Uuid
                ? ($table->logicalName === 'record' ? 'record_id' : 'line_id')
                : $field->handle;
            return [$table->column($logical)
                ?? throw new InvalidBusinessRecordQuery('The identity column is unavailable.')];
        }
        $prefix = $field->handle . '.';
        $columns = array_values(array_filter(
            $table->columns(),
            static fn (PhysicalColumnBlueprint $column): bool =>
                $column->logicalName === $field->handle || str_starts_with($column->logicalName, $prefix),
        ));
        if ($columns === []) {
            throw new InvalidBusinessRecordQuery('A requested field has no installed physical column.');
        }

        return $columns;
    }

    private function field(EntityTypeDefinition $definition, string $handle): FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }
        throw new InvalidBusinessRecordQuery('A business-record query references an unknown field.');
    }

    private function identityField(EntityTypeDefinition $definition): FieldDefinition
    {
        $type = $definition->identityStrategy === IdentityStrategy::Uuid
            ? 'core.uuid'
            : 'core.reference_identity';
        foreach ($definition->fields() as $field) {
            if ($field->type === $type) {
                return $field;
            }
        }
        throw new InvalidBusinessRecordQuery('The definition identity field is unavailable.');
    }

    private function relationship(EntityTypeDefinition $definition, string $handle): RelationshipDefinition
    {
        return $definition->runtimeRelationship($handle)
            ?? throw new InvalidBusinessRecordQuery('A query references an unknown relationship.');
    }

    private function inverseRelationship(
        EntityTypeDefinition $source,
        EntityTypeDefinition $target,
        RelationshipDefinition $relationship,
    ): RelationshipDefinition {
        if ($relationship->inverse === null) {
            throw new InvalidBusinessRecordQuery('The relationship has no installed source or inverse storage.');
        }
        foreach ($target->relationships() as $candidate) {
            if ($candidate->handle === $relationship->inverse && $candidate->target === $source->handle) {
                return $candidate;
            }
        }
        throw new InvalidBusinessRecordQuery('The canonical inverse relationship definition is unavailable.');
    }

    private function target(
        EntityTypeDefinition $source,
        RelationshipDefinition $relationship,
    ): ResolvedBusinessDefinition {
        return $this->targetHandle($source, $relationship->target);
    }

    private function targetHandle(
        EntityTypeDefinition $source,
        string $targetHandle,
    ): ResolvedBusinessDefinition {
        $site = SiteContext::fromString($source->siteIdentifier);
        $generation = $this->fence->shared($site, $targetHandle);
        $entry = $this->definitions->entry($site, $targetHandle);
        if ($entry === null || !$entry->ownerActive) {
            throw new InvalidBusinessRecordQuery('A relation query target is unavailable.');
        }
        $installation = $this->installations->find($entry->id);
        if (
            $installation === null || $installation->status !== SchemaInstallationStatus::Active
            || $installation->siteIdentifier !== $source->siteIdentifier
        ) {
            throw new InvalidBusinessRecordQuery('A relation query target schema is unavailable.');
        }
        $targetVersion = SchemaEvolutionHints::fromDefinition($source)->repin($targetHandle)
            ?? $installation->definitionVersion;
        if ($targetVersion > $installation->definitionVersion) {
            throw new InvalidBusinessRecordQuery('A relation target is newer than its installed schema.');
        }
        $published = $this->definitions->published($site, $entry->id, $targetVersion);
        if ($published === null) {
            throw new InvalidBusinessRecordQuery('A relation query target definition version is unavailable.');
        }

        $resolved = new ResolvedBusinessDefinition($published->definition, $installation);
        $generation->assertMatches($resolved);

        return $resolved;
    }

    private function recordTable(ResolvedBusinessDefinition $resolved): PhysicalTableBlueprint
    {
        return $resolved->installation->blueprint->table('record')
            ?? throw new InvalidBusinessRecordQuery('The installed record table is unavailable.');
    }

    private function physical(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)?->physicalName
            ?? throw new InvalidBusinessRecordQuery('An installed query column is unavailable.');
    }

    private function type(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)?->doctrineType
            ?? throw new InvalidBusinessRecordQuery('An installed query column type is unavailable.');
    }

    private function quote(string $identifier): string
    {
        return $this->database->getDatabasePlatform()->quoteIdentifier($identifier);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function cursorDigest(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
    ): string {
        return CanonicalDefinitionJson::checksum([
            'definition_id' => $resolved->definition->id,
            'definition_version' => $resolved->definition->definitionVersion,
            'definition_checksum' => $resolved->definition->checksum(),
            'schema_checksum' => $resolved->installation->schemaChecksum,
            'scope' => $scope->toArray(),
            'specification' => $specification->toArray(false),
        ]);
    }
}
