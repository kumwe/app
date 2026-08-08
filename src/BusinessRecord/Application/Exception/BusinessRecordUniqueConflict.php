<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordUniqueConflict extends BusinessRecordException
{
    public function __construct(public readonly ?string $field = null)
    {
        parent::__construct('business_record.unique_conflict', 'A unique business-record value is already in use.');
    }
}
