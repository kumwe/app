<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

final readonly class NullFilter implements RecordFilter
{
    public function __construct(public string $field, public bool $isNull = true)
    {
        QueryIdentifier::assertField($field);
    }

    public function toArray(): array
    {
        return ['type' => 'null', 'field' => $this->field, 'is_null' => $this->isNull];
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
