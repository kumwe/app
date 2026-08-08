<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Query\RecordCursor;

final readonly class RecordBrowseResult
{
    /** @var list<BusinessRecordView> */
    public array $records;

    /** @var array<string, int|string|null> */
    public array $aggregates;

    /**
     * @param list<BusinessRecordView> $records
     * @param array<string, int|string|null> $aggregates
     */
    public function __construct(array $records, public ?RecordCursor $nextCursor = null, array $aggregates = [])
    {
        if (count($records) > 200 || count($aggregates) > 16) {
            throw new InvalidArgumentException('A business-record browse result exceeds its declared bounds.');
        }
        $this->records = array_values($records);
        $this->aggregates = $aggregates;
    }
}
