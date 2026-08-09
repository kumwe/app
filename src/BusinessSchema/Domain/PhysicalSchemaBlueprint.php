<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Canonical description of every physical table one published definition version installs.
 *
 * This is the unit that gets checksummed and compared: the compiler produces one from a definition, the
 * planner diffs the installed blueprint against the target to derive operations, the executor verifies the
 * result against live introspection, and the installation record stores the blueprint it settled on. Table
 * order is normalized on construction and logical and physical names are proven unique, so two blueprints
 * describing the same schema always produce the same checksum regardless of how they were assembled.
 *
 * @since  2.0.0
 */
final readonly class PhysicalSchemaBlueprint
{
    /**
     * Tables of this schema, ordered by logical then physical name so the checksum is stable.
     *
     * @var    list<PhysicalTableBlueprint>
     * @since  2.0.0
     */
    private array $tables;

    /**
     * Compile a schema from the tables one definition version resolves to.
     *
     * @param string $definitionId UUID of the business definition this schema belongs to.
     * @param   int                           $definitionVersion   Published version the tables were compiled from.
     * @param string $definitionChecksum SHA-256 of that published definition, so drift is detectable.
     * @param list<PhysicalTableBlueprint> $tables Tables to install, in any order; at least one, at most 512.
     *
     * @throws  InvalidBusinessSchema  When the identifier or checksum is malformed, the version is below
     *          one, the table collection is empty or over the bound, or two tables
     *          share a logical name or a case-insensitive physical name.
     *
     * @since   2.0.0
     */
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

    /**
     * Rebuild a blueprint from its persisted document, revalidating every table.
     *
     * @param   array<string, mixed>  $document  Stored blueprint object, as written by `toArray()`.
     *
     * @return  self  The revalidated blueprint, with its tables back in canonical order.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a field is missing or
     *          misshapen, or any table breaks a schema or table invariant.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When a table's stored options
     *          cannot be canonically encoded.
     *
     * @since   2.0.0
     */
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

    /**
     * List every table this schema installs.
     *
     * @return  list<PhysicalTableBlueprint>  The tables in canonical order, never empty.
     *
     * @since   2.0.0
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * Look a table up by the logical handle a plan operation names it with.
     *
     * @param   string  $logicalHandle  Logical table name, such as `record` or `relation:<handle>`.
     *
     * @return  PhysicalTableBlueprint|null  The matching table, or null when this schema declares none.
     *
     * @since   2.0.0
     */
    public function table(string $logicalHandle): ?PhysicalTableBlueprint
    {
        foreach ($this->tables as $table) {
            if ($table->logicalName === $logicalHandle) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Export the blueprint in the shape that is persisted and checksummed.
     *
     * @return  array<string, mixed>  The definition binding plus a `tables` list in canonical order.
     *
     * @since   2.0.0
     */
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

    /**
     * Compute the identity of this schema for approval, installation, and drift checks.
     *
     * @return  string  Lowercase SHA-256 over the canonical JSON encoding of `toArray()`.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }
}
