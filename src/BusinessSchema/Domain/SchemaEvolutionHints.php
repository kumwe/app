<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\Expression;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;

final readonly class SchemaEvolutionHints
{
    private const KEYS = ['column_renames', 'backfills', 'transforms', 'repins'];

    private const MAXIMUM_HINTS_PER_KIND = 256;

    /** @var array<string, array<string, string>> */
    private array $renamesByTable;

    /** @var array<string, bool|int|string|Expression> */
    private array $backfills;

    /** @var array<string, Expression> */
    private array $transforms;

    /** @var array<string, int> */
    private array $repins;

    /**
     * @param array<string, array<string, string>> $renamesByTable
     * @param array<string, bool|int|string|Expression> $backfills
     * @param array<string, Expression> $transforms
     * @param array<string, int> $repins
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

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly($document, self::KEYS, 'Schema-evolution hints');
        $renames = self::parseRenames(self::metadataObject($document, 'column_renames'));
        $backfills = self::parseBackfills(self::metadataObject($document, 'backfills'));
        $transforms = self::parseTransforms(self::metadataObject($document, 'transforms'));
        $repins = self::parseRepins(self::metadataObject($document, 'repins'));

        return new self($renames, $backfills, $transforms, $repins);
    }

    /** @return array<string, string> Old logical column to new logical column. */
    public function renameForTable(string $logicalTable): array
    {
        SchemaDocument::assertIdentifier($logicalTable, 'The schema-evolution table');

        return $this->renamesByTable[$logicalTable] ?? [];
    }

    public function hasBackfill(string $logicalColumn): bool
    {
        SchemaDocument::assertIdentifier($logicalColumn, 'The schema backfill column');

        return array_key_exists($logicalColumn, $this->backfills);
    }

    public function backfill(string $logicalColumn): bool|int|string|Expression|null
    {
        SchemaDocument::assertIdentifier($logicalColumn, 'The schema backfill column');

        return $this->backfills[$logicalColumn] ?? null;
    }

    public function transform(string $logicalColumn): ?Expression
    {
        SchemaDocument::assertIdentifier($logicalColumn, 'The schema transform column');

        return $this->transforms[$logicalColumn] ?? null;
    }

    /** @return array<string, Expression> */
    public function transforms(): array
    {
        return $this->transforms;
    }

    /** @return array<string, array<string, string>> */
    public function renames(): array
    {
        return $this->renamesByTable;
    }

    /** @return array<string, bool|int|string|Expression> */
    public function backfills(): array
    {
        return $this->backfills;
    }

    public function repin(string $definitionHandle): ?int
    {
        self::assertDefinitionHandle($definitionHandle);

        return $this->repins[$definitionHandle] ?? null;
    }

    /** @return array<string, int> */
    public function repins(): array
    {
        return $this->repins;
    }

    /** @return array<string, mixed> */
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

    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
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
     * @param array<string, mixed> $document
     * @return array<string, array<string, string>>
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
     * @param array<string, mixed> $document
     * @return array<string, bool|int|string|Expression>
     */
    private static function parseBackfills(array $document): array
    {
        self::assertBounded($document, 'backfills');
        $result = [];
        foreach ($document as $logicalColumn => $literal) {
            SchemaDocument::assertIdentifier($logicalColumn, 'A schema backfill column');
            if (is_array($literal) && !array_is_list($literal)) {
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
     * @param array<string, mixed> $document
     * @return array<string, Expression>
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
     * @param array<string, mixed> $document
     * @return array<string, int>
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

    /** @return array{string, string} */
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

    /** @param array<string, string> $renames */
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

    /** @param array<string, mixed> $document */
    private static function assertBounded(array $document, string $subject): void
    {
        if (count($document) > self::MAXIMUM_HINTS_PER_KIND) {
            throw new InvalidBusinessSchema('Schema evolution contains too many ' . $subject . '.');
        }
    }

    private static function looksLikeEvolutionKey(string $key): bool
    {
        return preg_match(
            '/^(?:schema(?:_|$)|evolution(?:_|$)|column_renames?(?:_|$)|backfills?(?:_|$)'
                . '|transforms?(?:_|$)|transformations?(?:_|$)|repins?(?:_|$))/D',
            $key,
        ) === 1;
    }

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
