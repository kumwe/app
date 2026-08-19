<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use RuntimeException;

/**
 * Signals that a policy-filtered result must move to the bounded queued-export path.
 *
 * @since  2.0.0
 */
final class ReportRowLimitExceeded extends RuntimeException
{
}
