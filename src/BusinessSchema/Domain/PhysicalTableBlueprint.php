<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Canonical description of one physical table, closed over its own columns, keys, indexes, and options.
 *
 * A table blueprint is the smallest unit a plan operation can name, and it is self-consistent by
 * construction: every primary-key, index, and foreign-key column is proven to exist in the same table, and
 * a set-null referential action is proven to land on nullable columns. Because the planner diffs blueprints
 * and the executor verifies live tables against them, collections are sorted and duplicate logical or
 * case-insensitive physical names are refused, so equal tables always serialize identically.
 *
 * @since  2.0.0
 */
final readonly class PhysicalTableBlueprint
{
    /**
     * Columns of the table, ordered by logical then physical name so the serialization is stable.
     *
     * @var    list<PhysicalColumnBlueprint>
     * @since  2.0.0
     */
    private array $columns;

    /**
     * Physical column names forming the primary key, in key order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $primaryKey;

    /**
     * Indexes and unique constraints, ordered by logical then physical name.
     *
     * @var    list<PhysicalIndexBlueprint>
     * @since  2.0.0
     */
    private array $indexes;

    /**
     * Referential constraints leaving this table, ordered by logical then physical name.
     *
     * @var    list<PhysicalForeignKeyBlueprint>
     * @since  2.0.0
     */
    private array $foreignKeys;

    /**
     * Portable table metadata carried alongside the structure, key sorted for a stable checksum.
     *
     * The compiler stores the provenance a later plan needs — owning definition handle, identity field and
     * strategy, scope mode, soft-delete flag, relationship kind — not engine tuning options.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $options;

    /**
     * Assemble a table and prove it is internally consistent.
     *
     * @param string $logicalName Handle a plan operation names this table by, such as `record`.
     * @param string $physicalName Installed table name, without the configured prefix applied.
     * @param PhysicalTableKind $kind Whether the table holds records, links, or owned lines.
     * @param   list<PhysicalColumnBlueprint>      $columns       Columns in any order; at least one, at most 512.
     * @param list<string> $primaryKey Physical column names in key order; at most 16, all present in $columns.
     * @param   list<PhysicalIndexBlueprint>       $indexes       Indexes whose columns must all belong to this table.
     * @param   list<PhysicalForeignKeyBlueprint>  $foreignKeys   Constraints whose local columns must belong here.
     * @param array<string, mixed> $options Portable table metadata; sorted by key before it is stored.
     *
     * @throws  InvalidBusinessSchema  When a name breaks its grammar, the column collection is empty or over
     *          the bound, two columns, indexes, or foreign keys collide, the primary
     *          key is empty, oversized, repeated, or references a column outside the
     *          table, an index or foreign key references a column outside the table,
     *          a set-null action lands on a non-nullable column, or the options are
     *          not a string-keyed object.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the options hold a value
     *          that cannot be canonically encoded, such as a float or an object.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $logicalName,
        public string $physicalName,
        public PhysicalTableKind $kind,
        array $columns,
        array $primaryKey,
        array $indexes = [],
        array $foreignKeys = [],
        array $options = [],
    ) {
        SchemaDocument::assertIdentifier($logicalName, 'The physical table logical name');
        SchemaDocument::assertPhysicalIdentifier($physicalName, 'The physical table name');
        if ($columns === [] || count($columns) > 512) {
            throw new InvalidBusinessSchema('A physical table requires a bounded column collection.');
        }
        $columns = self::unique(
            $columns,
            static fn (PhysicalColumnBlueprint $column): array => [$column->logicalName, $column->physicalName],
            'column',
        );
        usort(
            $columns,
            static fn (PhysicalColumnBlueprint $left, PhysicalColumnBlueprint $right): int =>
                [$left->logicalName, $left->physicalName] <=> [$right->logicalName, $right->physicalName],
        );
        $this->columns = $columns;
        $physicalColumns = array_map(
            static fn (PhysicalColumnBlueprint $column): string => $column->physicalName,
            $this->columns,
        );
        if (
            $primaryKey === [] || count($primaryKey) > 16
            || count(array_unique($primaryKey)) !== count($primaryKey)
            || array_diff($primaryKey, $physicalColumns) !== []
        ) {
            throw new InvalidBusinessSchema('A physical primary key must reference unique table columns.');
        }
        $this->primaryKey = array_values($primaryKey);
        $indexes = self::unique(
            $indexes,
            static fn (PhysicalIndexBlueprint $index): array => [$index->logicalName, $index->physicalName],
            'index',
        );
        usort(
            $indexes,
            static fn (PhysicalIndexBlueprint $left, PhysicalIndexBlueprint $right): int =>
                [$left->logicalName, $left->physicalName] <=> [$right->logicalName, $right->physicalName],
        );
        $this->indexes = $indexes;
        foreach ($this->indexes as $index) {
            if (array_diff($index->columns, $physicalColumns) !== []) {
                throw new InvalidBusinessSchema('A physical index references a column outside its table.');
            }
        }
        $foreignKeys = self::unique(
            $foreignKeys,
            static fn (PhysicalForeignKeyBlueprint $key): array => [$key->logicalName, $key->physicalName],
            'foreign key',
        );
        usort(
            $foreignKeys,
            static fn (PhysicalForeignKeyBlueprint $left, PhysicalForeignKeyBlueprint $right): int =>
                [$left->logicalName, $left->physicalName] <=> [$right->logicalName, $right->physicalName],
        );
        $this->foreignKeys = $foreignKeys;
        foreach ($this->foreignKeys as $foreignKey) {
            if (array_diff($foreignKey->localColumns, $physicalColumns) !== []) {
                throw new InvalidBusinessSchema('A physical foreign key references a local column outside its table.');
            }
            if ($foreignKey->onDelete !== 'SET NULL' && $foreignKey->onUpdate !== 'SET NULL') {
                continue;
            }
            foreach ($foreignKey->localColumns as $localColumn) {
                $column = $this->physicalColumn($localColumn);
                if ($column === null || !$column->nullable) {
                    throw new InvalidBusinessSchema('A set-null foreign key requires nullable local columns.');
                }
            }
        }
        SchemaDocument::assertObjectValue($options, 'Physical table options');
        CanonicalDefinitionJson::encode($options);
        ksort($options, SORT_STRING);
        $this->options = $options;
    }

    /**
     * Rebuild a table from its persisted document, revalidating every invariant.
     *
     * @param   array<string, mixed>  $document  Stored table object, as written by `toArray()`.
     *
     * @return  self  The revalidated table, with its collections back in canonical order.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, the stored kind is not a known one, or any table invariant
     *          fails.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the stored options hold
     *          a value that cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'logical_name', 'physical_name', 'kind', 'columns', 'primary_key', 'indexes', 'foreign_keys',
                'options',
            ],
            'A physical table blueprint',
        );
        $kind = PhysicalTableKind::tryFrom(SchemaDocument::string($document, 'kind'))
            ?? throw new InvalidBusinessSchema('A physical table kind is invalid.');

        return new self(
            SchemaDocument::string($document, 'logical_name'),
            SchemaDocument::string($document, 'physical_name'),
            $kind,
            array_map(PhysicalColumnBlueprint::fromArray(...), SchemaDocument::objects($document, 'columns')),
            SchemaDocument::strings($document, 'primary_key'),
            array_map(PhysicalIndexBlueprint::fromArray(...), SchemaDocument::objects($document, 'indexes')),
            array_map(
                PhysicalForeignKeyBlueprint::fromArray(...),
                SchemaDocument::objects($document, 'foreign_keys'),
            ),
            SchemaDocument::object($document, 'options') ?? [],
        );
    }

    /**
     * List every column of the table.
     *
     * @return  list<PhysicalColumnBlueprint>  The columns in canonical order, never empty.
     *
     * @since   2.0.0
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Resolve the column a definition field handle maps to.
     *
     * @param   string  $logicalName  Logical column handle, as a plan operation's subject names it.
     *
     * @return  PhysicalColumnBlueprint|null  The matching column, or null when this table declares none.
     *
     * @since   2.0.0
     */
    public function column(string $logicalName): ?PhysicalColumnBlueprint
    {
        foreach ($this->columns as $column) {
            if ($column->logicalName === $logicalName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Resolve a column from the installed name a key or constraint refers to.
     *
     * @param   string  $physicalName  Installed column name.
     *
     * @return  PhysicalColumnBlueprint|null  The matching column, or null when this table declares none.
     *
     * @since   2.0.0
     */
    public function physicalColumn(string $physicalName): ?PhysicalColumnBlueprint
    {
        foreach ($this->columns as $column) {
            if ($column->physicalName === $physicalName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * List the indexes and unique constraints this table declares.
     *
     * @return  list<PhysicalIndexBlueprint>  The indexes in canonical order; empty when the table has none
     *          beyond its primary key.
     *
     * @since   2.0.0
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * List the referential constraints leaving this table.
     *
     * @return  list<PhysicalForeignKeyBlueprint>  The constraints in canonical order; empty when the table
     *          references nothing.
     *
     * @since   2.0.0
     */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Export the table in the shape that is persisted inside a schema blueprint.
     *
     * @return  array<string, mixed>  Names, kind, and the four collections, each already in canonical order.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'logical_name' => $this->logicalName,
            'physical_name' => $this->physicalName,
            'kind' => $this->kind->value,
            'columns' => array_map(
                static fn (PhysicalColumnBlueprint $column): array => $column->toArray(),
                $this->columns,
            ),
            'primary_key' => $this->primaryKey,
            'indexes' => array_map(
                static fn (PhysicalIndexBlueprint $index): array => $index->toArray(),
                $this->indexes,
            ),
            'foreign_keys' => array_map(
                static fn (PhysicalForeignKeyBlueprint $foreignKey): array => $foreignKey->toArray(),
                $this->foreignKeys,
            ),
            'options' => $this->options,
        ];
    }

    /**
     * Reject a collection whose entries collide on either of their two names.
     *
     * Logical names must be distinct so a plan operation resolves to exactly one member, and physical names
     * must be distinct case insensitively because engines differ on identifier folding.
     *
     * @template T of object
     *
     * @param   list<T>                             $values
     * @param   callable(T): array{string, string}  $names
     * @param string $subject Member word used in the failure message, such as `column` or `index`.
     *
     * @return  list<T>  The same entries, re-indexed from zero.
     *
     * @throws  InvalidBusinessSchema  When two entries share a logical or case-insensitive physical name.
     *
     * @since   2.0.0
     */
    private static function unique(array $values, callable $names, string $subject): array
    {
        $logical = [];
        $physical = [];
        foreach ($values as $value) {
            [$logicalName, $physicalName] = $names($value);
            if (isset($logical[$logicalName]) || isset($physical[strtolower($physicalName)])) {
                throw new InvalidBusinessSchema('A physical table contains a duplicate ' . $subject . '.');
            }
            $logical[$logicalName] = true;
            $physical[strtolower($physicalName)] = true;
        }

        return array_values($values);
    }
}
