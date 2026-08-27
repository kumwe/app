<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use LogicException;

/**
 * Signals that trusted App Content facts cannot form one internally consistent Studio target.
 *
 * This typed logic failure lets the contextual authority collapse an expected coordinate mismatch without
 * disguising an unrelated programming failure as an authorization refusal.
 *
 * @since  2.0.0
 */
final class ContentStudioAuthoringTargetMismatch extends LogicException
{
}
