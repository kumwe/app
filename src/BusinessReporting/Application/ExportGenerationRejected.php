<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use RuntimeException;

/**
 * Permanent refusal when an export request no longer matches current authority or policy.
 *
 * @since  2.0.0
 */
final class ExportGenerationRejected extends RuntimeException
{
}
