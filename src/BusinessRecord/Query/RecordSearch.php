<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class RecordSearch
{
    /** @var non-empty-list<string> */
    public array $fields;

    /** @param non-empty-list<string> $fields */
    public function __construct(public string $term, array $fields)
    {
        if (trim($term) === '' || mb_strlen($term) > 256) {
            throw new InvalidArgumentException('A business-record search term requires 1 to 256 characters.');
        }
        if ($fields === [] || count($fields) > 16) {
            throw new InvalidArgumentException('A business-record search requires between 1 and 16 fields.');
        }
        foreach ($fields as $field) {
            QueryIdentifier::assertField($field);
        }
        $fields = array_values(array_unique($fields));
        sort($fields, SORT_STRING);
        $this->fields = $fields;
    }

    /** @return array{term: string, fields: non-empty-list<string>} */
    public function toArray(): array
    {
        return ['term' => $this->term, 'fields' => $this->fields];
    }
}
