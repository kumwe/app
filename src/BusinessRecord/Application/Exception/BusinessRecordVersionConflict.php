<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordVersionConflict extends BusinessRecordException
{
    public function __construct(public readonly int $expectedVersion, public readonly ?int $actualVersion)
    {
        parent::__construct(
            'business_record.version_conflict',
            'The business record changed after the supplied expected version.',
        );
    }
}
