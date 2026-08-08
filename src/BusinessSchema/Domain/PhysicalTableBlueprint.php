<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class PhysicalTableBlueprint
{
    /** @var list<PhysicalColumnBlueprint> */
    private array $columns;

    /** @var list<string> Physical primary-key column names. */
    public array $primaryKey;

    /** @var list<PhysicalIndexBlueprint> */
    private array $indexes;

    /** @var list<PhysicalForeignKeyBlueprint> */
    private array $foreignKeys;

    /** @var array<string, mixed> */
    public array $options;

    /**
     * @param list<PhysicalColumnBlueprint> $columns
     * @param list<string> $primaryKey Physical column names.
     * @param list<PhysicalIndexBlueprint> $indexes
     * @param list<PhysicalForeignKeyBlueprint> $foreignKeys
     * @param array<string, mixed> $options
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

    /** @param array<string, mixed> $document */
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

    /** @return list<PhysicalColumnBlueprint> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function column(string $logicalName): ?PhysicalColumnBlueprint
    {
        foreach ($this->columns as $column) {
            if ($column->logicalName === $logicalName) {
                return $column;
            }
        }

        return null;
    }

    public function physicalColumn(string $physicalName): ?PhysicalColumnBlueprint
    {
        foreach ($this->columns as $column) {
            if ($column->physicalName === $physicalName) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<PhysicalIndexBlueprint> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /** @return list<PhysicalForeignKeyBlueprint> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /** @return array<string, mixed> */
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
     * @template T of object
     * @param list<T> $values
     * @param callable(T): array{string, string} $names
     * @return list<T>
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
