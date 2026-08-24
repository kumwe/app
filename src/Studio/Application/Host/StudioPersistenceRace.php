<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use RuntimeException;

/**
 * Internal compare-and-set signal for a concurrent Studio persistence claimant.
 *
 * @since  2.0.0
 */
final class StudioPersistenceRace extends RuntimeException
{
}
