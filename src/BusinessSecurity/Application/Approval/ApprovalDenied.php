<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Application\Approval;

/**
 * Generic approval failure that deliberately does not disclose request existence or rule detail.
 *
 * @since  2.0.0
 */
final class ApprovalDenied extends \RuntimeException
{
    /**
     * Create the common non-enumerating approval denial.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The requested approval operation is not permitted.');
    }
}
