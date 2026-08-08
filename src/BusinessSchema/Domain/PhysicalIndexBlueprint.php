<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class PhysicalIndexBlueprint
{
    /** @var list<string> */
    public array $columns;

    /** @var array<string, mixed> */
    public array $options;

    /**
     * @param list<string> $columns Physical column names in index order.
     * @param array<string, mixed> $options Portable Doctrine index options.
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

    /** @param array<string, mixed> $document */
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

    /** @return array<string, mixed> */
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
