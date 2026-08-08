<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordNotFound extends BusinessRecordException
{
    public function __construct()
    {
        parent::__construct('business_record.not_found', 'The business record was not found.');
    }
}
