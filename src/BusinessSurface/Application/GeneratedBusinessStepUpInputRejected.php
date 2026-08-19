<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use InvalidArgumentException;

/**
 * Signals incomplete browser verification controls without masking action-execution failures.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessStepUpInputRejected extends InvalidArgumentException
{
}
