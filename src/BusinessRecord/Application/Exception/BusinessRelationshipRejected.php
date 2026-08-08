<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRelationshipRejected extends BusinessRecordException
{
    public function __construct(string $reason = 'The requested business relationship mutation is invalid.')
    {
        parent::__construct('business_record.relationship_rejected', $reason);
    }
}
