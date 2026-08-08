<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class InvalidBusinessRecordQuery extends BusinessRecordException
{
    public function __construct(string $reason)
    {
        parent::__construct('business_record.invalid_query', $reason);
    }
}
