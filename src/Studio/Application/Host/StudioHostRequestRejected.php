<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use RuntimeException;

/**
 * Internal typed rejection of a malformed canonical host envelope.
 *
 * @since  2.0.0
 */
final class StudioHostRequestRejected extends RuntimeException
{
}
