<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use RuntimeException;

/**
 * Internal signal that another transaction atomically claimed a Studio mutation replay scope.
 *
 * @since  2.0.0
 */
final class StudioMutationReplayRace extends RuntimeException
{
}
