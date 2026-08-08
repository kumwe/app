<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class PhysicalSchemaBlueprint
{
    /** @var list<PhysicalTableBlueprint> */
    private array $tables;

    /** @param list<PhysicalTableBlueprint> $tables */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $definitionChecksum,
        array $tables,
    ) {
        SchemaDocument::assertUuid($definitionId, 'The physical schema definition ID');
        SchemaDocument::assertChecksum($definitionChecksum, 'The physical schema definition checksum');
        if ($definitionVersion < 1 || $tables === [] || count($tables) > 512) {
            throw new InvalidBusinessSchema('A physical schema requires a published version and bounded tables.');
        }
        $logical = [];
        $physical = [];
        foreach ($tables as $table) {
            if (
                isset($logical[$table->logicalName])
                || isset($physical[strtolower($table->physicalName)])
            ) {
                throw new InvalidBusinessSchema('A physical schema contains a table-name collision.');
            }
            $logical[$table->logicalName] = true;
            $physical[strtolower($table->physicalName)] = true;
        }
        usort(
            $tables,
            static fn (PhysicalTableBlueprint $left, PhysicalTableBlueprint $right): int =>
                [$left->logicalName, $left->physicalName] <=> [$right->logicalName, $right->physicalName],
        );
        $this->tables = $tables;
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            ['definition_id', 'definition_version', 'definition_checksum', 'tables'],
            'A physical schema blueprint',
        );

        return new self(
            SchemaDocument::string($document, 'definition_id'),
            SchemaDocument::integer($document, 'definition_version'),
            SchemaDocument::string($document, 'definition_checksum'),
            array_map(PhysicalTableBlueprint::fromArray(...), SchemaDocument::objects($document, 'tables')),
        );
    }

    /** @return list<PhysicalTableBlueprint> */
    public function tables(): array
    {
        return $this->tables;
    }

    public function table(string $logicalHandle): ?PhysicalTableBlueprint
    {
        foreach ($this->tables as $table) {
            if ($table->logicalName === $logicalHandle) {
                return $table;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'definition_version' => $this->definitionVersion,
            'definition_checksum' => $this->definitionChecksum,
            'tables' => array_map(
                static fn (PhysicalTableBlueprint $table): array => $table->toArray(),
                $this->tables,
            ),
        ];
    }

    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }
}
