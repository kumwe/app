<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use RuntimeException;

/**
 * Optimistic conflict between two export metadata transitions.
 *
 * @since  2.0.0
 */
final class ExportVersionConflict extends RuntimeException
{
}
