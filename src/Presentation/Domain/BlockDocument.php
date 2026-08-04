<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;
use JsonException;

final readonly class BlockDocument
{
    private const MAX_NODES = 500;
    private const MAX_DEPTH = 12;

    /**
     * @param list<BlockNode> $roots
     */
    private function __construct(
        private string $id,
        private int $schemaVersion,
        private array $roots,
        private int $version,
        private string $checksum,
    ) {
    }

    /**
     * @param list<BlockNode> $roots
     *
     * @throws JsonException
     */
    public static function create(
        string $id,
        int $schemaVersion,
        array $roots,
        BlockSchemaRegistry $schemas,
    ): self {
        self::assertUuid($id);

        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('A block schema version must be at least one.');
        }

        self::validateTree($roots, $schemas);
        $checksum = self::checksum($schemaVersion, $roots);

        return new self(strtolower($id), $schemaVersion, $roots, 1, $checksum);
    }

    /**
     * @param list<BlockNode> $roots
     *
     * @throws JsonException
     */
    public function revise(int $expectedVersion, array $roots, BlockSchemaRegistry $schemas): self
    {
        if ($expectedVersion !== $this->version) {
            throw new InvalidBlockDocument(sprintf(
                'Expected block document version %d, current version is %d.',
                $expectedVersion,
                $this->version,
            ));
        }

        self::validateTree($roots, $schemas);

        return new self(
            $this->id,
            $this->schemaVersion,
            $roots,
            $this->version + 1,
            self::checksum($this->schemaVersion, $roots),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /** @return list<BlockNode> */
    public function roots(): array
    {
        return $this->roots;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function checksum(): string
    {
        return $this->checksum;
    }

    /**
     * @param list<BlockNode> $roots
     */
    private static function validateTree(array $roots, BlockSchemaRegistry $schemas): void
    {
        if (!array_is_list($roots)) {
            throw new InvalidArgumentException('Block document roots must be an ordered list.');
        }

        $seen = [];
        $nodeCount = 0;

        foreach ($roots as $root) {
            if (!$root instanceof BlockNode) {
                throw new InvalidArgumentException('Block document roots must be block nodes.');
            }

            self::validateNode($root, $schemas, 1, $nodeCount, $seen);
        }
    }

    /** @param array<string, true> $seen */
    private static function validateNode(
        BlockNode $node,
        BlockSchemaRegistry $schemas,
        int $depth,
        int &$nodeCount,
        array &$seen,
    ): void {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidBlockDocument('A block document cannot exceed 12 levels.');
        }

        if (++$nodeCount > self::MAX_NODES) {
            throw new InvalidBlockDocument('A block document cannot exceed 500 nodes.');
        }

        if (isset($seen[$node->id()])) {
            throw new InvalidBlockDocument(sprintf('Block node %s occurs more than once.', $node->id()));
        }

        $seen[$node->id()] = true;
        $schemas->schemaFor($node->type())->validate($node);

        foreach ($node->children() as $child) {
            self::validateNode($child, $schemas, $depth + 1, $nodeCount, $seen);
        }
    }

    /**
     * @param list<BlockNode> $roots
     *
     * @throws JsonException
     */
    private static function checksum(int $schemaVersion, array $roots): string
    {
        return hash('sha256', json_encode(
            self::canonicalize([
                'schema_version' => $schemaVersion,
                'roots' => array_map(static fn (BlockNode $node): array => $node->toArray(), $roots),
            ]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    private static function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A block document ID must be a canonical UUID.');
        }
    }
}
