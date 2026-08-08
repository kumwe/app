<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordActionRejected extends BusinessRecordException
{
    public function __construct(string $reason = 'The requested business action is not valid for this record.')
    {
        parent::__construct('business_record.action_rejected', $reason);
    }
}
