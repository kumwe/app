<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use RuntimeException;

/**
 * Non-enumerating refusal used for an absent or unavailable report contribution.
 *
 * @since  2.0.0
 */
final class ReportUnavailable extends RuntimeException
{
}
