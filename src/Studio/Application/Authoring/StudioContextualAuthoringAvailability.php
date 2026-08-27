<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

/**
 * Application boundary deciding whether contextual Studio may replace the Content fallback.
 *
 * @since  2.0.0
 */
interface StudioContextualAuthoringAvailability
{
    /**
     * Evaluate exact pinned protocol, packaged-browser, and PHP-adapter evidence.
     *
     * @return  StudioContextualAuthoringReadiness  Ready only when every boundary is present.
     *
     * @since   2.0.0
     */
    public function current(): StudioContextualAuthoringReadiness;
}
