<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use RuntimeException;

final class AuthenticationThrottled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Too many unsuccessful authentication attempts. Try again later.');
    }
}
