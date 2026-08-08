<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

final class BusinessRecordSchemaUnavailable extends BusinessRecordException
{
    public function __construct(string $reason = 'The installed business schema is unavailable.')
    {
        parent::__construct('business_record.schema_unavailable', $reason);
    }
}
