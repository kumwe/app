<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Makes a translation group's site ownership a database-enforced relationship.
 *
 * The original multilingual migration made the group UUID globally unique but allowed an entry from a
 * different site to reference that UUID, because its foreign key named the group identifier alone. This
 * append-only repair adds the redundant group-owner column relational engines require, binds
 * `(translation_group_id, translation_group_site_identifier)` to the group's `(id, site_identifier)`,
 * and checks that the redundant owner equals the entry owner. Keeping the entry owner itself out of the
 * foreign key preserves `ON DELETE SET NULL`: deleting a group releases the translation fields without
 * trying to null the non-null site owner. The original migration is not edited, so an applied checksum
 * remains a trustworthy record of the bytes an installation ran.
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
     * MySQL-family virtual column carrying the ownership predicate checked by the engine.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string OWNER_VALIDITY_COLUMN = 'translation_group_owner_valid';

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
     * @throws  RuntimeException  When existing data already crosses a site boundary.
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
        if ((int) $contradictions > 0) {
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
                ['onDelete' => 'SET NULL'],
                $foreignKey,
            );
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
        if ($database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addMySqlOwnerConstraint($database, $entriesName);

            return;
        }

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
     * @throws  \Doctrine\DBAL\Exception  When a non-duplicate driver failure occurs.
     *
     * @since   2.0.0
     */
    private function addOwnerCheckConstraint(Connection $database, string $table): void
    {
        $constraint = $this->ownerCheckName($table);
        try {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
                $database->quoteSingleIdentifier($table),
                $database->quoteSingleIdentifier($constraint),
                $this->ownerPredicate(),
            ));
        } catch (\Doctrine\DBAL\Exception $failure) {
            $message = strtolower($failure->getMessage());
            if (
                !str_contains($message, 'already exists')
                && !str_contains($message, 'duplicate check constraint')
                && !str_contains($message, 'duplicate key name')
            ) {
                throw $failure;
            }
        }
    }

    /**
     * Install the MySQL-family invariant through a virtual value and a check over that value alone.
     *
     * MySQL and MariaDB reject a check that directly names a column participating in an
     * `ON DELETE SET NULL` foreign key. A virtual generated boolean may derive from those columns, while the check names
     * only that virtual value. This retains fail-closed database enforcement without the `TRIGGER` and
     * `SUPER` privileges managed services routinely withhold. A fresh install adds both objects in one
     * atomic alter; a catalog-proven partial attempt adds only the missing check.
     *
     * @param   Connection  $database  MySQL-family installation database being repaired.
     * @param   string      $table     Physical content-entry table name.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a replay finds a generated column or check with the wrong shape.
     * @throws  \Doctrine\DBAL\Exception  When catalog inspection or schema alteration fails.
     *
     * @since   2.0.0
     */
    private function addMySqlOwnerConstraint(Connection $database, string $table): void
    {
        $column = $this->mySqlOwnerColumn($database, $table);
        $check = $this->mySqlOwnerCheck($database, $table);
        if ($column !== null) {
            $this->assertMySqlOwnerColumnShape($column, $table);
        }
        if ($check !== null) {
            $this->assertMySqlOwnerCheckShape($check, $table);
        }
        if ($column === null && $check !== null) {
            throw new RuntimeException(sprintf(
                'Content-entry ownership check "%s" exists without its generated validity column.',
                $this->ownerCheckName($table),
            ));
        }

        $clauses = [];
        if ($column === null) {
            $clauses[] = sprintf(
                'ADD COLUMN %s TINYINT(1) GENERATED ALWAYS AS (%s) VIRTUAL',
                $database->quoteSingleIdentifier(self::OWNER_VALIDITY_COLUMN),
                $this->ownerPredicate(),
            );
        }
        if ($check === null) {
            $clauses[] = sprintf(
                'ADD CONSTRAINT %s CHECK (%s = 1)',
                $database->quoteSingleIdentifier($this->ownerCheckName($table)),
                $database->quoteSingleIdentifier(self::OWNER_VALIDITY_COLUMN),
            );
        }
        if ($clauses === []) {
            return;
        }

        $database->executeStatement(sprintf(
            'ALTER TABLE %s %s',
            $database->quoteSingleIdentifier($table),
            implode(', ', $clauses),
        ));

        $column = $this->mySqlOwnerColumn($database, $table);
        $check = $this->mySqlOwnerCheck($database, $table);
        if ($column === null || $check === null) {
            throw new RuntimeException('The content-entry ownership invariant was not installed completely.');
        }
        $this->assertMySqlOwnerColumnShape($column, $table);
        $this->assertMySqlOwnerCheckShape($check, $table);
    }

    /**
     * Read the generated owner-validity column from the MySQL-family catalog.
     *
     * @param   Connection  $database  MySQL-family connection whose column catalog is inspected.
     * @param   string      $table     Physical content-entry table the generated column must belong to.
     *
     * @return  ?array{data_type: string, extra: string, generation_expression: ?string}  Catalog shape, or
     *          null when the column has not been added yet.
     *
     * @throws  RuntimeException  When the catalog returns an unreadable row shape.
     * @throws  \Doctrine\DBAL\Exception  When the column catalog cannot be read.
     *
     * @since   2.0.0
     */
    private function mySqlOwnerColumn(Connection $database, string $table): ?array
    {
        $row = $database->fetchAssociative(
            'SELECT DATA_TYPE AS data_type, EXTRA AS extra, '
            . 'GENERATION_EXPRESSION AS generation_expression FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, self::OWNER_VALIDITY_COLUMN],
        );
        if ($row === false) {
            return null;
        }

        $dataType = $row['data_type'] ?? null;
        $extra = $row['extra'] ?? null;
        $expression = $row['generation_expression'] ?? null;
        if (
            !is_string($dataType)
            || !is_string($extra)
            || ($expression !== null && !is_string($expression))
        ) {
            throw new RuntimeException('The content-entry ownership generated-column catalog row is unreadable.');
        }

        return ['data_type' => $dataType, 'extra' => $extra, 'generation_expression' => $expression];
    }

    /**
     * Read the named owner-validity check from the MySQL-family catalog.
     *
     * @param   Connection  $database  MySQL-family connection whose check catalog is inspected.
     * @param   string      $table     Physical content-entry table the check must belong to.
     *
     * @return  ?string  Catalog check clause, or null when the constraint has not been added yet.
     *
     * @throws  RuntimeException  When the catalog returns a non-string check clause.
     * @throws  \Doctrine\DBAL\Exception  When the check catalog cannot be read.
     *
     * @since   2.0.0
     */
    private function mySqlOwnerCheck(Connection $database, string $table): ?string
    {
        $clause = $database->fetchOne(
            'SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c '
            . 'INNER JOIN information_schema.TABLE_CONSTRAINTS t '
            . 'ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME '
            . "WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND c.CONSTRAINT_NAME = ? AND t.TABLE_NAME = ? "
            . "AND t.CONSTRAINT_TYPE = 'CHECK'",
            [$this->ownerCheckName($table), $table],
        );
        if ($clause === false) {
            return null;
        }
        if (!is_string($clause)) {
            throw new RuntimeException('The content-entry ownership check catalog row is unreadable.');
        }

        return $clause;
    }

    /**
     * Refuse a replay column that is stored, mistyped or derives a different predicate.
     *
     * @param   array{data_type: string, extra: string, generation_expression: ?string}  $column  Catalog
     *          shape of the existing owner-validity column.
     * @param   string  $table  Physical content-entry table named in a refusal.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the existing generated column does not match this migration.
     *
     * @since   2.0.0
     */
    private function assertMySqlOwnerColumnShape(array $column, string $table): void
    {
        $extra = strtolower($column['extra']);
        $expression = $column['generation_expression'];
        $shape = null;
        if (
            strtolower($column['data_type']) === 'tinyint'
            && str_contains($extra, 'virtual')
            && str_contains($extra, 'generated')
            && $expression !== null
        ) {
            $shape = $this->ownerPredicateShape($expression);
        }
        if (
            strtolower($column['data_type']) !== 'tinyint'
            || !str_contains($extra, 'virtual')
            || !str_contains($extra, 'generated')
            || !in_array($shape, [
                'or(and(A,B),and(C,D,E))',
                'or(and(A,B),and(and(C,D),E))',
                'or(and(A,B),and(C,and(D,E)))',
            ], true)
        ) {
            throw new RuntimeException(sprintf(
                'Content-entry ownership column "%s" on table "%s" has an incompatible shape.',
                self::OWNER_VALIDITY_COLUMN,
                $table,
            ));
        }
    }

    /**
     * Refuse a replay check that guards anything other than truth of the virtual validity value.
     *
     * @param   string  $clause  Check clause read from the MySQL-family catalog.
     * @param   string  $table   Physical content-entry table named in a refusal.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the existing check does not match this migration.
     *
     * @since   2.0.0
     */
    private function assertMySqlOwnerCheckShape(string $clause, string $table): void
    {
        $expected = self::OWNER_VALIDITY_COLUMN . ' = 1';
        if (
            $this->stripOuterParentheses($this->normalizeSqlExpression($clause))
                !== $this->normalizeSqlExpression($expected)
        ) {
            throw new RuntimeException(sprintf(
                'Content-entry ownership check "%s" on table "%s" has an incompatible shape.',
                $this->ownerCheckName($table),
                $table,
            ));
        }
    }

    /**
     * Normalize catalog-rendered expressions for exact cross-driver shape comparison.
     *
     * @param   string  $expression  Generated expression or check clause reported by the database.
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
     * MySQL and MariaDB differ in redundant parentheses around generated expressions. Parsing the five
     * exact atoms admits the bounded associative renderings checked by the caller while keeping a
     * materially different `AND`/`OR` grouping different; merely deleting parentheses would let a
     * drifted predicate pass replay.
     *
     * @param   string  $expression  Generated ownership expression reported by the database.
     *
     * @return  string  Canonical boolean tree over the five expected predicate atoms.
     *
     * @throws  RuntimeException  When the expression contains an unknown atom or malformed grouping.
     *
     * @since   2.0.0
     */
    private function ownerPredicateShape(string $expression): string
    {
        $symbolic = str_replace(
            [
                'translation_group_site_identifierisnotnull',
                'translation_group_idisnotnull',
                'translation_group_site_identifierisnull',
                'translation_group_idisnull',
                'translation_group_site_identifier=site_identifier',
            ],
            ['D', 'C', 'B', 'A', 'E'],
            $this->normalizeSqlExpression($expression),
        );
        $tokens = $this->ownerPredicateTokens($symbolic);
        $offset = 0;
        $shape = $this->parseOwnerOr($tokens, $offset);
        if ($offset !== count($tokens)) {
            throw new RuntimeException('The content-entry ownership generated expression has trailing tokens.');
        }

        return $shape;
    }

    /**
     * Tokenize the bounded boolean grammar accepted from generated-column catalogs.
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

            throw new RuntimeException('The content-entry ownership generated expression has an unknown token.');
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
            throw new RuntimeException('The content-entry ownership generated expression has no valid operand.');
        }

        ++$offset;
        $shape = $this->parseOwnerOr($tokens, $offset);
        if (($tokens[$offset] ?? null) !== ')') {
            throw new RuntimeException('The content-entry ownership generated expression is not balanced.');
        }
        ++$offset;

        return $shape;
    }

    /**
     * Remove only parentheses that wrap an entire expression without changing its precedence.
     *
     * @param   string  $expression  Quote-free, whitespace-free check clause.
     *
     * @return  string  Clause with balanced redundant outer wrappers removed.
     *
     * @since   2.0.0
     */
    private function stripOuterParentheses(string $expression): string
    {
        while (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
            $depth = 0;
            $wrapsAll = true;
            for ($offset = 0; $offset < strlen($expression); ++$offset) {
                $depth += $expression[$offset] === '(' ? 1 : ($expression[$offset] === ')' ? -1 : 0);
                if ($depth === 0 && $offset < strlen($expression) - 1) {
                    $wrapsAll = false;
                    break;
                }
                if ($depth < 0) {
                    $wrapsAll = false;
                    break;
                }
            }
            if (!$wrapsAll || $depth !== 0) {
                break;
            }
            $expression = substr($expression, 1, -1);
        }

        return $expression;
    }
}
