<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Canonical description of one index or unique constraint on a physical table.
 *
 * The schema compiler emits one of these for every field, relationship, or foreign key that needs a lookup
 * path, and the Doctrine gateway both installs and verifies live indexes against it. That round trip only
 * works if the description is engine neutral, so an index is bounded to sixteen distinct physical columns
 * and carries no engine-specific options at all: a non-empty option map is refused rather than stored and
 * quietly dropped on an engine that cannot honour it. Whether those columns actually exist is not this
 * type's business — the owning `PhysicalTableBlueprint` proves that once it holds the column collection.
 *
 * @since  2.0.0
 */
final readonly class PhysicalIndexBlueprint
{
    /**
     * Physical column names the index covers, in the order that decides which lookups it can serve.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $columns;

    /**
     * Portable index options handed to Doctrine when the index is created; always empty by construction.
     *
     * The slot exists so the persisted document keeps a stable shape and the gateway has something to pass
     * through, not so engine tuning can be smuggled into a blueprint.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $options;

    /**
     * Assemble an index and prove its column list is bounded, distinct, and portable.
     *
     * @param   string                $logicalName   Handle a plan operation names this index by.
     * @param   string                $physicalName  Installed index name, as the physical name compiler produced it.
     * @param   list<string>          $columns       Physical column names in index order.
     * @param   bool                  $unique        Whether the index also forbids duplicate values over $columns.
     * @param   array<string, mixed>  $options       Portable Doctrine index options.
     *
     * @throws  InvalidBusinessSchema  When either name breaks its grammar, the column list is empty, longer
     *          than 16, or repeats a column, a column is not a portable physical
     *          identifier, or the options are a list, use a non-string key, or are
     *          non-empty at all.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $logicalName,
        public string $physicalName,
        array $columns,
        public bool $unique = false,
        array $options = [],
    ) {
        SchemaDocument::assertIdentifier($logicalName, 'The physical index logical name');
        SchemaDocument::assertPhysicalIdentifier($physicalName, 'The physical index name');
        if ($columns === [] || count($columns) > 16 || count(array_unique($columns)) !== count($columns)) {
            throw new InvalidBusinessSchema('A physical index requires bounded, unique columns.');
        }
        foreach ($columns as $column) {
            SchemaDocument::assertPhysicalIdentifier($column, 'A physical index column');
        }
        SchemaDocument::assertObjectValue($options, 'Physical index options');
        if ($options !== []) {
            throw new InvalidBusinessSchema('Portable physical indexes do not accept engine-specific options.');
        }
        CanonicalDefinitionJson::encode($options);
        ksort($options, SORT_STRING);
        $this->columns = array_values($columns);
        $this->options = $options;
    }

    /**
     * Rebuild an index from its persisted document, revalidating every rule the constructor applies.
     *
     * @param   array<string, mixed>  $document  Stored index object, as written by `toArray()`.
     *
     * @return  self  The revalidated index.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, or any index rule fails.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            ['logical_name', 'physical_name', 'columns', 'unique', 'options'],
            'A physical index blueprint',
        );

        return new self(
            SchemaDocument::string($document, 'logical_name'),
            SchemaDocument::string($document, 'physical_name'),
            SchemaDocument::strings($document, 'columns'),
            SchemaDocument::boolean($document, 'unique'),
            SchemaDocument::object($document, 'options') ?? [],
        );
    }

    /**
     * Export the index in the shape that is persisted inside a table blueprint.
     *
     * @return  array<string, mixed>  Keyed `logical_name`, `physical_name`, `columns`, `unique`, and
     *          `options`, with the columns in index order.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'logical_name' => $this->logicalName,
            'physical_name' => $this->physicalName,
            'columns' => $this->columns,
            'unique' => $this->unique,
            'options' => $this->options,
        ];
    }
}
