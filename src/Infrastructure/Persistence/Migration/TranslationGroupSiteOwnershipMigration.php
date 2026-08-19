<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\MatchType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Makes a translation group's site ownership a database-enforced relationship.
 *
 * The original multilingual migration made the group UUID globally unique but allowed an entry from a
 * different site to reference that UUID, because its foreign key named the group identifier alone. This
 * append-only repair adds the redundant group-owner column relational engines require, binds
 * `(translation_group_id, translation_group_site_identifier)` to the group's `(id, site_identifier)`,
 * and checks that the redundant owner equals the entry owner. Keeping the entry owner itself out of the
 * foreign key permits an explicit two-column detach while `RESTRICT` makes a raw group deletion fail
 * closed until its members have been detached. The original migration is not edited, so an applied
 * checksum remains a trustworthy record of the bytes an installation ran.
 *
 * @since  2.0.0
 */
final readonly class TranslationGroupSiteOwnershipMigration implements RepeatableMigration
{
    /**
     * Stable ordered identity after the multilingual content migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260819020000_translation_group_site_ownership';

    /**
     * Bind the repair to the installation's physical table names.
     *
     * @param  TableNames  $tables  Prefix-aware table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only migration identity.
     *
     * @return  string  Stable ordered identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind migration compatibility to the exact implementation bytes.
     *
     * @return  string  Lowercase SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When this source file cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The translation group ownership migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Refuse contradictory rows, then add the composite owner key and foreign key.
     *
     * @param   Connection  $database  Installation database being repaired.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the contradiction count is unreadable or existing data crosses a site boundary.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects inspection or a schema statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $entriesName = $this->tables->raw('content_entries');
        $groupsName = $this->tables->raw('content_translation_groups');
        $contradictions = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s e INNER JOIN %s g ON g.id = e.translation_group_id '
            . 'WHERE e.site_identifier <> g.site_identifier',
            $this->tables->quoted('content_entries'),
            $this->tables->quoted('content_translation_groups'),
        ));
        if (is_int($contradictions)) {
            $contradictionCount = $contradictions;
        } elseif (is_string($contradictions) && ctype_digit($contradictions)) {
            $contradictionCount = (int) $contradictions;
        } else {
            throw new RuntimeException('The translation-group ownership contradiction count is unreadable.');
        }
        if ($contradictionCount > 0) {
            throw new RuntimeException(
                'Translation group site ownership cannot be enforced while cross-site members exist.',
            );
        }

        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $entries = $after->getTable($entriesName);
        $groups = $after->getTable($groupsName);
        if (!$entries->hasColumn('translation_group_site_identifier')) {
            $entries->addColumn('translation_group_site_identifier', Types::STRING, [
                'length' => 191,
                'notnull' => false,
            ]);
            if ($database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $this->copyCharacterDefinition(
                    $entries->getColumn('site_identifier'),
                    $entries->getColumn('translation_group_site_identifier'),
                );
            }
        }
        $unique = $this->uniqueIndexName($groupsName);
        if (!$groups->hasIndex($unique)) {
            $groups->addUniqueIndex(['id', 'site_identifier'], $unique);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }

        $database->executeStatement(sprintf(
            'UPDATE %s SET translation_group_site_identifier = '
            . 'CASE WHEN translation_group_id IS NULL THEN NULL ELSE site_identifier END '
            . 'WHERE (translation_group_id IS NULL AND translation_group_site_identifier IS NOT NULL) '
            . 'OR (translation_group_id IS NOT NULL AND (translation_group_site_identifier IS NULL '
            . 'OR translation_group_site_identifier <> site_identifier))',
            $this->tables->quoted('content_entries'),
        ));

        $before = $manager->introspectSchema();
        $after = clone $before;
        $entries = $after->getTable($entriesName);
        $foreignKey = $this->foreignKeyName($entriesName);
        if (!$entries->hasForeignKey($foreignKey)) {
            $entries->addForeignKeyConstraint(
                $groupsName,
                ['translation_group_id', 'translation_group_site_identifier'],
                ['id', 'site_identifier'],
                ['onDelete' => 'RESTRICT'],
                $foreignKey,
            );
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
        $this->removePredecessorForeignKey($database, $entriesName, $groupsName);
        $this->addOwnerCheckConstraint($database, $entriesName);
    }

    /**
     * Derive a globally collision-resistant foreign-key name from its table and columns.
     *
     * @param   string  $table  Physical content-entry table name.
     *
     * @return  string  Stable name inside every supported engine's identifier limit.
     *
     * @since   2.0.0
     */
    private function foreignKeyName(string $table): string
    {
        return 'fk_' . substr(
            hash('sha256', $table . ':translation_group_id:translation_group_site_identifier'),
            0,
            24,
        );
    }

    /**
     * Derive a schema-global unique-index name from the physical group table.
     *
     * PostgreSQL puts index names in the schema namespace, so two installations with different table
     * prefixes cannot safely share a literal index name even though MySQL scopes one to its table.
     *
     * @param   string  $table  Physical translation-group table name.
     *
     * @return  string  Stable name inside the portable identifier limit.
     *
     * @since   2.0.0
     */
    private function uniqueIndexName(string $table): string
    {
        return 'uniq_' . substr(hash('sha256', $table . ':id:site_identifier'), 0, 24);
    }

    /**
     * Copy the entry owner's character definition onto its nullable group-owner mirror.
     *
     * @param   Column  $source  Authoritative site-identifier column.
     * @param   Column  $target  Nullable group-owner mirror used by the composite foreign key.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a MySQL-family source has no character definition to copy.
     *
     * @since   2.0.0
     */
    private function copyCharacterDefinition(Column $source, Column $target): void
    {
        $charset = $source->getCharset();
        $collation = $source->getCollation();
        if ($charset === null || $collation === null) {
            throw new RuntimeException('The content entry site identifier has no character definition to copy.');
        }

        $target->setPlatformOption('charset', $charset);
        $target->setPlatformOption('collation', $collation);
    }

    /**
     * Remove the immutable predecessor's overlapping one-column foreign key after proving its replacement.
     *
     * The predecessor's `SET NULL` conflicts with the replacement's fail-closed deletion rule, so both
     * constraints cannot safely coexist. The composite replacement is proved before the predecessor is
     * dropped, and the resulting shape is read back so a replay either observes the completed repair or
     * fails closed on an ambiguous relationship.
     *
     * @param   Connection  $database   Installation database whose entry constraints are repaired.
     * @param   non-empty-string  $entries  Physical content-entry table name.
     * @param   non-empty-string  $groups   Physical translation-group table name.
     *
     * @return  void
     *
     * @throws  RuntimeException  When either ownership foreign key is missing, divergent or ambiguous.
     * @throws  \Doctrine\DBAL\Exception  When constraint introspection or removal fails.
     *
     * @since   2.0.0
     */
    private function removePredecessorForeignKey(
        Connection $database,
        string $entries,
        string $groups,
    ): void {
        $manager = $database->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName($entries);
        $predecessor = $this->predecessorForeignKey($table->getForeignKeys(), $groups);
        if ($predecessor === null) {
            return;
        }

        $name = $predecessor->getObjectName()?->getIdentifier()->getValue();
        if ($name === null || $name === '') {
            throw new RuntimeException('The predecessor translation-group foreign key has no removable name.');
        }
        $manager->dropForeignKey($name, $entries);

        $remaining = $manager->introspectTableByUnquotedName($entries)->getForeignKeys();
        if ($this->predecessorForeignKey($remaining, $groups) !== null) {
            throw new RuntimeException('The predecessor translation-group foreign key was not removed.');
        }
    }

    /**
     * Prove the composite replacement and return the exact predecessor constraint when it remains.
     *
     * @param   array<array-key, ForeignKeyConstraint>  $foreignKeys  Entry-table constraints by catalog name.
     * @param   string                                  $groups       Physical translation-group table name.
     *
     * @return  ?ForeignKeyConstraint  Exact predecessor to remove, or null after a completed replay.
     *
     * @throws  RuntimeException  When the composite replacement or predecessor has a divergent shape,
     *          or either relationship is ambiguous.
     *
     * @since   2.0.0
     */
    private function predecessorForeignKey(array $foreignKeys, string $groups): ?ForeignKeyConstraint
    {
        $predecessors = [];
        $replacements = [];
        foreach ($foreignKeys as $foreignKey) {
            $columns = $this->foreignKeyColumns($foreignKey->getReferencingColumnNames());
            if ($columns === ['translation_group_id']) {
                $predecessors[] = $foreignKey;
            }
            if ($columns === ['translation_group_id', 'translation_group_site_identifier']) {
                $replacements[] = $foreignKey;
            }
        }

        if (count($replacements) !== 1) {
            throw new RuntimeException('The composite translation-group foreign key is missing or ambiguous.');
        }
        $this->assertForeignKeyShape(
            $replacements[0],
            $groups,
            ['id', 'site_identifier'],
            [ReferentialAction::RESTRICT, ReferentialAction::NO_ACTION],
            'composite',
        );
        if (count($predecessors) > 1) {
            throw new RuntimeException('The predecessor translation-group foreign key is ambiguous.');
        }
        if ($predecessors === []) {
            return null;
        }
        $this->assertForeignKeyShape(
            $predecessors[0],
            $groups,
            ['id'],
            [ReferentialAction::SET_NULL],
            'predecessor',
        );

        return $predecessors[0];
    }

    /**
     * Require one ownership foreign key to carry the complete expected relational shape.
     *
     * @param   ForeignKeyConstraint               $foreignKey  Constraint whose referenced half is verified.
     * @param   string                             $groups      Physical translation-group table name.
     * @param   list<string>                       $columns     Referenced group columns in declared order.
     * @param   non-empty-list<ReferentialAction>  $onDelete    Accepted equivalent delete actions.
     * @param   string                             $role        Relationship role named in an operator refusal.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the table, columns, match type or referential actions diverge.
     *
     * @since   2.0.0
     */
    private function assertForeignKeyShape(
        ForeignKeyConstraint $foreignKey,
        string $groups,
        array $columns,
        array $onDelete,
        string $role,
    ): void {
        if (
            $foreignKey->getReferencedTableName()->getUnqualifiedName()->getValue() !== $groups
            || $this->foreignKeyColumns($foreignKey->getReferencedColumnNames()) !== $columns
            || $foreignKey->getMatchType() !== MatchType::SIMPLE
            || !in_array($foreignKey->getOnUpdateAction(), [
                ReferentialAction::NO_ACTION,
                ReferentialAction::RESTRICT,
            ], true)
            || !in_array($foreignKey->getOnDeleteAction(), $onDelete, true)
        ) {
            throw new RuntimeException(sprintf(
                'The %s translation-group foreign key has an incompatible shape.',
                $role,
            ));
        }
    }

    /**
     * Flatten DBAL column-name objects to the unquoted logical identifiers schema rules compare.
     *
     * @param   list<UnqualifiedName>  $columns  Referencing or referenced names in declared order.
     *
     * @return  list<string>  Unquoted identifier values in the same order.
     *
     * @since   2.0.0
     */
    private function foreignKeyColumns(array $columns): array
    {
        return array_map(
            static fn (UnqualifiedName $column): string => $column->getIdentifier()->getValue(),
            $columns,
        );
    }

    /**
     * Return the complete nullable-pair and same-site ownership predicate.
     *
     * @return  string  Driver-neutral expression over the content-entry owner columns.
     *
     * @since   2.0.0
     */
    private function ownerPredicate(): string
    {
        return '(translation_group_id IS NULL AND translation_group_site_identifier IS NULL) OR '
            . '(translation_group_id IS NOT NULL AND translation_group_site_identifier IS NOT NULL '
            . 'AND translation_group_site_identifier = site_identifier)';
    }

    /**
     * Derive a schema-global check name from the physical content-entry table.
     *
     * @param   string  $table  Physical content-entry table name.
     *
     * @return  string  Stable 27-byte name inside every supported engine's identifier budget.
     *
     * @since   2.0.0
     */
    private function ownerCheckName(string $table): string
    {
        return 'ck_' . substr(hash('sha256', $table . ':translation_group_site_owner'), 0, 24);
    }

    /**
     * Require the nullable group owner to be absent with the group or equal to the entry owner.
     *
     * @param   Connection  $database  Installation database being repaired.
     * @param   string      $table     Physical content-entry table name.
     *
     * @return  void
     *
     * @throws  RuntimeException  When replay finds a missing, duplicate or divergent ownership check.
     * @throws  \Doctrine\DBAL\Exception  When catalog inspection or schema alteration fails.
     *
     * @since   2.0.0
     */
    private function addOwnerCheckConstraint(Connection $database, string $table): void
    {
        $constraint = $this->ownerCheckName($table);
        $clause = $this->ownerCheckClause($database, $table, $constraint);
        if ($clause !== null) {
            $this->assertOwnerCheckShape($clause, $table, $constraint);

            return;
        }

        $database->executeStatement(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
            $database->quoteSingleIdentifier($table),
            $database->quoteSingleIdentifier($constraint),
            $this->ownerPredicate(),
        ));

        $clause = $this->ownerCheckClause($database, $table, $constraint);
        if ($clause === null) {
            throw new RuntimeException(sprintf(
                'Content-entry ownership check "%s" was not installed.',
                $constraint,
            ));
        }
        $this->assertOwnerCheckShape($clause, $table, $constraint);
    }

    /**
     * Read the deterministic ownership check from the supported engine's catalog.
     *
     * @param   Connection  $database    Connection whose constraint catalog is inspected.
     * @param   string      $table       Physical content-entry table the check must belong to.
     * @param   string      $constraint  Deterministic ownership-check name.
     *
     * @return  ?string  Catalog check clause, or null when the constraint is absent.
     *
     * @throws  RuntimeException  When the platform is unsupported or the catalog shape is unreadable.
     * @throws  \Doctrine\DBAL\Exception  When the constraint catalog cannot be read.
     *
     * @since   2.0.0
     */
    private function ownerCheckClause(Connection $database, string $table, string $constraint): ?string
    {
        $platform = $database->getDatabasePlatform();
        if ($platform instanceof MariaDBPlatform) {
            $rows = $database->fetchAllAssociative(
                "SELECT c.CHECK_CLAUSE AS check_clause, 'YES' AS enforced "
                . 'FROM information_schema.CHECK_CONSTRAINTS c '
                . 'INNER JOIN information_schema.TABLE_CONSTRAINTS t '
                . 'ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME '
                . 'AND t.TABLE_NAME = ? '
                . 'WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND c.CONSTRAINT_NAME = ? '
                . "AND t.CONSTRAINT_TYPE = 'CHECK'",
                [$table, $constraint],
            );
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $rows = $database->fetchAllAssociative(
                'SELECT c.CHECK_CLAUSE AS check_clause, t.ENFORCED AS enforced '
                . 'FROM information_schema.CHECK_CONSTRAINTS c '
                . 'INNER JOIN information_schema.TABLE_CONSTRAINTS t '
                . 'ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME '
                . 'AND t.TABLE_NAME = ? '
                . 'WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND c.CONSTRAINT_NAME = ? '
                . "AND t.CONSTRAINT_TYPE = 'CHECK'",
                [$table, $constraint],
            );
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $rows = $database->fetchAllAssociative(
                'SELECT pg_catalog.pg_get_expr(c.conbin, c.conrelid) AS check_clause, '
                . 'c.convalidated AS enforced '
                . 'FROM pg_catalog.pg_constraint c '
                . 'INNER JOIN pg_catalog.pg_class t ON t.oid = c.conrelid '
                . 'INNER JOIN pg_catalog.pg_namespace n ON n.oid = t.relnamespace '
                . "WHERE n.nspname = current_schema() AND t.relname = ? AND c.conname = ? AND c.contype = 'c'",
                [$table, $constraint],
            );
        } else {
            throw new RuntimeException('Translation-group ownership checks require MySQL, MariaDB or PostgreSQL.');
        }

        if (count($rows) > 1) {
            throw new RuntimeException(sprintf(
                'Content-entry ownership check "%s" on table "%s" is ambiguous.',
                $constraint,
                $table,
            ));
        }
        if ($rows === []) {
            return null;
        }
        $clause = $rows[0]['check_clause'] ?? null;
        if (!is_string($clause) || $clause === '') {
            throw new RuntimeException('The content-entry ownership check catalog row is unreadable.');
        }
        $this->assertOwnerCheckEnforced($rows[0]['enforced'] ?? null, $platform, $table, $constraint);

        return $clause;
    }

    /**
     * Refuse a named check the catalog reports as non-enforced or not yet validated.
     *
     * @param   mixed                                     $enforced    Raw engine catalog value.
     * @param   AbstractMySQLPlatform|PostgreSQLPlatform  $platform    Supported database platform.
     * @param   string                                    $table       Physical content-entry table name.
     * @param   string                                    $constraint  Deterministic ownership-check name.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the named check does not currently protect every row.
     *
     * @since   2.0.0
     */
    private function assertOwnerCheckEnforced(
        mixed $enforced,
        AbstractMySQLPlatform|PostgreSQLPlatform $platform,
        string $table,
        string $constraint,
    ): void {
        $valid = $platform instanceof PostgreSQLPlatform
            ? in_array($enforced, [true, 1, '1', 't', 'true'], true)
            : is_string($enforced) && strtoupper($enforced) === 'YES';
        if (!$valid) {
            throw new RuntimeException(sprintf(
                'Content-entry ownership check "%s" on table "%s" is not enforced and validated.',
                $constraint,
                $table,
            ));
        }
    }

    /**
     * Refuse a deterministic ownership check that protects a different predicate.
     *
     * @param   string  $clause      Check clause reported by the database catalog.
     * @param   string  $table       Physical content-entry table named in a refusal.
     * @param   string  $constraint  Deterministic ownership-check name.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the clause has a materially different boolean shape.
     *
     * @since   2.0.0
     */
    private function assertOwnerCheckShape(string $clause, string $table, string $constraint): void
    {
        try {
            $shape = $this->ownerPredicateShape($clause);
        } catch (RuntimeException) {
            $shape = null;
        }
        if (
            !in_array($shape, [
                'or(and(A,B),and(C,D,E))',
                'or(and(A,B),and(and(C,D),E))',
                'or(and(A,B),and(C,and(D,E)))',
            ], true)
        ) {
            throw new RuntimeException(sprintf(
                'Content-entry ownership check "%s" on table "%s" has an incompatible shape.',
                $constraint,
                $table,
            ));
        }
    }

    /**
     * Normalize catalog-rendered expressions for exact cross-driver shape comparison.
     *
     * @param   string  $expression  Check expression reported by the database.
     *
     * @return  string  Lowercase expression without identifier quoting or whitespace.
     *
     * @throws  RuntimeException  When the fixed whitespace normalization expression fails.
     *
     * @since   2.0.0
     */
    private function normalizeSqlExpression(string $expression): string
    {
        $normalized = preg_replace(
            '/\s+/',
            '',
            str_replace(['`', '"'], '', strtolower($expression)),
        );
        if (!is_string($normalized)) {
            throw new RuntimeException('The content-entry ownership catalog expression could not be normalized.');
        }

        return $normalized;
    }

    /**
     * Reduce a catalog-rendered owner predicate to its precedence-preserving boolean shape.
     *
     * The engines differ in redundant parentheses, and PostgreSQL renders text equality with explicit
     * casts. Parsing the five exact atoms admits those bounded renderings while keeping a materially
     * different `AND`/`OR` grouping different; deleting every parenthesis would let drift pass replay.
     *
     * @param   string  $expression  Ownership-check expression reported by the database.
     *
     * @return  string  Canonical boolean tree over the five expected predicate atoms.
     *
     * @throws  RuntimeException  When the expression contains an unknown atom or malformed grouping.
     *
     * @since   2.0.0
     */
    private function ownerPredicateShape(string $expression): string
    {
        $normalized = str_replace(
            ['::text', '::charactervarying'],
            '',
            $this->normalizeSqlExpression($expression),
        );
        $normalized = str_replace(
            [
                '(translation_group_id)isnull',
                '(translation_group_id)isnotnull',
                '(translation_group_site_identifier)isnull',
                '(translation_group_site_identifier)isnotnull',
                '(translation_group_site_identifier)=(site_identifier)',
                '(translation_group_site_identifier)=site_identifier',
                'translation_group_site_identifier=(site_identifier)',
            ],
            [
                'translation_group_idisnull',
                'translation_group_idisnotnull',
                'translation_group_site_identifierisnull',
                'translation_group_site_identifierisnotnull',
                'translation_group_site_identifier=site_identifier',
                'translation_group_site_identifier=site_identifier',
                'translation_group_site_identifier=site_identifier',
            ],
            $normalized,
        );
        $symbolic = str_replace(
            [
                'translation_group_site_identifierisnotnull',
                'translation_group_idisnotnull',
                'translation_group_site_identifierisnull',
                'translation_group_idisnull',
                'translation_group_site_identifier=site_identifier',
            ],
            ['D', 'C', 'B', 'A', 'E'],
            $normalized,
        );
        $tokens = $this->ownerPredicateTokens($symbolic);
        $offset = 0;
        $shape = $this->parseOwnerOr($tokens, $offset);
        if ($offset !== count($tokens)) {
            throw new RuntimeException('The content-entry ownership check expression has trailing tokens.');
        }

        return $shape;
    }

    /**
     * Tokenize the bounded boolean grammar accepted from ownership-check catalogs.
     *
     * @param   string  $expression  Quote-free, whitespace-free symbolic owner expression.
     *
     * @return  list<string>  Parentheses, boolean operators and the five uppercase atom markers.
     *
     * @throws  RuntimeException  When an unknown token remains after atom substitution.
     *
     * @since   2.0.0
     */
    private function ownerPredicateTokens(string $expression): array
    {
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($expression)) {
            $character = $expression[$offset];
            if ($character === '(' || $character === ')' || str_contains('ABCDE', $character)) {
                $tokens[] = $character;
                ++$offset;
                continue;
            }
            if (substr($expression, $offset, 3) === 'and') {
                $tokens[] = 'and';
                $offset += 3;
                continue;
            }
            if (substr($expression, $offset, 2) === 'or') {
                $tokens[] = 'or';
                $offset += 2;
                continue;
            }

            throw new RuntimeException('The content-entry ownership check expression has an unknown token.');
        }

        return $tokens;
    }

    /**
     * Parse the disjunction level of the bounded owner-predicate grammar.
     *
     * @param   list<string>  $tokens  Tokenized owner expression.
     * @param   int           $offset  Current token position, advanced through the parsed expression.
     *
     * @return  string  Canonical disjunction shape at this grouping level.
     *
     * @throws  RuntimeException  When an operand is missing or grouping is malformed.
     *
     * @since   2.0.0
     */
    private function parseOwnerOr(array $tokens, int &$offset): string
    {
        $terms = [$this->parseOwnerAnd($tokens, $offset)];
        while (($tokens[$offset] ?? null) === 'or') {
            ++$offset;
            $terms[] = $this->parseOwnerAnd($tokens, $offset);
        }

        return count($terms) === 1 ? $terms[0] : 'or(' . implode(',', $terms) . ')';
    }

    /**
     * Parse the conjunction level of the bounded owner-predicate grammar.
     *
     * @param   list<string>  $tokens  Tokenized owner expression.
     * @param   int           $offset  Current token position, advanced through the parsed expression.
     *
     * @return  string  Canonical conjunction shape at this grouping level.
     *
     * @throws  RuntimeException  When an operand is missing or grouping is malformed.
     *
     * @since   2.0.0
     */
    private function parseOwnerAnd(array $tokens, int &$offset): string
    {
        $terms = [$this->parseOwnerPrimary($tokens, $offset)];
        while (($tokens[$offset] ?? null) === 'and') {
            ++$offset;
            $terms[] = $this->parseOwnerPrimary($tokens, $offset);
        }

        return count($terms) === 1 ? $terms[0] : 'and(' . implode(',', $terms) . ')';
    }

    /**
     * Parse one owner-predicate atom or one balanced parenthesized expression.
     *
     * @param   list<string>  $tokens  Tokenized owner expression.
     * @param   int           $offset  Current token position, advanced past the parsed primary.
     *
     * @return  string  Atom marker or canonical shape of the parenthesized expression.
     *
     * @throws  RuntimeException  When the primary is missing or its closing parenthesis is absent.
     *
     * @since   2.0.0
     */
    private function parseOwnerPrimary(array $tokens, int &$offset): string
    {
        $token = $tokens[$offset] ?? null;
        if (is_string($token) && strlen($token) === 1 && str_contains('ABCDE', $token)) {
            ++$offset;

            return $token;
        }
        if ($token !== '(') {
            throw new RuntimeException('The content-entry ownership check expression has no valid operand.');
        }

        ++$offset;
        $shape = $this->parseOwnerOr($tokens, $offset);
        if (($tokens[$offset] ?? null) !== ')') {
            throw new RuntimeException('The content-entry ownership check expression is not balanced.');
        }
        ++$offset;

        return $shape;
    }
}
