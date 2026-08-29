<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Rendering;

use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderResult;

/**
 * App host policy for canonical Producer render results.
 *
 * The App currently has no Producer enhancement runtime. Refusing the whole result keeps preview and
 * publication from silently discarding requested behavior or inventing a parallel executor; HTML and
 * the complete opaque CSS remain directly consumable when no enhancement was requested.
 *
 * @since  2.0.0
 */
final class StudioRenderResultAdmission
{
    /**
     * Refuse a result that the App cannot consume completely.
     *
     * @throws RenderException When any canonical enhancement was requested.
     *
     * @since 2.0.0
     */
    public static function assertSupported(RenderResult $result): void
    {
        if ($result->enhancements !== []) {
            throw new RenderException('The App has no canonical Producer enhancement runtime.');
        }
    }

    private function __construct()
    {
    }
}
