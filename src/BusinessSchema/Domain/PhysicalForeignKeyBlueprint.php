<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

/**
 * Canonical description of one referential constraint leaving a physical table.
 *
 * A foreign key is what makes a compiled definition graph hold together, so it is validated on its own
 * terms before the owning table ever sees it: the two column lists have to pair up one for one, stay
 * within the bound an engine will accept, and repeat no column. Referential actions are limited to the
 * four every supported engine spells the same way, which is what lets the planner emit one blueprint and
 * the executor verify a live constraint against it without engine-specific translation. Only the owning
 * `PhysicalTableBlueprint` can prove the local columns exist and that a set-null action lands on nullable
 * ones; this type deliberately stops at the properties a constraint can check about itself.
 *
 * @since  2.0.0
 */
final readonly class PhysicalForeignKeyBlueprint
{
    /**
     * Referential actions accepted, being those every supported engine names identically.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const ACTIONS = ['CASCADE', 'RESTRICT', 'SET NULL', 'NO ACTION'];

    /**
     * Physical columns of the owning table that carry the reference, in constraint order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $localColumns;

    /**
     * Physical columns of the target table the reference points at, positionally paired with the local ones.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $foreignColumns;

    /**
     * Assemble a constraint and prove its column pairing and referential actions are usable.
     *
     * @param   string        $logicalName     Handle a plan operation names this constraint by.
     * @param   string        $physicalName    Installed constraint name, with the configured prefix already applied.
     * @param   list<string>  $localColumns    Physical columns in constraint order.
     * @param   string        $foreignTable    Installed name of the referenced table, with the prefix already applied.
     * @param   list<string>  $foreignColumns  Physical target columns in constraint order.
     * @param   string        $onDelete        Action taken when a referenced row is deleted.
     * @param   string        $onUpdate        Action taken when a referenced key is updated.
     *
     * @throws  InvalidBusinessSchema  When a name or the target table breaks its grammar, either column list is
     *          empty, longer than 16, or repeats a column, the two lists differ in length, a column name is not
     *          a portable identifier, or either referential action is outside the supported four.
     *
     * @since   2.0.0
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

    /**
     * Rebuild a constraint from its persisted document, revalidating every rule the constructor applies.
     *
     * @param   array<string, mixed>  $document  Stored foreign-key object, as written by `toArray()`.
     *
     * @return  self  The revalidated constraint.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, or any constraint rule fails.
     *
     * @since   2.0.0
     */
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

    /**
     * Export the constraint in the shape that is persisted inside a table blueprint.
     *
     * @return  array<string, mixed>  Keyed `logical_name`, `physical_name`, `local_columns`, `foreign_table`,
     *          `foreign_columns`, `on_delete`, and `on_update`, with both column lists in constraint order.
     *
     * @since   2.0.0
     */
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
