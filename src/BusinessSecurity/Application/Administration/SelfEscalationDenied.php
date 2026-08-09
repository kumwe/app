<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Application\Administration;

/**
 * Refusal raised when an administrator mutation would alter the actor's own effective authority.
 *
 * @since  2.0.0
 */
final class SelfEscalationDenied extends \RuntimeException
{
    /**
     * Create a stable refusal that reveals no sensitive authorization state.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('Administrators cannot change their own effective business authority.');
    }
}
