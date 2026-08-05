<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use RuntimeException;

final class AuthorizationAuditUnavailable extends RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct('An authorization decision could not be recorded safely.', 0, $previous);
    }
}
