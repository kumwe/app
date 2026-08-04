<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class BlockNode
{
    /** @var array<string, mixed> */
    private array $properties;

    /** @var list<BlockNode> */
    private array $children;

    /**
     * @param array<array-key, mixed> $properties
     * @param array<mixed>            $children
     */
    public function __construct(
        private string $id,
        private string $type,
        array $properties = [],
        array $children = [],
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A block node ID must be a canonical UUID.');
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $type) !== 1) {
            throw new InvalidArgumentException('A block type must be a stable lowercase identifier.');
        }

        foreach ($properties as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Block property keys must be strings.');
            }

            self::assertJsonValue($value);
        }

        if (!array_is_list($children)) {
            throw new InvalidArgumentException('Block children must be an ordered list.');
        }

        foreach ($children as $child) {
            if (!($child instanceof self)) {
                throw new InvalidArgumentException('Block children must be block nodes.');
            }
        }

        /** @var array<string, mixed> $properties */
        $this->properties = $properties;
        /** @var list<BlockNode> $children */
        $this->children = $children;
    }

    public function id(): string
    {
        return strtolower($this->id);
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function properties(): array
    {
        return $this->properties;
    }

    /** @return list<BlockNode> */
    public function children(): array
    {
        return $this->children;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'type' => $this->type,
            'properties' => $this->properties,
            'children' => array_map(
                static fn (self $child): array => $child->toArray(),
                $this->children,
            ),
        ];
    }

    private static function assertJsonValue(mixed $value): void
    {
        if ($value === null || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_string($value) && mb_check_encoding($value, 'UTF-8')) {
            return;
        }

        if (is_float($value) && is_finite($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                self::assertJsonValue($nested);
            }

            return;
        }

        throw new InvalidArgumentException('Block properties must contain only JSON-compatible values.');
    }
}
