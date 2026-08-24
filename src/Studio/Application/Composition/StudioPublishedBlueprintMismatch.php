<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use RuntimeException;

/**
 * Refuses a configured artifact that is not the exact compatible host-owned Blueprint.
 *
 * @since  2.0.0
 */
final class StudioPublishedBlueprintMismatch extends RuntimeException
{
    /**
     * Create the stable public fail-closed refusal.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The configured Studio artifact is not a compatible published Blueprint.');
    }
}
