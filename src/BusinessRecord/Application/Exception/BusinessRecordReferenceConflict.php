<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordReferenceConflict extends BusinessRecordException
{
    public function __construct(public readonly ?string $relationship = null)
    {
        parent::__construct(
            'business_record.reference_conflict',
            'A business-record reference is missing or prevents this mutation.',
        );
    }
}
