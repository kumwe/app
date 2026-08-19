<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Domain;

/**
 * Lifecycle state of a user account, together with the rule for which moves between states are legal.
 *
 * The backing values are exactly what the `status` column stores, so `tryFrom()` is how a stored row
 * re-enters the domain and a value this version does not recognise is caught rather than assumed. The
 * type carries more than a label: `canAuthenticate()` is the gate `AuthorizationService` applies before
 * any policy runs, and `canTransitionTo()` is the transition rule that both the `User` aggregate and
 * `AccessControlService` defer to, so a status change requested through an administrator form and one
 * made by a domain method can never disagree about what is allowed.
 *
 * @since  2.0.0
 */
enum UserStatus: string
{
    /**
     * Registered but never activated, so the account exists and holds roles yet cannot sign in.
     *
     * This is where `User::register()` starts an account, and nothing moves an account back here.
     *
     * @since  2.0.0
     */
    case Pending = 'pending';
    /**
     * Usable: the only state in which an account may authenticate and have its grants consulted.
     *
     * @since  2.0.0
     */
    case Active = 'active';
    /**
     * Access withdrawn while the account and its role assignments are kept intact.
     *
     * Reachable only from `Active`, and reversible, so activation restores exactly what was suspended.
     *
     * @since  2.0.0
     */
    case Suspended = 'suspended';
    /**
     * Terminal revocation: reachable from every state and never left again.
     *
     * @since  2.0.0
     */
    case Disabled = 'disabled';

    /**
     * Whether an account in this state may sign in.
     *
     * @return  bool  True only for `Active`; pending, suspended and disabled accounts are all refused.
     *
     * @since   2.0.0
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * The authoritative lifecycle rule shared by the User aggregate and the access-control service.
     *
     * Disabling is terminal so that a revoked account cannot be silently restored, and suspension
     * applies only to an account that is currently able to authenticate. `Pending` is a starting state
     * alone: no other state may move back to it.
     *
     * @param   self  $status  State the caller is asking the account to move to.
     *
     * @return  bool  True when the move is legal. Asking for the state already held always answers true,
     *          which is what lets callers treat a repeated request as a no-op rather than an error.
     *
     * @since   2.0.0
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
