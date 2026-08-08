<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class SetFilter implements RecordFilter
{
    /** @var non-empty-list<mixed> */
    public array $values;

    /** @param non-empty-list<mixed> $values */
    public function __construct(public string $field, array $values, public bool $negated = false)
    {
        QueryIdentifier::assertField($field);
        if ($values === [] || count($values) > 100) {
            throw new InvalidArgumentException('A set filter requires between 1 and 100 values.');
        }
        foreach ($values as $value) {
            if ($value === null) {
                throw new InvalidArgumentException('Set filters cannot contain null; use an explicit null filter.');
            }
            QueryValue::assert($value);
        }
        $this->values = array_values($values);
    }

    public function toArray(): array
    {
        return ['type' => 'set', 'field' => $this->field, 'negated' => $this->negated,
            'values' => array_map(QueryCanonicalizer::value(...), $this->values)];
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
