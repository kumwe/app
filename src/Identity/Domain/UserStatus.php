<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Disabled = 'disabled';

    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }
}
