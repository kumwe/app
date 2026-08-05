<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

enum AuthenticationStrength: string
{
    case Password = 'password';
    case BearerToken = 'bearer_token';
    case System = 'system';
}
