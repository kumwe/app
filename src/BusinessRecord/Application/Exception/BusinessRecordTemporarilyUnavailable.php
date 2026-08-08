<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

use Throwable;

final class BusinessRecordTemporarilyUnavailable extends BusinessRecordException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'business_record.temporarily_unavailable',
            'The business-record operation is temporarily unavailable.',
            $previous,
        );
    }
}
