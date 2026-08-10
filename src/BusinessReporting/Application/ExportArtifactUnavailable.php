<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use RuntimeException;

/**
 * Non-enumerating refusal for absent, expired or unauthorized export artifacts.
 *
 * @since  2.0.0
 */
final class ExportArtifactUnavailable extends RuntimeException
{
}
