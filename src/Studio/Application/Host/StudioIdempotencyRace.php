<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use RuntimeException;

/**
 * Internal signal that another transaction atomically claimed an idempotency scope.
 *
 * @since  2.0.0
 */
final class StudioIdempotencyRace extends RuntimeException
{
}
