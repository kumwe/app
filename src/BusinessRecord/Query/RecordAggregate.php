<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class RecordAggregate
{
    public function __construct(
        public string $alias,
        public AggregateFunction $function,
        public ?string $field = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $alias) !== 1) {
            throw new InvalidArgumentException('A record aggregate alias is invalid.');
        }
        if (($function === AggregateFunction::Count) !== ($field === null)) {
            throw new InvalidArgumentException('Only count aggregates omit a field.');
        }
        if ($field !== null) {
            QueryIdentifier::assertField($field);
        }
    }

    /** @return array{alias: string, function: string, field: ?string} */
    public function toArray(): array
    {
        return ['alias' => $this->alias, 'function' => $this->function->value, 'field' => $this->field];
    }
}
