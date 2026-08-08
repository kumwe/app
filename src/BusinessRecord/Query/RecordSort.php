<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

final readonly class RecordSort
{
    public function __construct(
        public string $field,
        public SortDirection $direction = SortDirection::Ascending,
        public bool $nullsLast = true,
    ) {
        QueryIdentifier::assertField($field);
    }

    /** @return array{field: string, direction: string, nulls_last: bool} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'direction' => $this->direction->value, 'nulls_last' => $this->nullsLast];
    }
}
