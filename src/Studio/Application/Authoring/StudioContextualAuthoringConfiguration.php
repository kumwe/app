<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

/**
 * Marks one canonical, PHP-supplied configuration for a contextual Studio mount.
 *
 * The published Studio configuration contract owns the eventual members and browser encoding. Until
 * that contract is released, App keeps the value opaque: no provisional array shape, endpoint
 * convention, authentication field, or serializer belongs at this boundary.
 *
 * @since  2.0.0
 */
interface StudioContextualAuthoringConfiguration
{
}
