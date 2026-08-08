<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

final readonly class PhysicalForeignKeyBlueprint
{
    private const ACTIONS = ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'];

    /** @var list<string> */
    public array $localColumns;

    /** @var list<string> */
    public array $foreignColumns;

    /**
     * @param list<string> $localColumns Physical columns in constraint order.
     * @param list<string> $foreignColumns Physical target columns in constraint order.
     */
    public function __construct(
        public string $logicalName,
        public string $physicalName,
        array $localColumns,
        public string $foreignTable,
        array $foreignColumns,
        public string $onDelete = 'RESTRICT',
        public string $onUpdate = 'RESTRICT',
    ) {
        SchemaDocument::assertIdentifier($logicalName, 'The foreign-key logical name');
        SchemaDocument::assertPhysicalIdentifier($physicalName, 'The physical foreign-key name');
        SchemaDocument::assertPhysicalIdentifier($foreignTable, 'The foreign-key target table');
        if (
            $localColumns === [] || count($localColumns) > 16
            || count($localColumns) !== count($foreignColumns)
            || count(array_unique($localColumns)) !== count($localColumns)
            || count(array_unique($foreignColumns)) !== count($foreignColumns)
        ) {
            throw new InvalidBusinessSchema('A foreign key requires matching, bounded, unique column lists.');
        }
        foreach ([...$localColumns, ...$foreignColumns] as $column) {
            SchemaDocument::assertPhysicalIdentifier($column, 'A physical foreign-key column');
        }
        if (!in_array($onDelete, self::ACTIONS, true) || !in_array($onUpdate, self::ACTIONS, true)) {
            throw new InvalidBusinessSchema('A foreign key uses an unsupported referential action.');
        }
        $this->localColumns = $localColumns;
        $this->foreignColumns = $foreignColumns;
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'logical_name', 'physical_name', 'local_columns', 'foreign_table', 'foreign_columns', 'on_delete',
                'on_update',
            ],
            'A physical foreign-key blueprint',
        );

        return new self(
            SchemaDocument::string($document, 'logical_name'),
            SchemaDocument::string($document, 'physical_name'),
            SchemaDocument::strings($document, 'local_columns'),
            SchemaDocument::string($document, 'foreign_table'),
            SchemaDocument::strings($document, 'foreign_columns'),
            SchemaDocument::string($document, 'on_delete'),
            SchemaDocument::string($document, 'on_update'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'logical_name' => $this->logicalName,
            'physical_name' => $this->physicalName,
            'local_columns' => $this->localColumns,
            'foreign_table' => $this->foreignTable,
            'foreign_columns' => $this->foreignColumns,
            'on_delete' => $this->onDelete,
            'on_update' => $this->onUpdate,
        ];
    }
}
