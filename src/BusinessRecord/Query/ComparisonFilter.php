<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class ComparisonFilter implements RecordFilter
{
    public function __construct(
        public string $field,
        public ComparisonOperator $operator,
        public mixed $value,
    ) {
        QueryIdentifier::assertField($field);
        if ($value === null) {
            throw new InvalidArgumentException('Null comparisons require an explicit null filter.');
        }
        QueryValue::assert($value);
    }

    public function toArray(): array
    {
        return ['type' => 'comparison', 'field' => $this->field, 'operator' => $this->operator->value,
            'value' => QueryCanonicalizer::value($this->value)];
    }

    public function operationCount(): int
    {
        return 1;
    }

    public function depth(): int
    {
        return 1;
    }

    public function relationDepth(): int
    {
        return 0;
    }
}
