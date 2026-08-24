<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use RuntimeException;

/**
 * Refuses an immutable Blueprint whose locked public theme no longer matches publication.
 *
 * @since  2.0.0
 */
final class StudioCompositionThemeMismatch extends RuntimeException
{
    /**
     * Create the stable refusal used by the localized administrator boundary.
     *
     * @since  2.0.0
     */
    public function __construct()
    {
        parent::__construct('The Studio Blueprint public-theme lock requires an explicit migration.');
    }
}
