<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use RuntimeException;

/**
 * Refuses a configured public composition whose immutable Blueprint cannot be loaded.
 *
 * @since  2.0.0
 */
final class StudioPublishedBlueprintUnavailable extends RuntimeException
{
    /**
     * Create the stable public fail-closed refusal.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The configured published Studio Blueprint is unavailable.');
    }
}
