<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordDefinitionUnavailable extends BusinessRecordException
{
    public function __construct(string $reason = 'The business definition is unavailable.')
    {
        parent::__construct('business_record.definition_unavailable', $reason);
    }
}
