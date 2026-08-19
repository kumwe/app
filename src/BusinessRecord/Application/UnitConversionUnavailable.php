<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use RuntimeException;

/**
 * Refusal raised when no contributed provider can relate the units of a conversion.
 *
 * An installation with no conversion table is the ordinary state of core, so this is a stated outcome
 * rather than a defect: the caller presents the stored quantity in its own unit instead of an
 * unevidenced converted one.
 *
 * @since  2.0.0
 */
final class UnitConversionUnavailable extends RuntimeException
{
}
