<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use RuntimeException;

/**
 * A completed export would pass its site's cumulative byte budget for the current window.
 *
 * @since  2.0.0
 */
final class ExportSiteByteBudgetExhausted extends RuntimeException
{
    /**
     * Stable failure code recorded on the refused artifact.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string FAILURE_CODE = 'site_byte_budget';
}
