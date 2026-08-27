<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

/**
 * Application boundary qualifying the pinned contextual Studio release and browser runtime.
 *
 * This evidence never enables a Content mount by itself. `ContentStudioAuthoringLaunchResolver` also
 * requires one canonical PHP-supplied configuration for the exact mount.
 *
 * @since  2.0.0
 */
interface StudioContextualAuthoringAvailability
{
    /**
     * Evaluate exact pinned protocol, packaged-browser, and PHP-adapter evidence.
     *
     * @return  StudioContextualAuthoringReadiness  Ready only when every release/runtime boundary is present.
     *
     * @since   2.0.0
     */
    public function current(): StudioContextualAuthoringReadiness;
}
