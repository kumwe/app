<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Administration;

/**
 * Refusal raised when a Business Security mutation leaves the authenticated membership boundary.
 *
 * @since  2.0.0
 */
final class BusinessSecurityScopeDenied extends \RuntimeException
{
    /**
     * Create a non-enumerating refusal that does not disclose the foreign target.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The Business Security target is unavailable in this authority scope.');
    }
}
