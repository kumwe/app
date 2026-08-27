<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use RuntimeException;

/**
 * Non-disclosing refusal for an absent, foreign, malformed, expired, or unauthorized authoring context.
 *
 * @since  2.0.0
 */
final class ContentStudioAuthoringContextRefused extends RuntimeException
{
}
