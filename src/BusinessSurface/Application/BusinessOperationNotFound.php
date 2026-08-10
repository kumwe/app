<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application;

use RuntimeException;

/**
 * Non-enumerating refusal for an absent, expired or differently scoped business operation.
 *
 * @since  2.0.0
 */
final class BusinessOperationNotFound extends RuntimeException
{
    /**
     * Construct the one caller-safe operation lookup message.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The business operation is unavailable.');
    }
}
