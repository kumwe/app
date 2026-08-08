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

    /**
     * The authoritative lifecycle rule shared by the User aggregate and the access-control service.
     *
     * Disabling is terminal so that a revoked account cannot be silently restored, and suspension
     * applies only to an account that is currently able to authenticate.
     */
    public function canTransitionTo(self $status): bool
    {
        if ($this === $status) {
            return true;
        }

        return match ($status) {
            self::Disabled => true,
            self::Active => $this !== self::Disabled,
            self::Suspended => $this === self::Active,
            self::Pending => false,
        };
    }
}
