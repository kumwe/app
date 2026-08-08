<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordIdempotencyRace extends BusinessRecordException
{
    public function __construct()
    {
        parent::__construct('business_record.idempotency_race', 'A concurrent idempotent command won this key.');
    }
}
