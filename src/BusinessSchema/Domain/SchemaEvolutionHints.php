<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\Expression;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;

/**
 * The declared, bounded instructions that let a plan rewrite data instead of refusing to.
 *
 * A rename, a type change, or a tightened constraint cannot be applied over rows pinned to an older
 * definition version unless the new version says how those rows should be carried across. This value
 * object is the only reading of that intent: it lifts the four evolution families out of a definition's
 * compatibility metadata, normalizes them, and proves them bounded and unambiguous — no rename cycles, no
 * two renames landing on one column, no unbounded expression — so the planner can consult them freely.
 *
 * A typo is treated as a defect rather than as absence: a metadata key that reads like an evolution key
 * but is not one of the four is rejected, so a misspelled hint cannot silently degrade into "no hint".
 *
 * @since  2.0.0
 */
final readonly class SchemaEvolutionHints
{
    /**
     * The four evolution families read from compatibility metadata, in document order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const KEYS = ['column_renames', 'backfills', 'transforms', 'repins'];

    /**
     * Most entries one family may declare, so a definition cannot make planning unbounded.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_HINTS_PER_KIND = 256;

    /**
     * Column renames grouped by logical table, each inner map running old name to new name.
     *
     * @var    array<string, array<string, string>>
     * @since  2.0.0
     */
    private array $renamesByTable;

    /**
     * Value to write into each named logical column for rows that predate it.
     *
     * @var    array<string, bool|int|string|Expression>
     * @since  2.0.0
     */
    private array $backfills;

    /**
     * Bounded expression that converts each named logical column's existing values.
     *
     * @var    array<string, Expression>
     * @since  2.0.0
     */
    private array $transforms;

    /**
     * Definition version each named definition handle's records must be re-pinned to.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    private array $repins;

    /**
     * Store already-parsed, already-sorted hint maps.
     *
     * Private because the maps carry invariants the parsers establish; build instances through
     * `fromDefinition()` or `fromArray()`.
     *
     * @param  array<string, array<string, string>>       $renamesByTable  Renames keyed by table, then old column.
     * @param  array<string, bool|int|string|Expression>  $backfills       Backfill values keyed by logical column.
     * @param  array<string, Expression>                  $transforms      Conversions keyed by logical column.
     * @param  array<string, int>                         $repins          Target versions keyed by definition handle.
     *
     * @since  2.0.0
     */
    private function __construct(
        array $renamesByTable,
        array $backfills,
        array $transforms,
        array $repins,
    ) {
        $this->renamesByTable = $renamesByTable;
        $this->backfills = $backfills;
        $this->transforms = $transforms;
        $this->repins = $repins;
    }

    /**
     * Read the evolution hints a published definition declares in its compatibility metadata.
     *
     * Metadata unrelated to schema evolution is ignored, but a key that merely looks like one of the four
     * families is refused, because tolerating it would silently skip the rewrite it was meant to authorize.
     *
     * @param   EntityTypeDefinition  $definition  Definition version whose metadata carries the intent.
     *
     * @return  self  The parsed hints; every family is empty when the definition declares none.
     *
     * @throws  InvalidBusinessSchema  When a metadata key is not a string, an evolution-looking key is not
     *          one of the four families, or a declared family is malformed.
     *
     * @since   2.0.0
     */
    public static function fromDefinition(EntityTypeDefinition $definition): self
    {
        $metadata = $definition->compatibilityMetadata();
        foreach ($metadata as $key => $_value) {
            if (!is_string($key)) {
                throw new InvalidBusinessSchema('Business compatibility metadata keys must be strings.');
            }
            if (!in_array($key, self::KEYS, true) && self::looksLikeEvolutionKey($key)) {
                throw new InvalidBusinessSchema('Unknown schema-evolution compatibility key: ' . $key);
            }
        }
        $document = [];
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $metadata)) {
                $document[$key] = $metadata[$key];
            }
        }

        return self::fromArray($document);
    }

    /**
     * Parse hints from a document holding only the four evolution families.
     *
     * @param   array<string, mixed>  $document  Evolution families as declared; each one may be absent.
     *
     * @return  self  The parsed hints, with every family key sorted for a stable checksum.
     *
     * @throws  InvalidBusinessSchema  When the document holds a key outside the four families, a family is
     *          not an object, a family exceeds 256 entries, a name breaks its
     *          grammar, a rename is ambiguous, chained, or cyclic, a backfill is not
     *          a bounded scalar or expression, a transform is not a bounded
     *          expression, or a repin version is not positive.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly($document, self::KEYS, 'Schema-evolution hints');
        $renames = self::parseRenames(self::metadataObject($document, 'column_renames'));
        $backfills = self::parseBackfills(self::metadataObject($document, 'backfills'));
        $transforms = self::parseTransforms(self::metadataObject($document, 'transforms'));
        $repins = self::parseRepins(self::metadataObject($document, 'repins'));

        return new self($renames, $backfills, $transforms, $repins);
    }

    /**
     * Look up the column renames declared for one logical table.
     *
     * @param   string  $logicalTable  Logical table name; unqualified renames belong to `record`.
     *
     * @return  array<string, string>  Old logical column to new logical column; empty when the table has
     *          no declared renames.
     *
     * @throws  InvalidBusinessSchema  When the table name is not a metadata identifier.
     *
     * @since   2.0.0
     */
    public function renameForTable(string $logicalTable): array
    {
        SchemaDocument::assertIdentifier($logicalTable, 'The schema-evolution table');

        return $this->renamesByTable[$logicalTable] ?? [];
    }

    /**
     * Report whether a backfill is declared for a logical column.
     *
     * @param   string  $logicalColumn  Logical column handle to test.
     *
     * @return  bool  True when the definition declares a value to write into pre-existing rows.
     *
     * @throws  InvalidBusinessSchema  When the column name is not a metadata identifier.
     *
     * @since   2.0.0
     */
    public function hasBackfill(string $logicalColumn): bool
    {
        SchemaDocument::assertIdentifier($logicalColumn, 'The schema backfill column');

        return array_key_exists($logicalColumn, $this->backfills);
    }

    /**
     * Read the value a backfill writes into rows that predate a column.
     *
     * @param   string  $logicalColumn  Logical column handle to read.
     *
     * @return  bool|int|string|Expression|null  The declared literal or expression; null means no backfill
     *          is declared, since a declared one is never null.
     *
     * @throws  InvalidBusinessSchema  When the column name is not a metadata identifier.
     *
     * @since   2.0.0
     */
    public function backfill(string $logicalColumn): bool|int|string|Expression|null
    {
        SchemaDocument::assertIdentifier($logicalColumn, 'The schema backfill column');

        return $this->backfills[$logicalColumn] ?? null;
    }

    /**
     * Read the expression that converts a logical column's existing values.
     *
     * @param   string  $logicalColumn  Logical column handle to read.
     *
     * @return  Expression|null  The declared conversion, or null when the column keeps its values as they
     *          are.
     *
     * @throws  InvalidBusinessSchema  When the column name is not a metadata identifier.
     *
     * @since   2.0.0
     */
    public function transform(string $logicalColumn): ?Expression
    {
        SchemaDocument::assertIdentifier($logicalColumn, 'The schema transform column');

        return $this->transforms[$logicalColumn] ?? null;
    }

    /**
     * List every declared column conversion, which is how the planner finds rewritten columns.
     *
     * @return  array<string, Expression>  Conversions keyed by logical column, sorted by key.
     *
     * @since   2.0.0
     */
    public function transforms(): array
    {
        return $this->transforms;
    }

    /**
     * List every declared rename, grouped by the table it applies to.
     *
     * @return  array<string, array<string, string>>  Old to new logical column names, keyed by table.
     *
     * @since   2.0.0
     */
    public function renames(): array
    {
        return $this->renamesByTable;
    }

    /**
     * List every declared backfill.
     *
     * @return  array<string, bool|int|string|Expression>  Values keyed by logical column, sorted by key.
     *
     * @since   2.0.0
     */
    public function backfills(): array
    {
        return $this->backfills;
    }

    /**
     * Read the definition version a handle's stored records must be re-pinned to.
     *
     * A declared repin is what authorizes a plan to rewrite historical rows at all, so a null here means
     * older rows stay on their pinned version and the destructive follow-up work stays blocked.
     *
     * @param   string  $definitionHandle  Namespaced definition handle, such as `vendor.thing`.
     *
     * @return  int|null  The target version, or null when this handle is not re-pinned.
     *
     * @throws  InvalidBusinessSchema  When the handle is over 191 bytes or is not namespaced.
     *
     * @since   2.0.0
     */
    public function repin(string $definitionHandle): ?int
    {
        self::assertDefinitionHandle($definitionHandle);

        return $this->repins[$definitionHandle] ?? null;
    }

    /**
     * List every declared repin.
     *
     * @return  array<string, int>  Target versions keyed by definition handle, sorted by key.
     *
     * @since   2.0.0
     */
    public function repins(): array
    {
        return $this->repins;
    }

    /**
     * Export the hints in their canonical document shape.
     *
     * Renames are flattened back to `table/old` keys, so the round trip through `fromArray()` yields the
     * same object regardless of whether the source spelled a rename with or without its table.
     *
     * @return  array<string, mixed>  All four families, always present, each sorted by key; expressions are
     *          rendered as their own array form.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $renames = [];
        foreach ($this->renamesByTable as $table => $tableRenames) {
            foreach ($tableRenames as $old => $new) {
                $renames[$table . '/' . $old] = $new;
            }
        }
        ksort($renames, SORT_STRING);

        return [
            'column_renames' => $renames,
            'backfills' => array_map(
                static fn (bool|int|string|Expression $value): bool|int|string|array =>
                    $value instanceof Expression ? ['expression' => $value->toArray()] : $value,
                $this->backfills,
            ),
            'transforms' => array_map(
                static fn (Expression $expression): array => $expression->toArray(),
                $this->transforms,
            ),
            'repins' => $this->repins,
        ];
    }

    /**
     * Compute a stable identity for the declared intent, so a plan can be bound to it.
     *
     * @return  string  Lowercase SHA-256 over the canonical JSON encoding of `toArray()`.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }

    /**
     * Read one evolution family as a string-keyed object, treating absence as empty.
     *
     * @param   array<string, mixed>  $document  Whole hints document.
     * @param   string                $key       Family name to read.
     *
     * @return  array<string, mixed>  The family's entries, or an empty array when it is not declared.
     *
     * @throws  InvalidBusinessSchema  When the family is present but is not a string-keyed object.
     *
     * @since   2.0.0
     */
    private static function metadataObject(array $document, string $key): array
    {
        if (!array_key_exists($key, $document)) {
            return [];
        }
        $value = $document[$key];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidBusinessSchema('Schema-evolution hint ' . $key . ' must be an object.');
        }
        foreach (array_keys($value) as $itemKey) {
            if (!is_string($itemKey)) {
                throw new InvalidBusinessSchema('Schema-evolution hint ' . $key . ' requires string keys.');
            }
        }
        /** @var array<string, mixed> $value */

        return $value;
    }

    /**
     * Group flat `table/old` rename keys by table and prove each table's renames are applicable.
     *
     * @param   array<string, mixed>  $document  Declared renames, keyed by column or by `table/column`.
     *
     * @return  array<string, array<string, string>>  Old to new logical column names, keyed by table, with
     *          both levels sorted by key.
     *
     * @throws  InvalidBusinessSchema  When there are over 256 renames, a target is not a string or not a
     *          metadata identifier, a source is neither `column` nor `table/column`,
     *          the same source is renamed twice, or the table's renames are
     *          ambiguous, chained, or cyclic.
     *
     * @since   2.0.0
     */
    private static function parseRenames(array $document): array
    {
        self::assertBounded($document, 'column renames');
        $byTable = [];
        foreach ($document as $oldPath => $newColumn) {
            if (!is_string($newColumn)) {
                throw new InvalidBusinessSchema('Every schema column rename target must be a string.');
            }
            [$table, $oldColumn] = self::renameSource($oldPath);
            SchemaDocument::assertIdentifier($newColumn, 'A schema rename target column');
            if (isset($byTable[$table][$oldColumn])) {
                throw new InvalidBusinessSchema('A schema column rename is declared more than once.');
            }
            $byTable[$table][$oldColumn] = $newColumn;
        }
        ksort($byTable, SORT_STRING);
        foreach ($byTable as $table => $renames) {
            ksort($renames, SORT_STRING);
            self::assertRenameGraph($table, $renames);
            $byTable[$table] = $renames;
        }

        return $byTable;
    }

    /**
     * Parse declared backfills into exact scalars and bounded expressions.
     *
     * A nested `{"expression": ...}` object is read as an `Expression`; anything else must be a bool, an
     * int, or a string within 32768 bytes. Floats are refused because a backfill has to be exactly
     * reproducible on replay.
     *
     * @param   array<string, mixed>  $document  Declared backfills keyed by logical column.
     *
     * @return  array<string, bool|int|string|Expression>  Backfill values keyed by column, sorted by key.
     *
     * @throws  InvalidBusinessSchema  When there are over 256 backfills, a column name breaks its grammar,
     *          an expression wrapper is malformed or holds an invalid expression,
     *          or a literal is null, a float, or an oversized string.
     *
     * @since   2.0.0
     */
    private static function parseBackfills(array $document): array
    {
        self::assertBounded($document, 'backfills');
        $result = [];
        foreach ($document as $logicalColumn => $literal) {
            SchemaDocument::assertIdentifier($logicalColumn, 'A schema backfill column');
            if (is_array($literal) && !array_is_list($literal)) {
                /** @var array<string, mixed> $literal */
                SchemaDocument::assertOnly($literal, ['expression'], 'A schema backfill expression');
                $expressionDocument = $literal['expression'] ?? null;
                if (!is_array($expressionDocument) || array_is_list($expressionDocument)) {
                    throw new InvalidBusinessSchema(
                        'A schema backfill expression must contain one bounded expression object.',
                    );
                }
                try {
                    /** @var array<string, mixed> $expressionDocument */
                    $result[$logicalColumn] = Expression::fromArray($expressionDocument);
                } catch (InvalidBusinessDefinition $exception) {
                    throw new InvalidBusinessSchema(
                        'A schema backfill contains an invalid bounded expression.',
                        0,
                        $exception,
                    );
                }
                continue;
            }
            if (
                (!is_bool($literal) && !is_int($literal) && !is_string($literal))
                || (is_string($literal) && strlen($literal) > 32_768)
            ) {
                throw new InvalidBusinessSchema(
                    'A schema backfill must be a bounded, non-null exact scalar or Expression.',
                );
            }
            $result[$logicalColumn] = $literal;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Parse declared column conversions into bounded expressions.
     *
     * An invalid expression is re-raised as a schema failure so callers of this namespace need to handle
     * only one exception type.
     *
     * @param   array<string, mixed>  $document  Declared transforms keyed by logical column.
     *
     * @return  array<string, Expression>  Conversions keyed by column, sorted by key.
     *
     * @throws  InvalidBusinessSchema  When there are over 256 transforms, a column name breaks its
     *          grammar, or an entry is not a valid bounded expression object.
     *
     * @since   2.0.0
     */
    private static function parseTransforms(array $document): array
    {
        self::assertBounded($document, 'transforms');
        $result = [];
        foreach ($document as $logicalColumn => $expressionDocument) {
            SchemaDocument::assertIdentifier($logicalColumn, 'A schema transform column');
            if (!is_array($expressionDocument) || array_is_list($expressionDocument)) {
                throw new InvalidBusinessSchema('A schema transform must be a bounded expression object.');
            }
            /** @var array<string, mixed> $expressionDocument */
            try {
                $result[$logicalColumn] = Expression::fromArray($expressionDocument);
            } catch (InvalidBusinessDefinition $exception) {
                throw new InvalidBusinessSchema(
                    'A schema transform contains an invalid bounded expression.',
                    0,
                    $exception,
                );
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Parse the definition versions that records must be moved onto.
     *
     * @param   array<string, mixed>  $document  Declared repins keyed by definition handle.
     *
     * @return  array<string, int>  Target versions keyed by handle, sorted by key.
     *
     * @throws  InvalidBusinessSchema  When there are over 256 repins, a handle is over 191 bytes or is not
     *          namespaced, or a version is not a positive integer.
     *
     * @since   2.0.0
     */
    private static function parseRepins(array $document): array
    {
        self::assertBounded($document, 'definition repins');
        $result = [];
        foreach ($document as $handle => $version) {
            self::assertDefinitionHandle($handle);
            if (!is_int($version) || $version < 1) {
                throw new InvalidBusinessSchema('A schema repin requires a positive definition version.');
            }
            $result[$handle] = $version;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /**
     * Split a rename key into the table and column it names.
     *
     * An unqualified key belongs to the `record` table, which is the shape most definitions write.
     *
     * @param   string  $path  Rename source, spelled `column` or `table/column`.
     *
     * @return  array{string, string}  The logical table and the logical column being renamed.
     *
     * @throws  InvalidBusinessSchema  When the key has more than one separator, or either part is not a
     *          metadata identifier.
     *
     * @since   2.0.0
     */
    private static function renameSource(string $path): array
    {
        $parts = explode('/', $path);
        if (count($parts) === 1) {
            $table = 'record';
            $column = $parts[0];
        } elseif (count($parts) === 2) {
            [$table, $column] = $parts;
        } else {
            throw new InvalidBusinessSchema('A schema rename source must be column or table/column.');
        }
        SchemaDocument::assertIdentifier($table, 'A schema rename source table');
        SchemaDocument::assertIdentifier($column, 'A schema rename source column');

        return [$table, $column];
    }

    /**
     * Prove one table's renames can be applied in a single pass.
     *
     * Two renames may not target the same column, a rename may not feed another rename, and the graph may
     * not close on itself — each of those would make the result depend on the order the plan happened to
     * apply the steps in.
     *
     * @param   string                 $table    Logical table the renames belong to, named in the failure message.
     * @param   array<string, string>  $renames  Old to new logical column names for that table.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When two renames share a target, the graph contains a cycle, or one
     *          rename's target is itself renamed.
     *
     * @since   2.0.0
     */
    private static function assertRenameGraph(string $table, array $renames): void
    {
        $targets = [];
        foreach ($renames as $old => $new) {
            if (isset($targets[$new])) {
                throw new InvalidBusinessSchema(sprintf(
                    'Schema table %s has ambiguous renames targeting column %s.',
                    $table,
                    $new,
                ));
            }
            $targets[$new] = true;
            $visited = [];
            $current = $old;
            while (isset($renames[$current])) {
                if (isset($visited[$current])) {
                    throw new InvalidBusinessSchema('A schema column rename graph contains a cycle.');
                }
                $visited[$current] = true;
                $current = $renames[$current];
            }
        }
        foreach ($renames as $old => $new) {
            if ($old !== $new && isset($renames[$new])) {
                throw new InvalidBusinessSchema('A schema column rename chain is ambiguous within one plan.');
            }
        }
    }

    /**
     * Cap how many entries one evolution family may declare.
     *
     * @param   array<string, mixed>  $document  Entries of a single family.
     * @param   string                $subject   Plural family name used in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the family declares more than 256 entries.
     *
     * @since   2.0.0
     */
    private static function assertBounded(array $document, string $subject): void
    {
        if (count($document) > self::MAXIMUM_HINTS_PER_KIND) {
            throw new InvalidBusinessSchema('Schema evolution contains too many ' . $subject . '.');
        }
    }

    /**
     * Decide whether an unrecognised metadata key is close enough to an evolution family to be a typo.
     *
     * @param   string  $key  Compatibility metadata key that is not one of the four families.
     *
     * @return  bool  True when the key opens with a schema, evolution, rename, backfill, transform, or
     *          repin word, and should therefore be refused rather than ignored.
     *
     * @since   2.0.0
     */
    private static function looksLikeEvolutionKey(string $key): bool
    {
        return preg_match(
            '/^(?:schema(?:_|$)|evolution(?:_|$)|column_renames?(?:_|$)|backfills?(?:_|$)'
                . '|transforms?(?:_|$)|transformations?(?:_|$)|repins?(?:_|$))/D',
            $key,
        ) === 1;
    }

    /**
     * Require a namespaced definition handle, which is what makes a repin target unambiguous.
     *
     * @param   string  $handle  Candidate handle, which must carry at least one `.`, `_`, or `-` group.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the handle is over 191 bytes or is not namespaced.
     *
     * @since   2.0.0
     */
    private static function assertDefinitionHandle(string $handle): void
    {
        if (
            strlen($handle) > 191
            || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $handle) !== 1
        ) {
            throw new InvalidBusinessSchema('A schema repin requires a stable namespaced definition handle.');
        }
    }
}
