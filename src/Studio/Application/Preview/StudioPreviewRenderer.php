<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;

/**
 * Canonical presentation boundary shared with published site output.
 *
 * @since  2.0.0
 */
interface StudioPreviewRenderer
{
    /**
     * Render a canonical draft through the site's real template and theme pipeline.
     *
     * @param   StudioHostSessionSnapshot   $snapshot  Live trusted Studio authority.
     * @param   StudioPreviewDraft          $draft     Exact canonical unpublished Blueprint.
     * @param   StudioPreviewRenderRequest  $request   Exact render attempt and viewport.
     * @param   StudioPreviewBindingValues  $values    Authorized values for Blueprint binding resolution.
     *
     * @return  StudioPreviewRenderedDocument  Complete HTML and exact marker inventory.
     *
     * @throws  StudioCompositionThemeMismatch  When the draft's public-theme lock is no longer live.
     *
     * @since   2.0.0
     */
    public function render(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewDraft $draft,
        StudioPreviewRenderRequest $request,
        StudioPreviewBindingValues $values,
    ): StudioPreviewRenderedDocument;
}
