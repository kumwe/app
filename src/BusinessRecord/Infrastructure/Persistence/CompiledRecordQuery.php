<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use InvalidArgumentException;

final readonly class CompiledRecordQuery
{
    /** @var list<mixed> */
    public array $parameters;

    /** @var list<string> */
    public array $types;

    /** @var list<string> */
    public array $projectedFields;

    /** @var list<array{field: ?string, physical: string}> */
    public array $cursorColumns;

    /** @var list<mixed> */
    public array $aggregateParameters;

    /** @var list<string> */
    public array $aggregateTypes;

    /**
     * @param list<mixed> $parameters
     * @param list<string> $types
     * @param list<string> $projectedFields
     * @param list<array{field: ?string, physical: string}> $cursorColumns
     * @param list<mixed> $aggregateParameters
     * @param list<string> $aggregateTypes
     */
    public function __construct(
        public string $sql,
        public string $cursorDigest,
        array $parameters,
        array $types,
        array $projectedFields,
        array $cursorColumns,
        public ?string $aggregateSql = null,
        array $aggregateParameters = [],
        array $aggregateTypes = [],
    ) {
        if (count($parameters) !== count($types) || count($aggregateParameters) !== count($aggregateTypes)) {
            throw new InvalidArgumentException('A compiled business-record query has mismatched bound parameters.');
        }
        $this->parameters = array_values($parameters);
        $this->types = array_values($types);
        $this->projectedFields = array_values($projectedFields);
        $this->cursorColumns = array_values($cursorColumns);
        $this->aggregateParameters = array_values($aggregateParameters);
        $this->aggregateTypes = array_values($aggregateTypes);
    }
}
