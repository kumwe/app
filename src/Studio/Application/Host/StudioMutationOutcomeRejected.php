<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use RuntimeException;

/**
 * Non-disclosing signal that a protected Studio mutation outcome cannot be trusted.
 *
 * @since  2.0.0
 */
final class StudioMutationOutcomeRejected extends RuntimeException
{
}
