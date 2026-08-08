<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Schema;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\CMS\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\CMS\BusinessSchema\Application\SchemaChunkResult;
use Kumwe\CMS\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalForeignKeyBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalIndexBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperation;
use Kumwe\CMS\BusinessSchema\Domain\SchemaOperationKind;
use Throwable;

/** Executes only identifier-safe, canonical operations through Doctrine's schema model. */
final readonly class DoctrinePhysicalSchemaGateway implements PhysicalSchemaGateway
{
    public function __construct(private Connection $database)
    {
    }

    public function inspect(PhysicalSchemaBlueprint $expected): ?PhysicalSchemaBlueprint
    {
        $manager = $this->database->createSchemaManager();
        $present = 0;
        foreach ($expected->tables() as $table) {
            if (!$manager->tablesExist([$table->physicalName])) {
                continue;
            }
            ++$present;
            $actual = $manager->introspectTableByUnquotedName($table->physicalName);
            if (!$this->tableMatches($actual, $table)) {
                throw new BusinessSchemaConflict(sprintf(
                    'Physical schema drift was detected for compiled table %s: %s.',
                    $table->logicalName,
                    $this->tableMismatchReason($actual, $table),
                ));
            }
        }
        if ($present === 0) {
            return null;
        }
        if ($present !== count($expected->tables())) {
            throw new BusinessSchemaConflict('The installed physical schema is incomplete.');
        }

        return $expected;
    }

    public function operationSatisfied(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
    ): bool {
        $manager = $this->database->createSchemaManager();
        if ($operation->kind === SchemaOperationKind::CreateTable) {
            $planned = PhysicalTableBlueprint::fromArray($this->state($operation->after, 'create-table target'));
            if (!$manager->tablesExist([$planned->physicalName])) {
                return false;
            }

            return $this->tableMatches(
                $manager->introspectTableByUnquotedName($planned->physicalName),
                $planned,
                false,
            );
        }
        $targetTable = $target->table($operation->table);
        $physicalTable = $targetTable?->physicalName ?? $this->beforeTableName($operation);
        if ($physicalTable === null) {
            throw new InvalidBusinessSchema('A schema operation references an unknown table.');
        }
        $exists = $manager->tablesExist([$physicalTable]);

        return match ($operation->kind) {
            SchemaOperationKind::DropTable => !$exists,
            SchemaOperationKind::RenameTable => $exists,
            default => $exists && $this->objectSatisfied(
                $manager->introspectTableByUnquotedName($physicalTable),
                $targetTable,
                $operation,
            ),
        };
    }

    public function execute(SchemaOperation $operation, PhysicalSchemaBlueprint $target): void
    {
        if ($this->operationSatisfied($operation, $target)) {
            return;
        }
        $manager = $this->database->createSchemaManager();
        $table = $target->table($operation->table);

        if ($operation->kind === SchemaOperationKind::CreateTable) {
            $planned = PhysicalTableBlueprint::fromArray($this->state($operation->after, 'create-table target'));
            $manager->createTable($this->doctrineTable($planned));
            return;
        }
        if ($operation->kind === SchemaOperationKind::DropTable) {
            $name = $this->beforeTableName($operation)
                ?? throw new InvalidBusinessSchema('A drop-table operation has no prior table.');
            $manager->dropTable($name);
            return;
        }
        if (in_array($operation->kind, [SchemaOperationKind::Backfill, SchemaOperationKind::Transform], true)) {
            throw new InvalidBusinessSchema('Data rewrite operations require bounded chunk execution.');
        }

        $before = $manager->introspectSchema();
        $after = clone $before;
        $this->mutate($after, $operation, $table);
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($this->database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $this->database->executeStatement($statement);
        }
    }

    public function compensateCreateTable(SchemaOperation $operation): bool
    {
        if ($operation->kind !== SchemaOperationKind::CreateTable) {
            throw new InvalidBusinessSchema('Only an approved create-table operation can be compensated.');
        }
        $planned = PhysicalTableBlueprint::fromArray($this->state($operation->after, 'create-table target'));
        $manager = $this->database->createSchemaManager();
        if (!$manager->tablesExist([$planned->physicalName])) {
            return false;
        }
        $actual = $manager->introspectTableByUnquotedName($planned->physicalName);
        if (!$this->tableMatches($actual, $planned, true)) {
            throw new BusinessSchemaConflict(
                'A newly created table changed shape and cannot be compensated automatically.',
            );
        }
        if (
            $this->database->fetchOne(sprintf(
                'SELECT 1 FROM %s LIMIT 1',
                $this->database->quoteSingleIdentifier($planned->physicalName),
            )) !== false
        ) {
            throw new BusinessSchemaConflict('A newly created table contains data and cannot be compensated.');
        }
        $manager->dropTable($planned->physicalName);

        return true;
    }

    public function hasRowsPinnedBefore(
        PhysicalSchemaBlueprint $installed,
        int $definitionVersion,
    ): bool {
        if ($definitionVersion < 1) {
            throw new InvalidBusinessSchema('A pinned-row version boundary must be positive.');
        }
        $record = $installed->table('record')
            ?? throw new InvalidBusinessSchema('An installed schema has no record table.');
        $version = $record->column('definition_version')
            ?? throw new InvalidBusinessSchema('An installed record table has no definition-version column.');
        $manager = $this->database->createSchemaManager();
        if (!$manager->tablesExist([$record->physicalName])) {
            throw new BusinessSchemaConflict('The installed record table is missing during pinned-row validation.');
        }

        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE %s < ? LIMIT 1',
            $this->database->quoteSingleIdentifier($record->physicalName),
            $this->database->quoteSingleIdentifier($version->physicalName),
        ), [$definitionVersion], [\Doctrine\DBAL\Types\Types::INTEGER]) !== false;
    }

    public function backfillChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult {
        if ($operation->kind !== SchemaOperationKind::Backfill || $limit < 1 || $limit > 1_000) {
            throw new InvalidBusinessSchema('A schema backfill chunk request is invalid.');
        }
        $table = $target->table($operation->table)
            ?? throw new InvalidBusinessSchema('A backfill operation has no target table.');
        if (count($table->primaryKey) !== 1) {
            throw new InvalidBusinessSchema('Chunked backfill requires a single canonical identity column.');
        }
        $state = $this->state($operation->after, 'backfill');
        $columnState = $state['column'] ?? null;
        $hasLiteral = array_key_exists('value', $state);
        $hasExpression = isset($state['expression'], $state['dependencies']);
        if (!is_array($columnState) || array_is_list($columnState) || $hasLiteral === $hasExpression) {
            throw new InvalidBusinessSchema(
                'A backfill requires a canonical column and exactly one literal or Expression value.',
            );
        }
        $column = PhysicalColumnBlueprint::fromArray($columnState);
        $targetColumn = $table->column($column->logicalName);
        if ($targetColumn === null || $targetColumn->physicalName !== $column->physicalName) {
            throw new InvalidBusinessSchema('A backfill column is not present in the approved target blueprint.');
        }
        $identityName = $table->primaryKey[0];
        $identity = $this->physicalColumn($table, $identityName);
        $last = $cursor['last_identity'] ?? null;
        if ($cursor !== null && !is_int($last) && !is_string($last)) {
            throw new InvalidBusinessSchema('A schema backfill cursor is invalid.');
        }
        $parameters = [];
        $types = [];
        $predicate = '';
        if ($last !== null) {
            $predicate = sprintf(' AND %s > ?', $this->database->quoteSingleIdentifier($identityName));
            $parameters[] = $last;
            $types[] = $identity->doctrineType;
        }
        $expression = null;
        $dependencies = [];
        if ($hasExpression) {
            if (
                !is_array($state['expression']) || array_is_list($state['expression'])
                || !is_array($state['dependencies']) || array_is_list($state['dependencies'])
            ) {
                throw new InvalidBusinessSchema('A backfill Expression state is invalid.');
            }
            /** @var array<string, mixed> $expressionDocument */
            $expressionDocument = $state['expression'];
            $expression = Expression::fromArray($expressionDocument);
            foreach ($state['dependencies'] as $logical => $document) {
                if (!is_string($logical) || !is_array($document) || array_is_list($document)) {
                    throw new InvalidBusinessSchema('A backfill Expression dependency is invalid.');
                }
                $dependencies[$logical] = PhysicalColumnBlueprint::fromArray($document);
            }
            if (array_keys($dependencies) !== $expression->dependencies()) {
                throw new InvalidBusinessSchema('A backfill Expression dependency map is incomplete.');
            }
        }
        $select = [$this->database->quoteSingleIdentifier($identityName) . ' AS backfill_identity'];
        $aliases = [];
        foreach ($dependencies as $logical => $dependency) {
            $alias = 'backfill_value_' . count($aliases);
            $select[] = $this->database->quoteSingleIdentifier($dependency->physicalName)
                . ' AS ' . $this->database->quoteSingleIdentifier($alias);
            $aliases[$logical] = $alias;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT %s FROM %s WHERE %s IS NULL%s ORDER BY %s LIMIT %d',
            implode(', ', $select),
            $this->database->quoteSingleIdentifier($table->physicalName),
            $this->database->quoteSingleIdentifier($column->physicalName),
            $predicate,
            $this->database->quoteSingleIdentifier($identityName),
            $limit,
        ), $parameters, $types);
        $processed = 0;
        foreach ($rows as $row) {
            $identityValue = $row['backfill_identity'] ?? null;
            if (!is_int($identityValue) && !is_string($identityValue)) {
                throw new BusinessSchemaConflict('A physical backfill identity has an invalid value.');
            }
            $value = $state['value'] ?? null;
            if ($expression !== null) {
                $fields = [];
                foreach ($dependencies as $logical => $_dependency) {
                    $fields[$logical] = $this->expressionValue(
                        $expression,
                        $logical,
                        $row[$aliases[$logical]] ?? null,
                    );
                }
                $value = $expression->evaluate($fields);
            }
            if ($value === null || is_float($value) || is_array($value) || is_object($value)) {
                throw new InvalidBusinessSchema('A schema backfill produced a non-exact or null result.');
            }
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ? AND %s IS NULL',
                $this->database->quoteSingleIdentifier($table->physicalName),
                $this->database->quoteSingleIdentifier($column->physicalName),
                $this->database->quoteSingleIdentifier($identityName),
                $this->database->quoteSingleIdentifier($column->physicalName),
            ), [
                $this->boundPhysicalValue($column, $value),
                $identityValue,
            ], [$column->doctrineType, $identity->doctrineType]);
            $last = $identityValue;
            ++$processed;
        }

        return new SchemaChunkResult(
            $last === null ? $cursor : ['last_identity' => $last],
            $processed,
            count($rows) < $limit,
        );
    }

    public function transformChunk(
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult {
        if ($operation->kind !== SchemaOperationKind::Transform || $limit < 1 || $limit > 1_000) {
            throw new InvalidBusinessSchema('A schema transform chunk request is invalid.');
        }
        $table = $target->table($operation->table)
            ?? throw new InvalidBusinessSchema('A transform operation has no target table.');
        $state = $this->state($operation->after, 'transform');
        foreach (['source', 'target', 'expression', 'dependencies'] as $required) {
            if (!is_array($state[$required] ?? null) || array_is_list($state[$required])) {
                throw new InvalidBusinessSchema('A schema transform has an invalid canonical ' . $required . '.');
            }
        }
        /** @var array<string, mixed> $sourceState */
        $sourceState = $state['source'];
        /** @var array<string, mixed> $targetState */
        $targetState = $state['target'];
        /** @var array<string, mixed> $expressionState */
        $expressionState = $state['expression'];
        /** @var array<string, mixed> $dependencyStates */
        $dependencyStates = $state['dependencies'];
        $source = PhysicalColumnBlueprint::fromArray($sourceState);
        $shadow = PhysicalColumnBlueprint::fromArray($targetState);
        $expression = Expression::fromArray($expressionState);
        $primary = $state['primary_key'] ?? null;
        if (!is_array($primary) || !array_is_list($primary) || count($primary) !== 1 || !is_string($primary[0])) {
            throw new InvalidBusinessSchema('Chunked transform requires one canonical physical identity.');
        }
        $identityName = $primary[0];
        if (!in_array($identityName, $table->primaryKey, true)) {
            throw new InvalidBusinessSchema('A schema transform identity disagrees with its target blueprint.');
        }
        $identity = $this->physicalColumn($table, $identityName);
        $dependencies = [];
        foreach ($dependencyStates as $logical => $document) {
            if (!is_string($logical) || !is_array($document) || array_is_list($document)) {
                throw new InvalidBusinessSchema('A schema transform dependency map is invalid.');
            }
            $dependencies[$logical] = PhysicalColumnBlueprint::fromArray($document);
        }
        if (
            !isset($dependencies[$source->logicalName])
            && in_array($source->logicalName, $expression->dependencies(), true)
        ) {
            throw new InvalidBusinessSchema('A schema transform source dependency is unavailable.');
        }
        $last = $cursor['last_identity'] ?? null;
        if ($cursor !== null && !is_int($last) && !is_string($last)) {
            throw new InvalidBusinessSchema('A schema transform cursor is invalid.');
        }
        $select = [$this->database->quoteSingleIdentifier($identityName) . ' AS transform_identity'];
        $offset = 0;
        $aliases = [];
        foreach ($dependencies as $logical => $dependency) {
            $alias = 'transform_value_' . $offset++;
            $select[] = $this->database->quoteSingleIdentifier($dependency->physicalName)
                . ' AS ' . $this->database->quoteSingleIdentifier($alias);
            $aliases[$logical] = $alias;
        }
        $parameters = [];
        $types = [];
        $predicate = '';
        if ($last !== null) {
            $predicate = ' WHERE ' . $this->database->quoteSingleIdentifier($identityName) . ' > ?';
            $parameters[] = $last;
            $types[] = $identity->doctrineType;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT %s FROM %s%s ORDER BY %s LIMIT %d',
            implode(', ', $select),
            $this->database->quoteSingleIdentifier($table->physicalName),
            $predicate,
            $this->database->quoteSingleIdentifier($identityName),
            $limit,
        ), $parameters, $types);
        $processed = 0;
        foreach ($rows as $row) {
            $identityValue = $row['transform_identity'] ?? null;
            if (!is_int($identityValue) && !is_string($identityValue)) {
                throw new BusinessSchemaConflict('A physical transform identity has an invalid value.');
            }
            $values = [];
            foreach ($dependencies as $logical => $_dependency) {
                $values[$logical] = $this->expressionValue(
                    $expression,
                    $logical,
                    $row[$aliases[$logical]] ?? null,
                );
            }
            $result = $expression->evaluate($values);
            if (is_float($result) || is_array($result) || is_object($result)) {
                throw new InvalidBusinessSchema('A schema transform produced a non-exact scalar result.');
            }
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s = ? WHERE %s = ?',
                $this->database->quoteSingleIdentifier($table->physicalName),
                $this->database->quoteSingleIdentifier($shadow->physicalName),
                $this->database->quoteSingleIdentifier($identityName),
            ), [
                $this->boundPhysicalValue($shadow, $result),
                $identityValue,
            ], [$shadow->doctrineType, $identity->doctrineType]);
            $last = $identityValue;
            ++$processed;
        }

        return new SchemaChunkResult(
            $last === null ? $cursor : ['last_identity' => $last],
            $processed,
            count($rows) < $limit,
        );
    }

    private function mutate(
        Schema $schema,
        SchemaOperation $operation,
        ?PhysicalTableBlueprint $target,
    ): void {
        $tableName = $target?->physicalName ?? $this->beforeTableName($operation);
        if ($tableName === null || !$schema->hasTable($tableName)) {
            throw new BusinessSchemaConflict('The schema operation table disappeared before execution.');
        }
        $table = $schema->getTable($tableName);
        switch ($operation->kind) {
            case SchemaOperationKind::RenameTable:
                $after = $this->state($operation->after, 'rename-table target');
                $name = $this->physicalName($after);
                $schema->renameTable($tableName, $name);
                return;

            case SchemaOperationKind::AddColumn:
                $column = PhysicalColumnBlueprint::fromArray($this->state($operation->after, 'target column'));
                $this->addColumn($table, $column);
                return;

            case SchemaOperationKind::AlterColumn:
                $column = $this->targetColumn($target, $operation->subject);
                $table->modifyColumn($column->physicalName, [
                    'type' => Type::getType($column->doctrineType),
                    ...$this->columnOptions($column),
                ]);
                return;

            case SchemaOperationKind::RenameColumn:
                $before = PhysicalColumnBlueprint::fromArray($this->state($operation->before, 'prior column'));
                $column = $this->targetColumn($target, $operation->subject);
                $table->renameColumn($before->physicalName, $column->physicalName);
                $table->modifyColumn($column->physicalName, [
                    'type' => Type::getType($column->doctrineType),
                    ...$this->columnOptions($column),
                ]);
                return;

            case SchemaOperationKind::DropColumn:
                $column = PhysicalColumnBlueprint::fromArray($this->state($operation->before, 'prior column'));
                $table->dropColumn($column->physicalName);
                return;

            case SchemaOperationKind::AddIndex:
                $this->addIndex($table, $this->targetIndex($target, $operation->subject));
                return;

            case SchemaOperationKind::DropIndex:
                $index = PhysicalIndexBlueprint::fromArray($this->state($operation->before, 'prior index'));
                $table->dropIndex($index->physicalName);
                return;

            case SchemaOperationKind::AddForeignKey:
                $this->addForeignKey($table, $this->targetForeignKey($target, $operation->subject));
                return;

            case SchemaOperationKind::DropForeignKey:
                $foreignKey = PhysicalForeignKeyBlueprint::fromArray(
                    $this->state($operation->before, 'prior foreign key'),
                );
                $table->removeForeignKey($foreignKey->physicalName);
                return;

            case SchemaOperationKind::AddPrimaryKey:
                if ($target === null) {
                    throw new InvalidBusinessSchema('An add-primary-key operation has no target table.');
                }
                $table->addPrimaryKeyConstraint(
                    PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$target->primaryKey)->create(),
                );
                return;

            case SchemaOperationKind::DropPrimaryKey:
                $table->dropPrimaryKey();
                return;

            case SchemaOperationKind::ValidateConstraint:
                return;

            default:
                throw new InvalidBusinessSchema('The requested schema operation cannot be applied as an alteration.');
        }
    }

    private function doctrineTable(PhysicalTableBlueprint $blueprint): Table
    {
        $table = new Table($blueprint->physicalName);
        foreach ($blueprint->columns() as $column) {
            $this->addColumn($table, $column);
        }
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$blueprint->primaryKey)->create(),
        );
        foreach ($blueprint->indexes() as $index) {
            $this->addIndex($table, $index);
        }
        foreach ($blueprint->foreignKeys() as $foreignKey) {
            $this->addForeignKey($table, $foreignKey);
        }

        return $table;
    }

    private function addColumn(Table $table, PhysicalColumnBlueprint $column): void
    {
        $table->addColumn($column->physicalName, $column->doctrineType, $this->columnOptions($column));
    }

    /** @return array<string, mixed> */
    private function columnOptions(PhysicalColumnBlueprint $column): array
    {
        $allowed = [
            'length', 'precision', 'scale', 'fixed', 'unsigned', 'autoincrement', 'default', 'comment',
            'platformOptions',
        ];
        $options = array_intersect_key($column->options, array_flip($allowed));
        if (array_key_exists('default', $options)) {
            $options['default'] = $this->physicalDefault($column, $options['default']);
        }

        return ['notnull' => !$column->nullable, ...$options];
    }

    private function addIndex(Table $table, PhysicalIndexBlueprint $index): void
    {
        if ($index->unique) {
            $table->addUniqueIndex($index->columns, $index->physicalName, $index->options);
            return;
        }
        $table->addIndex($index->columns, $index->physicalName, [], $index->options);
    }

    private function addForeignKey(Table $table, PhysicalForeignKeyBlueprint $foreignKey): void
    {
        $table->addForeignKeyConstraint(
            $foreignKey->foreignTable,
            $foreignKey->localColumns,
            $foreignKey->foreignColumns,
            ['onDelete' => $foreignKey->onDelete, 'onUpdate' => $foreignKey->onUpdate],
            $foreignKey->physicalName,
        );
    }

    private function objectSatisfied(
        Table $actual,
        ?PhysicalTableBlueprint $target,
        SchemaOperation $operation,
    ): bool {
        $temporalPrecisions = $this->temporalPrecisions($actual);

        return match ($operation->kind) {
            SchemaOperationKind::AlterColumn => $target !== null
                && ($column = $target->column($operation->subject)) !== null
                && $actual->hasColumn($column->physicalName)
                && $this->columnMatches(
                    $actual->getColumn($column->physicalName),
                    $column,
                    $temporalPrecisions,
                ),
            SchemaOperationKind::AddColumn => ($column = PhysicalColumnBlueprint::fromArray(
                $this->state($operation->after, 'target column'),
            )) !== null
                && $actual->hasColumn($column->physicalName)
                && $this->columnMatches(
                    $actual->getColumn($column->physicalName),
                    $column,
                    $temporalPrecisions,
                ),
            SchemaOperationKind::RenameColumn => ($before = PhysicalColumnBlueprint::fromArray(
                $this->state($operation->before, 'prior column'),
            )) !== null
                && ($after = PhysicalColumnBlueprint::fromArray(
                    $this->state($operation->after, 'target column'),
                )) !== null
                && !$actual->hasColumn($before->physicalName)
                && $actual->hasColumn($after->physicalName)
                && $this->columnMatches(
                    $actual->getColumn($after->physicalName),
                    $after,
                    $temporalPrecisions,
                ),
            SchemaOperationKind::Transform => false,
            SchemaOperationKind::DropColumn => !$actual->hasColumn(
                PhysicalColumnBlueprint::fromArray($this->state($operation->before, 'prior column'))->physicalName,
            ),
            SchemaOperationKind::AddIndex => $target !== null
                && ($index = $this->findIndex($target, $operation->subject)) !== null
                && $this->hasIndex($actual, $index),
            SchemaOperationKind::DropIndex => !$actual->hasIndex(
                PhysicalIndexBlueprint::fromArray($this->state($operation->before, 'prior index'))->physicalName,
            ),
            SchemaOperationKind::AddForeignKey => $target !== null
                && ($key = $this->findForeignKey($target, $operation->subject)) !== null
                && $this->hasForeignKey($actual, $key),
            SchemaOperationKind::DropForeignKey => !$this->foreignKeyNamed(
                $actual,
                PhysicalForeignKeyBlueprint::fromArray(
                    $this->state($operation->before, 'prior foreign key'),
                )->physicalName,
            ),
            SchemaOperationKind::Backfill => $this->backfillSatisfied($actual, $operation),
            SchemaOperationKind::RepinRecords => $target !== null
                && $this->repinSatisfied($actual, $target, $operation),
            SchemaOperationKind::AddPrimaryKey => $actual->getPrimaryKeyConstraint() !== null,
            SchemaOperationKind::DropPrimaryKey => $actual->getPrimaryKeyConstraint() === null,
            SchemaOperationKind::ValidateConstraint => true,
            default => false,
        };
    }

    private function tableMatches(
        Table $actual,
        PhysicalTableBlueprint $expected,
        bool $exact = true,
    ): bool {
        $temporalPrecisions = $this->temporalPrecisions($actual);
        if ($exact) {
            $actualColumns = array_map(
                static fn (Column $column): string => strtolower(
                    $column->getObjectName()->getIdentifier()->getValue(),
                ),
                $actual->getColumns(),
            );
            $expectedColumns = array_map(
                static fn (PhysicalColumnBlueprint $column): string => strtolower($column->physicalName),
                $expected->columns(),
            );
            sort($actualColumns, SORT_STRING);
            sort($expectedColumns, SORT_STRING);
            if ($actualColumns !== $expectedColumns) {
                return false;
            }
        }
        foreach ($expected->columns() as $column) {
            if (
                !$actual->hasColumn($column->physicalName)
                || !$this->columnMatches(
                    $actual->getColumn($column->physicalName),
                    $column,
                    $temporalPrecisions,
                )
            ) {
                return false;
            }
        }
        $primary = $actual->getPrimaryKeyConstraint();
        if ($primary === null || $this->unqualifiedNames($primary->getColumnNames()) !== $expected->primaryKey) {
            return false;
        }
        foreach ($expected->indexes() as $index) {
            if (!$this->hasIndex($actual, $index)) {
                return false;
            }
        }
        foreach ($expected->foreignKeys() as $foreignKey) {
            if (!$this->hasForeignKey($actual, $foreignKey)) {
                return false;
            }
        }
        if ($exact) {
            $expectedIndexes = array_map(
                static fn (PhysicalIndexBlueprint $index): string => strtolower($index->physicalName),
                $expected->indexes(),
            );
            sort($expectedIndexes, SORT_STRING);
            $actualIndexes = [];
            foreach ($actual->getIndexes() as $index) {
                $name = strtolower($index->getObjectName()?->getIdentifier()->getValue() ?? '');
                $columns = array_map(
                    static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(),
                    $index->getIndexedColumns(),
                );
                $implicitPrimary = $columns === $expected->primaryKey
                    && ($name === 'primary' || str_ends_with($name, '_pkey'));
                if (!$implicitPrimary) {
                    $actualIndexes[] = $name;
                }
            }
            sort($actualIndexes, SORT_STRING);
            if ($actualIndexes !== $expectedIndexes) {
                return false;
            }
            $expectedKeys = array_map(
                static fn (PhysicalForeignKeyBlueprint $key): string => strtolower($key->physicalName),
                $expected->foreignKeys(),
            );
            $actualKeys = array_map(
                fn (ForeignKeyConstraint $key): string => strtolower($this->constraintName($key)),
                $actual->getForeignKeys(),
            );
            sort($expectedKeys, SORT_STRING);
            sort($actualKeys, SORT_STRING);
            if ($actualKeys !== $expectedKeys) {
                return false;
            }
        }

        return true;
    }

    /** Returns bounded structural evidence without exposing persisted values. */
    private function tableMismatchReason(Table $actual, PhysicalTableBlueprint $expected): string
    {
        $actualColumns = array_map(
            static fn (Column $column): string => strtolower(
                $column->getObjectName()->getIdentifier()->getValue(),
            ),
            $actual->getColumns(),
        );
        $expectedColumns = array_map(
            static fn (PhysicalColumnBlueprint $column): string => strtolower($column->physicalName),
            $expected->columns(),
        );
        sort($actualColumns, SORT_STRING);
        sort($expectedColumns, SORT_STRING);
        if ($actualColumns !== $expectedColumns) {
            return sprintf(
                'column inventory differs (actual [%s], expected [%s])',
                implode(', ', $actualColumns),
                implode(', ', $expectedColumns),
            );
        }
        $temporalPrecisions = $this->temporalPrecisions($actual);
        foreach ($expected->columns() as $column) {
            if (!$actual->hasColumn($column->physicalName)) {
                return sprintf('compiled column %s is missing', $column->physicalName);
            }
            if (!$this->columnMatches($actual->getColumn($column->physicalName), $column, $temporalPrecisions)) {
                return sprintf('compiled column %s differs', $column->physicalName);
            }
        }
        $primary = $actual->getPrimaryKeyConstraint();
        if ($primary === null || $this->unqualifiedNames($primary->getColumnNames()) !== $expected->primaryKey) {
            return 'primary-key columns differ';
        }
        foreach ($expected->indexes() as $index) {
            if (!$this->hasIndex($actual, $index)) {
                return sprintf('compiled index %s differs', $index->physicalName);
            }
        }
        foreach ($expected->foreignKeys() as $foreignKey) {
            if (!$this->hasForeignKey($actual, $foreignKey)) {
                return sprintf('compiled foreign key %s differs', $foreignKey->physicalName);
            }
        }
        $expectedIndexes = array_map(
            static fn (PhysicalIndexBlueprint $index): string => strtolower($index->physicalName),
            $expected->indexes(),
        );
        sort($expectedIndexes, SORT_STRING);
        $actualIndexes = [];
        foreach ($actual->getIndexes() as $index) {
            $name = strtolower($index->getObjectName()?->getIdentifier()->getValue() ?? '');
            $columns = array_map(
                static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(),
                $index->getIndexedColumns(),
            );
            if ($columns !== $expected->primaryKey || ($name !== 'primary' && !str_ends_with($name, '_pkey'))) {
                $actualIndexes[] = $name;
            }
        }
        sort($actualIndexes, SORT_STRING);
        if ($actualIndexes !== $expectedIndexes) {
            return sprintf(
                'index inventory differs (actual [%s], expected [%s])',
                implode(', ', $actualIndexes),
                implode(', ', $expectedIndexes),
            );
        }
        $expectedKeys = array_map(
            static fn (PhysicalForeignKeyBlueprint $key): string => strtolower($key->physicalName),
            $expected->foreignKeys(),
        );
        $actualKeys = array_map(
            fn (ForeignKeyConstraint $key): string => strtolower($this->constraintName($key)),
            $actual->getForeignKeys(),
        );
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            return sprintf(
                'foreign-key inventory differs (actual [%s], expected [%s])',
                implode(', ', $actualKeys),
                implode(', ', $expectedKeys),
            );
        }

        return 'unclassified structural mismatch';
    }

    /** @param array<string, int> $temporalPrecisions */
    private function columnMatches(
        Column $actual,
        PhysicalColumnBlueprint $expected,
        array $temporalPrecisions,
    ): bool {
        if (
            !$this->physicalTypeMatches($actual, $expected)
            || $actual->getNotnull() === $expected->nullable
        ) {
            return false;
        }
        if (
            in_array(
                $expected->doctrineType,
                ['datetime_immutable', 'datetimetz_immutable', 'time_immutable'],
                true,
            )
            && ($temporalPrecisions[$expected->physicalName] ?? null) !== 6
        ) {
            return false;
        }
        foreach (['length' => 'getLength', 'precision' => 'getPrecision', 'scale' => 'getScale'] as $key => $method) {
            if ($this->physicalOptionIsNotIntrospectable($expected, $key)) {
                continue;
            }
            if (array_key_exists($key, $expected->options) && $actual->{$method}() !== $expected->options[$key]) {
                return false;
            }
        }
        if (
            !$this->physicalOptionIsNotIntrospectable($expected, 'fixed')
            && array_key_exists('fixed', $expected->options)
            && $actual->getFixed() !== $expected->options['fixed']
        ) {
            return false;
        }
        if (
            $actual->getAutoincrement() !== ($expected->options['autoincrement'] ?? false)
            || $actual->getComment() !== ($expected->options['comment'] ?? '')
        ) {
            return false;
        }
        if (
            array_key_exists('default', $expected->options)
            && !$this->defaultMatches(
                $actual->getDefault(),
                $expected->options['default'],
                $expected,
            )
        ) {
            return false;
        }
        if (!array_key_exists('default', $expected->options) && $actual->getDefault() !== null) {
            return false;
        }

        return true;
    }

    private function physicalTypeMatches(Column $actual, PhysicalColumnBlueprint $expected): bool
    {
        $platform = $this->database->getDatabasePlatform();
        if (
            $platform instanceof PostgreSQLPlatform
            && $expected->doctrineType === 'json'
            && ($actual->toArray()['jsonb'] ?? false) !== false
        ) {
            return false;
        }
        if (
            $platform instanceof AbstractMySQLPlatform
            && $expected->doctrineType === 'guid'
            && ($actual->getLength() !== 36 || !$actual->getFixed())
        ) {
            return false;
        }
        if (get_class($actual->getType()) === get_class(Type::getType($expected->doctrineType))) {
            return true;
        }
        if (!$platform instanceof AbstractMySQLPlatform && !$platform instanceof PostgreSQLPlatform) {
            return false;
        }
        $actualMatches = static fn (string $type): bool =>
            get_class($actual->getType()) === get_class(Type::getType($type));

        return match ($expected->doctrineType) {
            'ascii_string' => $actualMatches('string'),
            'binary' => $platform instanceof PostgreSQLPlatform && $actualMatches('blob'),
            'date_immutable' => $actualMatches('date'),
            'datetime_immutable' => $actualMatches('datetime'),
            'datetimetz_immutable' => $platform instanceof PostgreSQLPlatform
                ? $actualMatches('datetimetz')
                : $actualMatches('datetime') || $actualMatches('datetime_immutable'),
            'guid' => $platform instanceof AbstractMySQLPlatform
                && $actualMatches('string'),
            'time_immutable' => $actualMatches('time'),
            default => false,
        };
    }

    private function physicalOptionIsNotIntrospectable(
        PhysicalColumnBlueprint $column,
        string $option,
    ): bool {
        return $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            && $column->doctrineType === 'binary'
            && in_array($option, ['length', 'fixed'], true);
    }

    private function defaultMatches(
        mixed $actual,
        mixed $expected,
        PhysicalColumnBlueprint $column,
    ): bool {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }
        if ($column->doctrineType !== 'boolean' || !is_bool($expected)) {
            try {
                $expected = $this->physicalDefault($column, $expected);
                $actual = $this->physicalDefault($column, $actual);
            } catch (InvalidBusinessSchema) {
                return false;
            }

            return is_scalar($actual) && is_scalar($expected)
                && (string) $actual === (string) $expected;
        }
        if (is_bool($actual)) {
            return $actual === $expected;
        }
        if (is_int($actual) && in_array($actual, [0, 1], true)) {
            return ($actual === 1) === $expected;
        }
        if (!is_string($actual)) {
            return false;
        }
        $normalized = strtolower(trim($actual));
        if (in_array($normalized, ['1', 'true', 't'], true)) {
            return $expected;
        }
        if (in_array($normalized, ['0', 'false', 'f'], true)) {
            return !$expected;
        }

        return false;
    }

    private function physicalDefault(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        return match ($column->doctrineType) {
            'ascii_string', 'string' => $this->stringDefault($column, $value),
            'guid' => $this->guidDefault($value),
            'boolean' => $this->booleanDefault($value),
            'bigint', 'integer', 'smallint' => $this->integerDefault($column, $value),
            'decimal' => $this->decimalDefault($column, $value),
            'date_immutable' => $this->dateDefault($value),
            'time_immutable' => $this->timeDefault($value),
            'datetime_immutable', 'datetimetz_immutable' => $this->dateTimeDefault($value),
            default => $value,
        };
    }

    private function boundPhysicalValue(PhysicalColumnBlueprint $column, mixed $value): mixed
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }
        $normalized = $this->physicalDefault($column, $value);
        if (!is_string($normalized)) {
            return $normalized;
        }
        $format = match ($column->doctrineType) {
            'date_immutable' => '!Y-m-d',
            'time_immutable' => '!H:i:s.u',
            'datetime_immutable', 'datetimetz_immutable' => '!Y-m-d H:i:s.u',
            default => null,
        };
        if ($format === null) {
            return $normalized;
        }
        $parsed = DateTimeImmutable::createFromFormat($format, $normalized, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new InvalidBusinessSchema('A temporal schema rewrite value could not be bound exactly.');
        }

        return $parsed;
    }

    private function booleanDefault(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidBusinessSchema('A boolean physical default or rewrite value must be exact.');
        }

        return $value;
    }

    private function stringDefault(PhysicalColumnBlueprint $column, mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidBusinessSchema('A string physical default must be an exact string.');
        }
        $length = $column->options['length'] ?? null;
        if (is_int($length) && mb_strlen($value) > $length) {
            throw new InvalidBusinessSchema('A string physical default exceeds its declared length.');
        }

        return $value;
    }

    private function guidDefault(mixed $value): string
    {
        if (
            !is_string($value) || preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',
                $value,
            ) !== 1
        ) {
            throw new InvalidBusinessSchema('A GUID physical default is invalid.');
        }

        return strtolower($value);
    }

    private function integerDefault(PhysicalColumnBlueprint $column, mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) !== 1)) {
            throw new InvalidBusinessSchema('An integer physical default has an invalid exact representation.');
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($integer)) {
            throw new InvalidBusinessSchema('An integer physical default is outside the portable 64-bit range.');
        }
        if ($column->doctrineType === 'smallint' && ($integer < -32_768 || $integer > 32_767)) {
            throw new InvalidBusinessSchema('A small-integer physical default is outside its portable range.');
        }
        if (
            $column->doctrineType === 'integer'
            && ($integer < -2_147_483_648 || $integer > 2_147_483_647)
        ) {
            throw new InvalidBusinessSchema('An integer physical default is outside its portable range.');
        }

        return $integer;
    }

    private function decimalDefault(PhysicalColumnBlueprint $column, mixed $value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new InvalidBusinessSchema('A decimal physical default must be an exact numeric string.');
        }
        $value = (string) $value;
        if (preg_match('/^(-)?(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $matches) !== 1) {
            throw new InvalidBusinessSchema('A decimal physical default has an invalid exact representation.');
        }
        $precision = $column->options['precision'] ?? null;
        $scale = $column->options['scale'] ?? null;
        if (!is_int($precision) || !is_int($scale)) {
            throw new InvalidBusinessSchema('A decimal physical default has no declared precision and scale.');
        }
        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        if (strlen($fraction) > $scale) {
            throw new InvalidBusinessSchema('A decimal physical default exceeds its declared scale.');
        }
        $integerDigits = $integer === '0' ? 0 : strlen($integer);
        if ($integerDigits > $precision - $scale) {
            throw new InvalidBusinessSchema('A decimal physical default exceeds its declared precision.');
        }
        $fraction = str_pad($fraction, $scale, '0');
        $negative = ($matches[1] ?? '') === '-' && ($integer !== '0' || trim($fraction, '0') !== '');

        return ($negative ? '-' : '') . $integer . ($scale === 0 ? '' : '.' . $fraction);
    }

    private function dateDefault(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1) {
            throw new InvalidBusinessSchema('A date physical default must use canonical YYYY-MM-DD form.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (
            !$parsed instanceof DateTimeImmutable
            || $parsed->format('Y-m-d') !== $value
            || (int) substr($value, 0, 4) < 1000
        ) {
            throw new InvalidBusinessSchema('A date physical default is not a valid calendar date.');
        }

        return $value;
    }

    private function timeDefault(mixed $value): string
    {
        if (
            !is_string($value) || preg_match(
                '/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.([0-9]{1,6}))?$/D',
                $value,
                $matches,
            ) !== 1
        ) {
            throw new InvalidBusinessSchema('A time physical default must use canonical local-time form.');
        }
        $base = substr($value, 0, 8);

        return $base . '.' . str_pad($matches[2] ?? '', 6, '0');
    }

    private function dateTimeDefault(mixed $value): string
    {
        if (
            !is_string($value) || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}'
                . '(?:\.[0-9]{1,6})?(?:Z|\+00:00)?$/D',
                $value,
            ) !== 1
        ) {
            throw new InvalidBusinessSchema('A date-time physical default must be an exact UTC instant.');
        }
        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new InvalidBusinessSchema('A date-time physical default is invalid.', 0, $exception);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (
            ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->getOffset() !== 0
            || (int) substr($value, 0, 4) < 1000
        ) {
            throw new InvalidBusinessSchema('A date-time physical default is not a valid UTC instant.');
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @return array<string, int> */
    private function temporalPrecisions(Table $table): array
    {
        $platform = $this->database->getDatabasePlatform();
        $tableName = $table->getObjectName()->getUnqualifiedName()->getValue();
        if ($platform instanceof AbstractMySQLPlatform) {
            $rows = $this->database->fetchAllAssociative(
                'SELECT COLUMN_NAME AS column_name, DATETIME_PRECISION AS fractional_precision '
                . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$tableName],
            );
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $rows = $this->database->fetchAllAssociative(
                'SELECT column_name, datetime_precision AS fractional_precision '
                . 'FROM information_schema.columns WHERE table_catalog = current_database() '
                . 'AND table_schema = current_schema() AND table_name = ?',
                [$tableName],
            );
        } else {
            throw new InvalidBusinessSchema('The configured database has no temporal-precision introspector.');
        }

        $result = [];
        foreach ($rows as $row) {
            $column = $row['column_name'] ?? null;
            $precision = $row['fractional_precision'] ?? null;
            if (
                !is_string($column) || (!is_int($precision)
                && (!is_string($precision) || preg_match('/^[0-9]+$/D', $precision) !== 1))
            ) {
                continue;
            }
            $result[$column] = (int) $precision;
        }

        return $result;
    }

    private function hasIndex(Table $actual, PhysicalIndexBlueprint $expected): bool
    {
        foreach ($actual->getIndexes() as $index) {
            if ($index->getObjectName()?->getIdentifier()->getValue() !== $expected->physicalName) {
                continue;
            }
            $columns = array_map(
                static fn ($column): string => $column->getColumnName()->getIdentifier()->getValue(),
                $index->getIndexedColumns(),
            );

            return ($index->getType() === IndexType::UNIQUE) === $expected->unique
                && $columns === $expected->columns;
        }

        return false;
    }

    private function hasForeignKey(Table $actual, PhysicalForeignKeyBlueprint $expected): bool
    {
        foreach ($actual->getForeignKeys() as $foreignKey) {
            if ($this->constraintName($foreignKey) !== $expected->physicalName) {
                continue;
            }

            return $this->unqualifiedNames($foreignKey->getReferencingColumnNames()) === $expected->localColumns
                && $this->unqualifiedNames($foreignKey->getReferencedColumnNames()) === $expected->foreignColumns
                && $foreignKey->getReferencedTableName()->getUnqualifiedName()->getValue()
                    === $expected->foreignTable
                && $foreignKey->getOnDeleteAction()->value === $expected->onDelete
                && $foreignKey->getOnUpdateAction()->value === $expected->onUpdate;
        }

        return false;
    }

    private function foreignKeyNamed(Table $actual, string $physicalName): bool
    {
        foreach ($actual->getForeignKeys() as $foreignKey) {
            if ($this->constraintName($foreignKey) === $physicalName) {
                return true;
            }
        }

        return false;
    }

    private function constraintName(ForeignKeyConstraint $constraint): string
    {
        $name = $constraint->getObjectName();
        if ($name === null) {
            return '';
        }

        return $name->getIdentifier()->getValue();
    }

    /** @param array<\Doctrine\DBAL\Schema\Name\UnqualifiedName> $names @return list<string> */
    private function unqualifiedNames(array $names): array
    {
        return array_map(
            static fn (\Doctrine\DBAL\Schema\Name\UnqualifiedName $name): string =>
                $name->getIdentifier()->getValue(),
            $names,
        );
    }

    private function targetColumn(?PhysicalTableBlueprint $table, string $logical): PhysicalColumnBlueprint
    {
        return $table?->column($logical)
            ?? throw new InvalidBusinessSchema('A schema operation references an unknown target column.');
    }

    private function targetIndex(?PhysicalTableBlueprint $table, string $logical): PhysicalIndexBlueprint
    {
        return $this->findIndex($table, $logical)
            ?? throw new InvalidBusinessSchema('A schema operation references an unknown target index.');
    }

    private function findIndex(?PhysicalTableBlueprint $table, string $logical): ?PhysicalIndexBlueprint
    {
        foreach ($table?->indexes() ?? [] as $index) {
            if ($index->logicalName === $logical) {
                return $index;
            }
        }

        return null;
    }

    private function targetForeignKey(
        ?PhysicalTableBlueprint $table,
        string $logical,
    ): PhysicalForeignKeyBlueprint {
        return $this->findForeignKey($table, $logical)
            ?? throw new InvalidBusinessSchema('A schema operation references an unknown target foreign key.');
    }

    private function findForeignKey(
        ?PhysicalTableBlueprint $table,
        string $logical,
    ): ?PhysicalForeignKeyBlueprint {
        foreach ($table?->foreignKeys() ?? [] as $foreignKey) {
            if ($foreignKey->logicalName === $logical) {
                return $foreignKey;
            }
        }

        return null;
    }

    private function beforeTableName(SchemaOperation $operation): ?string
    {
        if ($operation->before === null) {
            return null;
        }
        $state = $operation->before;
        $name = $state['physical_name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            return null;
        }

        return $name;
    }

    /** @param array<string, mixed>|null $state @return array<string, mixed> */
    private function state(?array $state, string $subject): array
    {
        if ($state === null) {
            throw new InvalidBusinessSchema('A schema operation has no ' . $subject . ' state.');
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function physicalName(array $state): string
    {
        $name = $state['physical_name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new InvalidBusinessSchema('A schema operation contains an invalid compiled physical name.');
        }

        return $name;
    }

    private function backfillSatisfied(Table $actual, SchemaOperation $operation): bool
    {
        $state = $this->state($operation->after, 'backfill');
        $columnState = $state['column'] ?? null;
        if (!is_array($columnState) || array_is_list($columnState)) {
            throw new InvalidBusinessSchema('A backfill requires a canonical column.');
        }
        $column = PhysicalColumnBlueprint::fromArray($columnState);
        if (!$actual->hasColumn($column->physicalName)) {
            return false;
        }
        $tableName = $actual->getObjectName()->getUnqualifiedName()->getValue();

        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE %s IS NULL LIMIT 1',
            $this->database->quoteSingleIdentifier($tableName),
            $this->database->quoteSingleIdentifier($column->physicalName),
        )) === false;
    }

    private function repinSatisfied(
        Table $actual,
        PhysicalTableBlueprint $target,
        SchemaOperation $operation,
    ): bool {
        $state = $this->state($operation->after, 'record repin');
        $toVersion = $state['definition_version'] ?? null;
        if (!is_int($toVersion) || $toVersion < 2) {
            throw new InvalidBusinessSchema('A record-repin postcondition has an invalid target version.');
        }
        $tableName = $actual->getObjectName()->getUnqualifiedName()->getValue();
        $version = $target->column('definition_version');
        if ($version === null || !$actual->hasColumn($version->physicalName)) {
            throw new InvalidBusinessSchema('A record-repin table has no definition-version column.');
        }

        return $this->database->fetchOne(sprintf(
            'SELECT 1 FROM %s WHERE %s < ? LIMIT 1',
            $this->database->quoteSingleIdentifier($tableName),
            $this->database->quoteSingleIdentifier($version->physicalName),
        ), [$toVersion], [$version->doctrineType]) === false;
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
        throw new InvalidBusinessSchema('A physical primary-key column is missing from its blueprint.');
    }

    private function expressionValue(Expression $expression, string $field, mixed $value): bool|int|string|null
    {
        $type = $this->expressionFieldType($expression, $field);
        if ($value === null || $type === null || $type === 'any') {
            if ($value !== null && !is_bool($value) && !is_int($value) && !is_string($value)) {
                throw new InvalidBusinessSchema('A schema transform dependency is not an exact scalar.');
            }
            return $value;
        }

        return match ($type) {
            'boolean' => match ($value) {
                true, 1, '1', 't', 'true' => true,
                false, 0, '0', 'f', 'false' => false,
                default => throw new InvalidBusinessSchema('A schema transform boolean dependency is invalid.'),
            },
            'integer' => is_int($value)
                ? $value
                : ((is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1)
                    ? (int) $value
                    : throw new InvalidBusinessSchema('A schema transform integer dependency is invalid.')),
            'decimal', 'string', 'date', 'time', 'datetime' => is_string($value)
                ? $value
                : throw new InvalidBusinessSchema('A schema transform string dependency is invalid.'),
            default => throw new InvalidBusinessSchema('A schema transform dependency type is unsupported.'),
        };
    }

    private function expressionFieldType(Expression $expression, string $field): ?string
    {
        if ($expression->field === $field) {
            return $expression->type;
        }
        foreach ($expression->arguments() as $argument) {
            $type = $this->expressionFieldType($argument, $field);
            if ($type !== null) {
                return $type;
            }
        }

        return null;
    }
}
