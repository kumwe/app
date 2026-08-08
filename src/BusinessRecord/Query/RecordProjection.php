<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class RecordProjection
{
    /** @var list<string> */
    public array $fields;

    /** @var list<string> */
    public array $includes;

    /** @var list<RecordAggregate> */
    public array $aggregates;

    /**
     * @param list<string> $fields
     * @param list<string> $includes
     * @param list<RecordAggregate> $aggregates
     */
    public function __construct(array $fields = [], array $includes = [], array $aggregates = [])
    {
        if (count($fields) > 64 || count($includes) > 4 || count($aggregates) > 16) {
            throw new InvalidArgumentException(
                'A business-record projection exceeds its field, include, or aggregate limit.',
            );
        }
        foreach (array_merge($fields, $includes) as $identifier) {
            QueryIdentifier::assertField($identifier);
        }
        $aliases = [];
        foreach ($aggregates as $aggregate) {
            if (isset($aliases[$aggregate->alias])) {
                throw new InvalidArgumentException('A business-record aggregate alias is duplicated.');
            }
            $aliases[$aggregate->alias] = true;
        }
        $this->fields = array_values(array_unique($fields));
        $this->includes = array_values(array_unique($includes));
        $this->aggregates = array_values($aggregates);
    }

    /** @return array{fields: list<string>, includes: list<string>, aggregates: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'includes' => $this->includes,
            'aggregates' => array_map(static fn (RecordAggregate $item): array => $item->toArray(), $this->aggregates),
        ];
    }
}
