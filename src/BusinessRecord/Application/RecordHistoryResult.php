<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
final readonly class RecordHistoryResult
{
    /** @var list<BusinessRecordRevisionView> */
    public array $revisions;

    /** @param list<BusinessRecordRevisionView> $revisions */
    public function __construct(array $revisions, public bool $hasMore)
    {
        if (count($revisions) > 200) {
            throw new InvalidArgumentException('A business-record history result exceeds 200 revisions.');
        }
        $this->revisions = array_values($revisions);
    }
}
