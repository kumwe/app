<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class BooleanFilter implements RecordFilter
{
    /** @var non-empty-list<RecordFilter> */
    public array $children;

    /** @param non-empty-list<RecordFilter> $children */
    public function __construct(public BooleanOperator $operator, array $children)
    {
        if ($children === [] || count($children) > 16) {
            throw new InvalidArgumentException('A boolean query group requires between 1 and 16 children.');
        }
        if ($operator === BooleanOperator::Not && count($children) !== 1) {
            throw new InvalidArgumentException('A boolean NOT query group requires exactly one child.');
        }
        $this->children = array_values($children);
    }

    public function toArray(): array
    {
        return ['type' => 'boolean', 'operator' => $this->operator->value,
            'children' => array_map(static fn (RecordFilter $filter): array => $filter->toArray(), $this->children)];
    }

    public function operationCount(): int
    {
        return 1 + array_sum(array_map(
            static fn (RecordFilter $filter): int => $filter->operationCount(),
            $this->children,
        ));
    }

    public function depth(): int
    {
        return 1 + max(array_map(static fn (RecordFilter $filter): int => $filter->depth(), $this->children));
    }

    public function relationDepth(): int
    {
        return max(array_map(static fn (RecordFilter $filter): int => $filter->relationDepth(), $this->children));
    }
}
