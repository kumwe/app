<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use RuntimeException;

/**
 * Refuses a public composition whose immutable Content-model lock is no longer exact.
 *
 * @since  2.0.0
 */
final class StudioPublishedModelMismatch extends RuntimeException
{
    /**
     * Create the stable public fail-closed refusal.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The published Studio Blueprint Content-model lock is incompatible.');
    }
}
