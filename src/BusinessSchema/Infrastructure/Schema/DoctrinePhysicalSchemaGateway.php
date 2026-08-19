<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Infrastructure\Schema;

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
use Kumwe\App\BusinessDefinition\Domain\Expression;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\PhysicalSchemaGateway;
use Kumwe\App\BusinessSchema\Application\SchemaChunkResult;
use Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalForeignKeyBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalIndexBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Throwable;

/**
 * Doctrine DBAL binding of the physical schema gateway, for MySQL, MariaDB, and PostgreSQL.
 *
 * Nothing here composes DDL by hand. A shape change is applied by editing a clone of the introspected
 * `Schema` and executing whatever Doctrine's comparator derives from the difference, and the row
 * rewrites bind every value as a parameter with the surrounding identifiers passed through
 * `quoteSingleIdentifier()`. Verification then does the part Doctrine's introspection alone cannot:
 * fractional-second precision is read from `information_schema`, defaults are normalised into one exact
 * physical form before they are compared, and the few type names an engine reports back differently from
 * the way they were declared are accepted only where both spellings store identically. That is what
 * keeps a blueprint approved on one engine from being reported as drift on another. Any other platform
 * is refused rather than guessed at.
 *
 * @since  2.0.0
 */
final readonly class DoctrinePhysicalSchemaGateway implements PhysicalSchemaGateway
{
    /**
     * Bind the gateway to the connection every step is executed and verified through.
     *
     * @param  Connection  $database  Site database whose platform decides which introspection and
     *         type-matching rules apply.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database)
    {
    }

    /**
     * Confirm the expected blueprint is what the database actually holds.
     *
     * Each expected table that exists is introspected and compared exactly — column, index, and
     * foreign-key inventories in both directions, the primary key, and every column's type, options, and
     * default — so an object present in the database but absent from the blueprint is drift too. Tables
     * that do not exist are counted rather than compared, which is what makes "nothing installed"
     * distinguishable from "half installed".
     *
     * @param   PhysicalSchemaBlueprint  $expected  Blueprint the caller believes is installed.
     *
     * @return  ?PhysicalSchemaBlueprint  The same blueprint when every table is present and matches, null
     *          when not one of them exists.
     *
     * @throws  BusinessSchemaConflict  When a present table disagrees with the blueprint, the message
     *          naming the structural difference found, or when only some of the tables exist.
     * @throws  InvalidBusinessSchema  When the connected platform has no temporal-precision introspector.
     *
     * @since   2.0.0
     */
    public function inspect(PhysicalSchemaBlueprint $expected): ?PhysicalSchemaBlueprint
    {
        $manager = $this->database->createSchemaManager();
        $present = 0;
        foreach ($expected->tables() as $table) {
            if (!$manager->tablesExist([$table->physicalName])) {
                continue;
            }
            ++$present;
            $actual = $manager->introspectTableByUnquotedName($this->nonEmpty($table->physicalName));
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

    /**
     * Report whether the live database already looks the way one plan step intends to leave it.
     *
     * A create-table step is answered tolerantly: the table must carry everything the plan declared, but
     * objects it did not declare are ignored, so an engine's own additions do not force a redundant
     * retry. Table drops and renames are answered by the presence or absence of the name alone, and every
     * remaining kind by introspecting the one object it names — with a transform always reported
     * unsatisfied, because no shape of a table proves its values were recomputed.
     *
     * @param   SchemaOperation          $operation  Step whose intended end state is being checked.
     * @param   PhysicalSchemaBlueprint  $target     Blueprint the plan is moving the schema towards.
     *
     * @return  bool  True when the postcondition already holds, so the executor may skip the step.
     *
     * @throws  InvalidBusinessSchema  When the step names a table that is in neither the target blueprint
     *          nor its own prior state, when a state the check needs is missing or malformed, or when the
     *          connected platform has no temporal-precision introspector.
     *
     * @since   2.0.0
     */
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
                $manager->introspectTableByUnquotedName($this->nonEmpty($planned->physicalName)),
                $planned,
                false,
            );
        }
        $targetTable = $target->table($operation->table);
        $physicalTable = $targetTable->physicalName ?? $this->beforeTableName($operation);
        if ($physicalTable === null) {
            throw new InvalidBusinessSchema('A schema operation references an unknown table.');
        }
        $exists = $manager->tablesExist([$physicalTable]);

        return match ($operation->kind) {
            SchemaOperationKind::DropTable => !$exists,
            SchemaOperationKind::RenameTable => $exists,
            default => $exists && $this->objectSatisfied(
                $manager->introspectTableByUnquotedName($this->nonEmpty($physicalTable)),
                $targetTable,
                $operation,
            ),
        };
    }

    /**
     * Apply one approved shape-changing step to the live database.
     *
     * A step whose postcondition already holds returns without touching the database, which is what makes
     * re-running an interrupted plan safe. Creates and drops go straight to the schema manager; every
     * other kind is realised by editing a clone of the introspected schema and executing the statements
     * Doctrine's comparator derives, so the DDL dialect stays the platform's concern.
     *
     * @param   SchemaOperation          $operation  Approved step to realise as driver statements.
     * @param   PhysicalSchemaBlueprint  $target     Blueprint supplying the shape names resolve against.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the step rewrites rows, which belongs to the chunked methods,
     *          when its kind cannot be expressed as an alteration, or when a state it needs is missing or
     *          names an object the target blueprint does not declare.
     * @throws  BusinessSchemaConflict  When the table the step alters is no longer present by the time the
     *          alteration is built.
     *
     * @since   2.0.0
     */
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

    /**
     * Drop a table an earlier create-table step added, but only after proving it is untouched.
     *
     * The table must still match exactly the shape the step declared and must hold no row; either check
     * failing stops the compensation instead of destroying data, leaving the plan for an operator. An
     * already absent table is reported as nothing to do, so compensation is safe to repeat.
     *
     * @param   SchemaOperation  $operation  Completed create-table step being undone.
     *
     * @return  bool  True when the table was found and dropped, false when it was already absent.
     *
     * @throws  InvalidBusinessSchema  When the step is not a create-table step, its target state is
     *          missing, or the connected platform has no temporal-precision introspector.
     * @throws  BusinessSchemaConflict  When the table no longer matches the shape the step created, or
     *          holds at least one row.
     *
     * @since   2.0.0
     */
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
        $actual = $manager->introspectTableByUnquotedName($this->nonEmpty($planned->physicalName));
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

    /**
     * Report whether any stored record is still pinned to a definition version below the given one.
     *
     * Answered with one bounded existence query against the installed record table, so asking costs the
     * same whether a single row or a million rows are stale.
     *
     * @param   PhysicalSchemaBlueprint  $installed          Blueprint of the schema as currently installed.
     * @param   int                      $definitionVersion  Version rows must have reached to count as current.
     *
     * @return  bool  True when at least one record row predates that version.
     *
     * @throws  InvalidBusinessSchema  When the boundary version is below one, or the installed blueprint
     *          has no record table or no definition-version column on it.
     * @throws  BusinessSchemaConflict  When the installed record table is not present in the database.
     *
     * @since   2.0.0
     */
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

    /**
     * Fill one bounded batch of rows whose new column is still unset.
     *
     * Rows are read in identity order from the cursor and only where the target column is still null, and
     * each update repeats that null test, so a repeated batch overwrites neither a value an earlier pass
     * computed nor one a concurrent writer supplied. The value is the literal the step carries or the
     * result of its bounded expression, evaluated per row from dependency columns read in the same
     * select; a result that is null or cannot be stored exactly stops the batch rather than being
     * coerced.
     *
     * @param   SchemaOperation                      $operation  Approved backfill step and its source value.
     * @param   PhysicalSchemaBlueprint              $target     Blueprint the column belongs to.
     * @param   array<string, bool|int|string>|null  $cursor     Where the previous batch stopped, or null to start.
     * @param   int                                  $limit      Rows this batch may read, from one to 1000.
     *
     * @return  SchemaChunkResult  Rows filled and the position to resume from; complete once the batch
     *          reads fewer rows than the limit.
     *
     * @throws  InvalidBusinessSchema  When the step is not a backfill or the limit is out of bounds, the
     *          target table is missing or not singly keyed, the state carries no canonical column with
     *          exactly one literal or expression source and a complete dependency map, the column is
     *          absent from the approved target, the cursor is malformed, or a computed value is null or
     *          cannot be stored exactly.
     * @throws  BusinessSchemaConflict  When a visited row carries an identity that is neither an integer
     *          nor a string, so it cannot be bound as a parameter.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored expression
     *          cannot be rebuilt, or evaluating it against a row's values fails.
     *
     * @since   2.0.0
     */
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
        /** @var array<string, mixed> $columnState */
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
                /** @var array<string, mixed> $document */
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

    /**
     * Recompute one bounded batch of rows into the shadow column a type change writes through.
     *
     * Every row in identity order is rewritten rather than only the unset ones, because the new value is
     * derived from the row and nothing about the table's shape proves it was already produced; a failed
     * batch therefore resumes from its last checkpoint and repeats work instead of skipping it. The
     * expression's dependency columns are read in the same select and coerced to the types the expression
     * declares, so a driver that reports integers as strings does not change the result.
     *
     * @param   SchemaOperation                      $operation  Approved transform step and its expression.
     * @param   PhysicalSchemaBlueprint              $target     Blueprint both columns belong to.
     * @param   array<string, bool|int|string>|null  $cursor     Where the previous batch stopped, or null to start.
     * @param   int                                  $limit      Rows this batch may read, from one to 1000.
     *
     * @return  SchemaChunkResult  Rows recomputed and the position to resume from; complete once the
     *          batch reads fewer rows than the limit.
     *
     * @throws  InvalidBusinessSchema  When the step is not a transform or the limit is out of bounds, the
     *          target table is missing, a canonical state is malformed, the state does not name exactly
     *          one identity column that belongs to the target primary key, a dependency the expression
     *          reads is unavailable or contradicts its declared type, the cursor is malformed, or a
     *          computed value cannot be stored exactly.
     * @throws  BusinessSchemaConflict  When a visited row carries an identity that is neither an integer
     *          nor a string, so it cannot be bound as a parameter.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored expression
     *          cannot be rebuilt, or evaluating it against a row's values fails.
     *
     * @since   2.0.0
     */
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
            /** @var array<string, mixed> $document */
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

    /**
     * Express one operation as edits to the in-memory schema clone the comparator will diff.
     *
     * Nothing here reaches the database: the clone is edited so Doctrine can derive the alteration
     * statements for the platform in use. A validate-constraint step is accepted and edits nothing,
     * because the portable path defers no constraint that would need validating.
     *
     * @param   Schema                   $schema     Clone of the introspected schema, edited in place.
     * @param   SchemaOperation          $operation  Step to express as edits to that clone.
     * @param   ?PhysicalTableBlueprint  $target     Target shape of the affected table, or null.
     *
     * @return  void
     *
     * @throws  BusinessSchemaConflict  When the table the step names is absent from the introspected
     *          schema.
     * @throws  InvalidBusinessSchema  When a state the step needs is missing or malformed, it names an
     *          object the target table does not declare, or its kind has no alteration form.
     *
     * @since   2.0.0
     */
    private function mutate(
        Schema $schema,
        SchemaOperation $operation,
        ?PhysicalTableBlueprint $target,
    ): void {
        $tableName = $target->physicalName ?? $this->beforeTableName($operation);
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
                $table->renameColumn(
                    $this->nonEmpty($before->physicalName),
                    $this->nonEmpty($column->physicalName),
                );
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
                $table->dropForeignKey($foreignKey->physicalName);
                return;

            case SchemaOperationKind::AddPrimaryKey:
                if ($target === null) {
                    throw new InvalidBusinessSchema('An add-primary-key operation has no target table.');
                }
                $table->addPrimaryKeyConstraint(
                    PrimaryKeyConstraint::editor()
                        ->setUnquotedColumnNames(...$this->nonEmptyNames($target->primaryKey))
                        ->create(),
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

    /**
     * Build the Doctrine table a create-table step installs.
     *
     * @param   PhysicalTableBlueprint  $blueprint  Compiled shape of the table to create.
     *
     * @return  Table  Table carrying every column, the primary key, the indexes, and the foreign keys the
     *          blueprint declares.
     *
     * @throws  InvalidBusinessSchema  When the blueprint holds an empty identifier, or a column default
     *          its Doctrine type cannot carry exactly.
     *
     * @since   2.0.0
     */
    private function doctrineTable(PhysicalTableBlueprint $blueprint): Table
    {
        $table = new Table($blueprint->physicalName);
        foreach ($blueprint->columns() as $column) {
            $this->addColumn($table, $column);
        }
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames(...$this->nonEmptyNames($blueprint->primaryKey))
                ->create(),
        );
        foreach ($blueprint->indexes() as $index) {
            $this->addIndex($table, $index);
        }
        foreach ($blueprint->foreignKeys() as $foreignKey) {
            $this->addForeignKey($table, $foreignKey);
        }

        return $table;
    }

    /**
     * Add one blueprint column to a Doctrine table.
     *
     * @param   Table                    $table   Table being built or altered.
     * @param   PhysicalColumnBlueprint  $column  Column to add, with its portable options.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the column's default cannot be expressed exactly in its
     *          Doctrine type.
     *
     * @since   2.0.0
     */
    private function addColumn(Table $table, PhysicalColumnBlueprint $column): void
    {
        $table->addColumn($column->physicalName, $column->doctrineType, $this->columnOptions($column));
    }

    /**
     * Translate a blueprint column's portable options into the array Doctrine's table API expects.
     *
     * Nullability is handed over as Doctrine's `notnull` rather than the blueprint's own property, and a
     * declared default is first normalised into the single exact physical form the drift check compares
     * against, so the value written at install time and the value read back later are the same string.
     *
     * @param   PhysicalColumnBlueprint  $column  Column whose options are being translated.
     *
     * @return  array<string, mixed>  Doctrine column options, always carrying `notnull`.
     *
     * @throws  InvalidBusinessSchema  When the declared default cannot be expressed exactly in the
     *          column's Doctrine type.
     *
     * @since   2.0.0
     */
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

    /**
     * Add one blueprint index to a Doctrine table, as a unique constraint or a plain index.
     *
     * @param   Table                   $table  Table being built or altered.
     * @param   PhysicalIndexBlueprint  $index  Index to add, with its columns in key order.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the index declares no columns, or an empty column identifier.
     *
     * @since   2.0.0
     */
    private function addIndex(Table $table, PhysicalIndexBlueprint $index): void
    {
        if ($index->unique) {
            $table->addUniqueIndex($this->nonEmptyNames($index->columns), $index->physicalName, $index->options);
            return;
        }
        $table->addIndex($this->nonEmptyNames($index->columns), $index->physicalName, [], $index->options);
    }

    /**
     * Add one blueprint foreign key to a Doctrine table, with both of its referential actions.
     *
     * @param   Table                        $table       Table being built or altered.
     * @param   PhysicalForeignKeyBlueprint  $foreignKey  Constraint to add, with both column lists.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When either column list is empty or holds an empty identifier.
     *
     * @since   2.0.0
     */
    private function addForeignKey(Table $table, PhysicalForeignKeyBlueprint $foreignKey): void
    {
        $table->addForeignKeyConstraint(
            $foreignKey->foreignTable,
            $this->nonEmptyNames($foreignKey->localColumns),
            $this->nonEmptyNames($foreignKey->foreignColumns),
            ['onDelete' => $foreignKey->onDelete, 'onUpdate' => $foreignKey->onUpdate],
            $foreignKey->physicalName,
        );
    }

    /**
     * Decide whether one already-introspected table proves a step below table level was applied.
     *
     * Add and alter kinds need the object to be present and to agree with the target blueprint down to
     * its options; drop kinds need it to be gone. A transform always answers false, because its effect
     * lives in row values rather than in the table's shape, and a validate-constraint step always answers
     * true, because the portable path emits no statement for it.
     *
     * @param   Table                    $actual     Live table as Doctrine introspected it.
     * @param   ?PhysicalTableBlueprint  $target     Target shape of that table, or null.
     * @param   SchemaOperation          $operation  Step whose postcondition is being checked.
     *
     * @return  bool  True when the step's effect is already visible in the live table.
     *
     * @throws  InvalidBusinessSchema  When a state the check needs is missing or malformed, or the
     *          connected platform has no temporal-precision introspector.
     *
     * @since   2.0.0
     */
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
            SchemaOperationKind::AddColumn => $actual->hasColumn(($column = PhysicalColumnBlueprint::fromArray(
                $this->state($operation->after, 'target column'),
            ))->physicalName)
                && $this->columnMatches(
                    $actual->getColumn($column->physicalName),
                    $column,
                    $temporalPrecisions,
                ),
            SchemaOperationKind::RenameColumn => !$actual->hasColumn(($before = PhysicalColumnBlueprint::fromArray(
                $this->state($operation->before, 'prior column'),
            ))->physicalName)
                && $actual->hasColumn(($after = PhysicalColumnBlueprint::fromArray(
                    $this->state($operation->after, 'target column'),
                ))->physicalName)
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

    /**
     * Compare a live table against the blueprint it was compiled from.
     *
     * Exact mode compares the column, index, and foreign-key inventories in both directions, so an object
     * the database has and the blueprint does not is a mismatch; that is the mode drift detection and
     * compensation need. Tolerant mode only requires the declared objects to be there, which is what lets
     * a create-table step count as satisfied. An index an engine materialises for the primary key is
     * filtered out of that inventory rather than counted as an extra.
     *
     * @param   Table                   $actual    Live table as Doctrine introspected it.
     * @param   PhysicalTableBlueprint  $expected  Compiled shape the table should have.
     * @param   bool                    $exact     Whether undeclared objects count as a mismatch.
     *
     * @return  bool  True when the table satisfies the blueprint at the requested strictness.
     *
     * @throws  InvalidBusinessSchema  When the connected platform has no temporal-precision introspector.
     *
     * @since   2.0.0
     */
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

    /**
     * Name the structural difference that made a live table fail its blueprint.
     *
     * Repeats the exact comparison and reports only identifiers and inventories, so the conflict message
     * an operator reads carries enough evidence to act on without exposing a single stored value.
     *
     * @param   Table                   $actual    Live table that failed the comparison.
     * @param   PhysicalTableBlueprint  $expected  Compiled shape it was measured against.
     *
     * @return  string  Short phrase naming the first difference found, or an unclassified-mismatch phrase
     *          when no individual check reproduces the failure.
     *
     * @throws  InvalidBusinessSchema  When the connected platform has no temporal-precision introspector.
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether one live column carries exactly the shape its blueprint declares.
     *
     * Past type and nullability this pins the details a portable schema depends on: a column carrying a
     * time of day must have been created with microsecond precision, a declared default must normalise
     * to the same physical form as the introspected one, and a column the blueprint gives no default must
     * have none. Options the engine cannot report back are skipped rather than guessed at.
     *
     * @param   Column                   $actual              Live column as Doctrine introspected it.
     * @param   PhysicalColumnBlueprint  $expected            Compiled shape the column should have.
     * @param   array<string, int>       $temporalPrecisions  Fractional-second digits, by physical column name.
     *
     * @return  bool  True when every declared aspect of the column agrees with the blueprint.
     *
     * @since   2.0.0
     */
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
        foreach (['length', 'precision', 'scale'] as $key) {
            if ($this->physicalOptionIsNotIntrospectable($expected, $key)) {
                continue;
            }
            $actualValue = match ($key) {
                'length' => $actual->getLength(),
                'precision' => $actual->getPrecision(),
                'scale' => $actual->getScale(),
            };
            if (array_key_exists($key, $expected->options) && $actualValue !== $expected->options[$key]) {
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

    /**
     * Decide whether a live column's type is the declared one, or an alias that stores identically.
     *
     * Introspection does not round-trip every type name — an engine reports the storage it actually used,
     * so `datetime_immutable` comes back as `datetime` and a GUID comes back as a string. The alias table
     * is therefore restricted to MySQL and PostgreSQL and to pairs that behave the same, and two cases
     * are refused up front: a PostgreSQL `json` column created as `jsonb`, and a MySQL GUID not stored as
     * a fixed 36 characters. Both would otherwise pass as equal while behaving differently.
     *
     * @param   Column                   $actual    Live column as Doctrine introspected it.
     * @param   PhysicalColumnBlueprint  $expected  Compiled column declaring the intended type.
     *
     * @return  bool  True when the introspected type is the declared one or an accepted equivalent.
     *
     * @since   2.0.0
     */
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

    /**
     * Report whether the platform cannot read an option back, so comparing it would be meaningless.
     *
     * PostgreSQL stores a `binary` column as `bytea`, which carries neither a length nor a fixed-width
     * flag, so those two options are dropped from the drift check instead of being reported as
     * differences on every inspection.
     *
     * @param   PhysicalColumnBlueprint  $column  Column the option was declared on.
     * @param   string                   $option  Doctrine option name, such as `length` or `fixed`.
     *
     * @return  bool  True when the option cannot be introspected on this platform and type.
     *
     * @since   2.0.0
     */
    private function physicalOptionIsNotIntrospectable(
        PhysicalColumnBlueprint $column,
        string $option,
    ): bool {
        return $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            && $column->doctrineType === 'binary'
            && in_array($option, ['length', 'fixed'], true);
    }

    /**
     * Decide whether the default an engine reports is the one the blueprint declares.
     *
     * Engines spell defaults their own way, so both sides are normalised into one exact physical form and
     * compared as strings; a side that cannot be normalised counts as a difference rather than raising,
     * because an unreadable default is drift, not a broken plan. A boolean column whose blueprint default
     * really is a boolean takes a separate path instead, because introspection reports those as `1`, `t`,
     * `'true'`, and several other forms depending on the driver.
     *
     * @param   mixed                    $actual    Default as introspected, or null when there is none.
     * @param   mixed                    $expected  Default the blueprint declares, or null.
     * @param   PhysicalColumnBlueprint  $column    Column supplying the exact type both are read in.
     *
     * @return  bool  True when both sides mean the same stored default.
     *
     * @since   2.0.0
     */
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

    /**
     * Normalise a value into the one exact physical form its column type stores.
     *
     * This is the single place that decides what "the same value" means for a column: decimals gain their
     * declared scale, temporal values gain microseconds, GUIDs are lower-cased, and anything the type
     * cannot carry exactly is refused instead of rounded. Types with no canonical form pass through.
     *
     * @param   PhysicalColumnBlueprint  $column  Column whose Doctrine type selects the normalisation.
     * @param   mixed                    $value   Declared default or computed rewrite value.
     *
     * @return  mixed  The canonical representation for the column's type, or the value unchanged when its
     *          type declares no canonical form.
     *
     * @throws  InvalidBusinessSchema  When the value cannot be expressed exactly in the column's type.
     *
     * @since   2.0.0
     */
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

    /**
     * Convert a rewrite value into the PHP value Doctrine's parameter binding expects for the column.
     *
     * Backfill and transform values arrive as canonical strings, but the immutable date and time types
     * bind a `DateTimeImmutable`, so a temporal value is parsed back in UTC after normalisation. Nulls
     * and values already of that class pass straight through; everything else is bound normalised.
     *
     * @param   PhysicalColumnBlueprint  $column  Column the value is about to be written to.
     * @param   mixed                    $value   Literal or computed value produced for one row.
     *
     * @return  mixed  A value the column's Doctrine type can bind, temporal types as `DateTimeImmutable`.
     *
     * @throws  InvalidBusinessSchema  When the value is not exact for the column's type, or a temporal
     *          value does not parse back from its canonical form.
     *
     * @since   2.0.0
     */
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

    /**
     * Accept a boolean value only when it already is a boolean.
     *
     * Nothing is coerced on the way in: a blueprint that reached this point with `1` or `'true'` was
     * built wrong, and accepting it would hide the mistake behind a column that installs cleanly.
     *
     * @param   mixed  $value  Declared default or rewrite value for a boolean column.
     *
     * @return  bool  The same value.
     *
     * @throws  InvalidBusinessSchema  When the value is anything other than a boolean.
     *
     * @since   2.0.0
     */
    private function booleanDefault(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw new InvalidBusinessSchema('A boolean physical default or rewrite value must be exact.');
        }

        return $value;
    }

    /**
     * Accept a string value and prove it fits the column's declared length.
     *
     * The length is measured in characters rather than bytes, matching how the engines count a declared
     * `VARCHAR` width, so a multi-byte default is not rejected for a limit it does not actually breach.
     *
     * @param   PhysicalColumnBlueprint  $column  Column supplying the declared length, where it has one.
     * @param   mixed                    $value   Declared default or rewrite value for a string column.
     *
     * @return  string  The same string, unchanged.
     *
     * @throws  InvalidBusinessSchema  When the value is not a string, or is longer than the declared
     *          length.
     *
     * @since   2.0.0
     */
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

    /**
     * Accept a GUID value and reduce it to the lowercase form comparisons are made in.
     *
     * @param   mixed  $value  Declared default or rewrite value for a GUID column.
     *
     * @return  string  The same GUID in lowercase 8-4-4-4-12 form.
     *
     * @throws  InvalidBusinessSchema  When the value is not a hyphenated 36-character hexadecimal GUID.
     *
     * @since   2.0.0
     */
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

    /**
     * Accept an integer value and prove it fits the portable range of its declared width.
     *
     * A digit string is accepted alongside a real integer, because that is how introspection reports the
     * value back. The bounds applied are the portable ones rather than the current engine's, so a value
     * that only one platform would accept is refused here instead of failing at install time elsewhere.
     *
     * @param   PhysicalColumnBlueprint  $column  Column supplying the integer width to bound against.
     * @param   mixed                    $value   Declared default or rewrite value for an integer column.
     *
     * @return  int  The value as an integer inside the column's portable range.
     *
     * @throws  InvalidBusinessSchema  When the value is not an exact integer representation, or falls
     *          outside the 64-bit, integer, or small-integer range that applies.
     *
     * @since   2.0.0
     */
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

    /**
     * Accept a decimal value and render it at the column's declared precision and scale.
     *
     * The fraction is padded to the full scale so that `1.25` and `1.2500` compare equal on a
     * `DECIMAL(12,4)` column, the integer part is measured against the digits the precision leaves for
     * it, and a value that normalises to zero loses its sign so no negative zero reaches the database.
     *
     * @param   PhysicalColumnBlueprint  $column  Column supplying the declared precision and scale.
     * @param   mixed                    $value   Declared default or rewrite value for a decimal column.
     *
     * @return  string  Base-10 literal padded to the declared scale, with no point at scale zero.
     *
     * @throws  InvalidBusinessSchema  When the value is not an exact decimal literal, the column declares
     *          no precision and scale, or the value exceeds either of them.
     *
     * @since   2.0.0
     */
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
        $negative = $matches[1] === '-' && ($integer !== '0' || trim($fraction, '0') !== '');

        return ($negative ? '-' : '') . $integer . ($scale === 0 ? '' : '.' . $fraction);
    }

    /**
     * Accept a date value in canonical `YYYY-MM-DD` form and prove it is a real calendar date.
     *
     * Re-formatting the parsed date and comparing it back is what rejects a value such as `2026-02-30`,
     * which the parser would otherwise roll forward into March. Years below 1000 are refused so the
     * four-digit form never has to be padded.
     *
     * @param   mixed  $value  Declared default or rewrite value for a date column.
     *
     * @return  string  The same canonical date string, unchanged.
     *
     * @throws  InvalidBusinessSchema  When the value is not in canonical form, or is not a real date.
     *
     * @since   2.0.0
     */
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

    /**
     * Accept a local time value and pad it out to full microsecond precision.
     *
     * The padding is what lets a declared `13:14:15` compare equal to the `13:14:15.000000` an engine
     * reports back from a column the gateway insists was created with microsecond precision.
     *
     * @param   mixed  $value  Declared default or rewrite value for a time column.
     *
     * @return  string  The time as `HH:MM:SS.uuuuuu`.
     *
     * @throws  InvalidBusinessSchema  When the value is not a canonical local time, with or without a
     *          fractional part.
     *
     * @since   2.0.0
     */
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

    /**
     * Accept an instant and render it as a UTC timestamp carrying microseconds.
     *
     * Only UTC is accepted — bare, `Z`, or `+00:00` — because the stored form keeps no zone, so any other
     * offset would silently shift the value. Parser warnings count as failures, and years below 1000 are
     * refused so the four-digit form never has to be padded.
     *
     * @param   mixed  $value  Declared default or rewrite value for a date-time column.
     *
     * @return  string  The instant as `Y-m-d H:i:s.u` in UTC.
     *
     * @throws  InvalidBusinessSchema  When the value is not a canonical UTC instant, or does not parse
     *          cleanly into one.
     *
     * @since   2.0.0
     */
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

    /**
     * Read every column's fractional-second precision straight from `information_schema`.
     *
     * Doctrine's introspection does not report this, yet a temporal column created without microseconds
     * silently truncates what is written to it, so the drift check measures it here rather than assuming
     * it. Only MySQL, MariaDB, and PostgreSQL have a query; any other platform is refused, which is what
     * confines this gateway to the engines whose behaviour it has actually been reconciled against.
     *
     * @param   Table  $table  Live table whose columns are being measured.
     *
     * @return  array<string, int>  Fractional-second digits by physical column name; a column the engine
     *          reports no precision for, such as a non-temporal one, is absent.
     *
     * @throws  InvalidBusinessSchema  When the connected platform is neither MySQL-like nor PostgreSQL.
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether a live table carries the index its blueprint declares, under the same name.
     *
     * Uniqueness and the ordered column list must both agree, so an index covering the same columns in
     * another order is not accepted as a match: the two serve different queries.
     *
     * @param   Table                   $actual    Live table as Doctrine introspected it.
     * @param   PhysicalIndexBlueprint  $expected  Index the blueprint declares.
     *
     * @return  bool  True when an index of that name exists with the declared uniqueness and columns.
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether a live table carries the foreign key its blueprint declares, under the same name.
     *
     * Both column lists, the referenced table, and the delete and update actions must all agree, because
     * a constraint pointing at the right table with the wrong action enforces a different rule.
     *
     * @param   Table                        $actual    Live table as Doctrine introspected it.
     * @param   PhysicalForeignKeyBlueprint  $expected  Constraint the blueprint declares.
     *
     * @return  bool  True when a constraint of that name agrees in every compared aspect.
     *
     * @since   2.0.0
     */
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

    /**
     * Report whether a live table still carries a constraint under one installed name.
     *
     * Verifying a drop only needs the name to be gone, and the constraint's former shape is no longer
     * available to compare against anyway, so this deliberately checks nothing else.
     *
     * @param   Table   $actual        Live table as Doctrine introspected it.
     * @param   string  $physicalName  Installed constraint name to look for.
     *
     * @return  bool  True when a constraint of that name is still present.
     *
     * @since   2.0.0
     */
    private function foreignKeyNamed(Table $actual, string $physicalName): bool
    {
        foreach ($actual->getForeignKeys() as $foreignKey) {
            if ($this->constraintName($foreignKey) === $physicalName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the installed name of a foreign-key constraint.
     *
     * @param   ForeignKeyConstraint  $constraint  Constraint as Doctrine introspected it.
     *
     * @return  string  The identifier, or an empty string when the engine reported none, which never
     *          matches a compiled blueprint name.
     *
     * @since   2.0.0
     */
    private function constraintName(ForeignKeyConstraint $constraint): string
    {
        $name = $constraint->getObjectName();
        if ($name === null) {
            return '';
        }

        return $name->getIdentifier()->getValue();
    }

    /**
     * Reduce Doctrine's introspected name objects to the plain strings a blueprint compares against.
     *
     * @param   list<\Doctrine\DBAL\Schema\Name\UnqualifiedName>  $names  Column names, in the order the
     *          key or constraint declares them.
     *
     * @return  list<string>  The identifiers in that same order, ready to compare with a blueprint list.
     *
     * @since   2.0.0
     */
    private function unqualifiedNames(array $names): array
    {
        return array_map(
            static fn (\Doctrine\DBAL\Schema\Name\UnqualifiedName $name): string =>
                $name->getIdentifier()->getValue(),
            $names,
        );
    }

    /**
     * Resolve the column a step's subject names in the target table, or refuse the step.
     *
     * @param   ?PhysicalTableBlueprint  $table    Target shape of the affected table, or null.
     * @param   string                   $logical  Logical column handle taken from the step's subject.
     *
     * @return  PhysicalColumnBlueprint  The column the target blueprint declares under that handle.
     *
     * @throws  InvalidBusinessSchema  When there is no target table, or it declares no such column.
     *
     * @since   2.0.0
     */
    private function targetColumn(?PhysicalTableBlueprint $table, string $logical): PhysicalColumnBlueprint
    {
        return $table?->column($logical)
            ?? throw new InvalidBusinessSchema('A schema operation references an unknown target column.');
    }

    /**
     * Resolve the index a step's subject names in the target table, or refuse the step.
     *
     * @param   ?PhysicalTableBlueprint  $table    Target shape of the affected table, or null.
     * @param   string                   $logical  Logical index handle taken from the step's subject.
     *
     * @return  PhysicalIndexBlueprint  The index the target blueprint declares under that handle.
     *
     * @throws  InvalidBusinessSchema  When there is no target table, or it declares no such index.
     *
     * @since   2.0.0
     */
    private function targetIndex(?PhysicalTableBlueprint $table, string $logical): PhysicalIndexBlueprint
    {
        return $this->findIndex($table, $logical)
            ?? throw new InvalidBusinessSchema('A schema operation references an unknown target index.');
    }

    /**
     * Look an index up in the target table by the logical handle a step names it with.
     *
     * @param   ?PhysicalTableBlueprint  $table    Target shape of the affected table, or null.
     * @param   string                   $logical  Logical index handle taken from the step's subject.
     *
     * @return  ?PhysicalIndexBlueprint  The matching index, or null when there is no target table or it
     *          declares no index under that handle.
     *
     * @since   2.0.0
     */
    private function findIndex(?PhysicalTableBlueprint $table, string $logical): ?PhysicalIndexBlueprint
    {
        foreach ($table?->indexes() ?? [] as $index) {
            if ($index->logicalName === $logical) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Resolve the foreign key a step's subject names in the target table, or refuse the step.
     *
     * @param   ?PhysicalTableBlueprint  $table    Target shape of the affected table, or null.
     * @param   string                   $logical  Logical constraint handle from the step's subject.
     *
     * @return  PhysicalForeignKeyBlueprint  The constraint the target blueprint declares under that
     *          handle.
     *
     * @throws  InvalidBusinessSchema  When there is no target table, or it declares no such constraint.
     *
     * @since   2.0.0
     */
    private function targetForeignKey(
        ?PhysicalTableBlueprint $table,
        string $logical,
    ): PhysicalForeignKeyBlueprint {
        return $this->findForeignKey($table, $logical)
            ?? throw new InvalidBusinessSchema('A schema operation references an unknown target foreign key.');
    }

    /**
     * Look a foreign key up in the target table by the logical handle a step names it with.
     *
     * @param   ?PhysicalTableBlueprint  $table    Target shape of the affected table, or null.
     * @param   string                   $logical  Logical constraint handle from the step's subject.
     *
     * @return  ?PhysicalForeignKeyBlueprint  The matching constraint, or null when there is no target
     *          table or it declares none under that handle.
     *
     * @since   2.0.0
     */
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

    /**
     * Recover the installed table name from a step's prior state.
     *
     * Drop and rename steps act on a table the target blueprint no longer declares under that name, so
     * the state captured before the plan ran is the only record of it. The stored name is re-checked
     * against the compiled identifier grammar on the way out, because it is the one identifier in a step
     * that comes from persisted state rather than from a validated blueprint.
     *
     * @param   SchemaOperation  $operation  Step whose prior state may carry the installed name.
     *
     * @return  ?string  The installed table name, or null when the step has no prior state or the stored
     *          name is not a valid compiled identifier.
     *
     * @since   2.0.0
     */
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

    /**
     * Prove a compiled identifier is non-empty before handing it to an API that requires one.
     *
     * Blueprint validation already rules an empty name out; this keeps that guarantee visible to static
     * analysis at the call site instead of leaving it assumed.
     *
     * @param   string  $identifier  Compiled physical name taken from a blueprint.
     *
     * @return  non-empty-string  The same identifier.
     *
     * @throws  InvalidBusinessSchema  When the identifier is an empty string.
     *
     * @since   2.0.0
     */
    private function nonEmpty(string $identifier): string
    {
        if ($identifier === '') {
            throw new InvalidBusinessSchema('A compiled physical identifier is empty.');
        }

        return $identifier;
    }

    /**
     * Prove a compiled identifier list is non-empty and holds no empty name.
     *
     * @param   list<string>  $identifiers  Compiled physical names, such as an index or key column list.
     *
     * @return  non-empty-list<non-empty-string>  The same names, in the same order.
     *
     * @throws  InvalidBusinessSchema  When the list is empty, or any entry is an empty string.
     *
     * @since   2.0.0
     */
    private function nonEmptyNames(array $identifiers): array
    {
        if ($identifiers === []) {
            throw new InvalidBusinessSchema('A compiled physical identifier list is empty.');
        }

        return array_map($this->nonEmpty(...), $identifiers);
    }

    /**
     * Require the prior or target state a step needs before anything reads into it.
     *
     * @param   array<string, mixed>|null  $state    Prior or target state taken from the step.
     * @param   string                     $subject  What the state describes, named in the failure
     *          message so an operator can tell which half of the step is missing.
     *
     * @return  array<string, mixed>  The same state, proven present.
     *
     * @throws  InvalidBusinessSchema  When the step carries no state for that subject.
     *
     * @since   2.0.0
     */
    private function state(?array $state, string $subject): array
    {
        if ($state === null) {
            throw new InvalidBusinessSchema('A schema operation has no ' . $subject . ' state.');
        }

        return $state;
    }

    /**
     * Read the installed table name a rename step's target state declares.
     *
     * Re-checked against the compiled identifier grammar because it arrives from persisted state and goes
     * on to name a table in the rename Doctrine generates.
     *
     * @param   array<string, mixed>  $state  Target state of a rename-table step.
     *
     * @return  non-empty-string  The validated installed table name.
     *
     * @throws  InvalidBusinessSchema  When the state carries no `physical_name`, or the stored value
     *          breaks the compiled identifier grammar.
     *
     * @since   2.0.0
     */
    private function physicalName(array $state): string
    {
        $name = $state['physical_name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new InvalidBusinessSchema('A schema operation contains an invalid compiled physical name.');
        }

        return $name;
    }

    /**
     * Decide whether a backfill left no row with its target column still unset.
     *
     * Answered with a bounded existence query rather than a count, so the check costs the same however
     * much is left. A column that is not there yet answers false rather than raising, because the step
     * that adds it may simply not have run.
     *
     * @param   Table            $actual     Live table as Doctrine introspected it.
     * @param   SchemaOperation  $operation  Backfill step whose target column is being checked.
     *
     * @return  bool  True when no row of the table still holds null in that column.
     *
     * @throws  InvalidBusinessSchema  When the step's target state carries no canonical column, or the
     *          column cannot be rebuilt from it.
     *
     * @since   2.0.0
     */
    private function backfillSatisfied(Table $actual, SchemaOperation $operation): bool
    {
        $state = $this->state($operation->after, 'backfill');
        $columnState = $state['column'] ?? null;
        if (!is_array($columnState) || array_is_list($columnState)) {
            throw new InvalidBusinessSchema('A backfill requires a canonical column.');
        }
        /** @var array<string, mixed> $columnState */
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

    /**
     * Decide whether a record-repin step advanced every row to its new definition version.
     *
     * Answered with a bounded existence query for one row still pinned below the target version, the same
     * shape of test the planner uses before it allows a narrowing change to run at all.
     *
     * @param   Table                   $actual     Live table as Doctrine introspected it.
     * @param   PhysicalTableBlueprint  $target     Target shape supplying the definition-version column.
     * @param   SchemaOperation         $operation  Repin step declaring the version rows must reach.
     *
     * @return  bool  True when no row is pinned below the step's target version.
     *
     * @throws  InvalidBusinessSchema  When the step declares no target version of at least two, or the
     *          table has no definition-version column to test.
     *
     * @since   2.0.0
     */
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

    /**
     * Resolve a blueprint column from its installed name.
     *
     * A chunked rewrite needs the identity column's Doctrine type to bind its keyset cursor, and a
     * primary key names installed columns rather than logical handles, so the lookup goes this way round.
     * A miss is refused rather than returned as null, because the caller has no rewrite to fall back on.
     *
     * @param   PhysicalTableBlueprint  $table         Target shape the column must belong to.
     * @param   string                  $physicalName  Installed column name, as the primary key spells it.
     *
     * @return  PhysicalColumnBlueprint  The column declared under that installed name.
     *
     * @throws  InvalidBusinessSchema  When the table declares no column with that installed name.
     *
     * @since   2.0.0
     */
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

    /**
     * Coerce one column value a row supplied into the exact type the expression declares for that field.
     *
     * Drivers report the same column differently — an integer as a digit string, a boolean as `t` or `1`
     * — while the evaluator refuses a value that contradicts its declared type, so the reconciliation has
     * to happen here rather than inside the expression. A field the tree declares no type for is passed
     * through, but never as a float or an object, because a rewrite must stay exact.
     *
     * @param   Expression  $expression  Tree the field belongs to, supplying its declared type.
     * @param   string      $field       Field handle the value was read for.
     * @param   mixed       $value       Raw column value exactly as the driver returned it.
     *
     * @return  bool|int|string|null  The value in its declared type, or null when the column was null.
     *
     * @throws  InvalidBusinessSchema  When an untyped value is not an exact scalar, a typed value
     *          contradicts its declared type, or the declared type is one no rewrite supports.
     *
     * @since   2.0.0
     */
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

    /**
     * Find the result type an expression tree declares for one field reference.
     *
     * The tree is walked depth first and the first leaf reading that handle decides the type, so a value
     * is coerced the way the expression that will consume it expects.
     *
     * @param   Expression  $expression  Tree, or subtree, to search.
     * @param   string      $field       Field handle to find a declared type for.
     *
     * @return  ?string  The declared type, or null when the tree reads no such field.
     *
     * @since   2.0.0
     */
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
