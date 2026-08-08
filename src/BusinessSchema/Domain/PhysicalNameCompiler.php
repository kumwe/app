<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\Shared\Domain\DatabaseTablePrefix;
use Ramsey\Uuid\Uuid;

final readonly class PhysicalNameCompiler
{
    private const MAXIMUM_BYTES = 63;
    private const DIGEST_HEX_CHARACTERS = 20;

    private string $prefix;

    public function __construct(string $prefix = 'kb_')
    {
        if (!DatabaseTablePrefix::isValid($prefix)) {
            throw new InvalidBusinessSchema('The physical schema table prefix is invalid.');
        }
        $this->prefix = $prefix;
    }

    public function entityTable(string $definitionId, string $handle): string
    {
        SchemaDocument::assertUuid($definitionId, 'The entity-table definition ID');
        SchemaDocument::assertIdentifier($handle, 'The entity-table handle');

        return $this->compile('e', [$definitionId, $handle]);
    }

    public function relationTable(string $definitionId, string $handle): string
    {
        SchemaDocument::assertUuid($definitionId, 'The relation-table definition ID');
        SchemaDocument::assertIdentifier($handle, 'The relation-table handle');

        return $this->compile('r', [$definitionId, $handle]);
    }

    public function lineTable(string $definitionId, string $handle): string
    {
        SchemaDocument::assertUuid($definitionId, 'The line-table definition ID');
        SchemaDocument::assertIdentifier($handle, 'The line-table handle');

        return $this->compile('l', [$definitionId, $handle]);
    }

    public function column(string $logicalName): string
    {
        SchemaDocument::assertIdentifier($logicalName, 'The column handle');

        return $this->compile('c', [$logicalName]);
    }

    /** @param list<string> $columns */
    public function index(string $tableLogicalName, string $logicalName, array $columns = []): string
    {
        SchemaDocument::assertIdentifier($tableLogicalName, 'The index table handle');
        SchemaDocument::assertIdentifier($logicalName, 'The index handle');
        $this->assertParts($columns, 'index column');

        return $this->compile('i', [$tableLogicalName, $logicalName, ...$columns]);
    }

    /** @param list<string> $columns */
    public function foreignKey(string $tableLogicalName, string $logicalName, array $columns = []): string
    {
        SchemaDocument::assertIdentifier($tableLogicalName, 'The foreign-key table handle');
        SchemaDocument::assertIdentifier($logicalName, 'The foreign-key handle');
        $this->assertParts($columns, 'foreign-key column');

        return $this->compile('f', [$tableLogicalName, $logicalName, ...$columns]);
    }

    /** @param list<string> $parts */
    public function compile(string $category, array $parts): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $category) !== 1 || $parts === []) {
            throw new InvalidBusinessSchema('A physical name category or metadata part collection is invalid.');
        }
        $this->assertParts($parts, 'physical-name metadata part');
        $canonical = $category . "\0" . implode("\0", array_map(strtolower(...), $parts));
        $digest = substr(hash('sha256', $canonical), 0, self::DIGEST_HEX_CHARACTERS);
        $readable = strtolower(implode('_', $parts));
        $readable = (string) preg_replace('/[^a-z0-9]+/', '_', $readable);
        $readable = trim($readable, '_');
        $stem = $this->prefix . $category . '_' . $readable;
        $maximumStem = self::MAXIMUM_BYTES - strlen($digest) - 1;
        $stem = rtrim(substr($stem, 0, $maximumStem), '_');
        $name = $stem . '_' . $digest;
        SchemaDocument::assertPhysicalIdentifier($name, 'The compiled physical name');

        return $name;
    }

    /** @param list<string> $parts */
    private function assertParts(array $parts, string $subject): void
    {
        if (count($parts) > 64) {
            throw new InvalidBusinessSchema('A physical name has too many metadata parts.');
        }
        foreach ($parts as $part) {
            if (Uuid::isValid($part)) {
                continue;
            }
            SchemaDocument::assertIdentifier($part, 'The ' . $subject);
        }
    }
}
