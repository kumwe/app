<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

/**
 * Safe extension implementation contract for one manifest-bound Studio preview renderer.
 *
 * The host selects an implementation only after reconciling its owner-local service with the exact
 * signed block definition and dependency-lock coordinate. Implementations receive no Content service,
 * template environment, request, or authorization context and can return only the closed semantic
 * fragment the host escapes and projects into its canonical page template.
 *
 * @since  2.0.0
 */
interface StudioPreviewBlockRenderer
{
    /**
     * Present one admitted node and its already-authorized value as a bounded semantic fragment.
     *
     * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
     * @param   StudioPreviewBindingResult  $binding   Already-authorized canonical `value` port.
     * @param   string                      $viewport  Active semantic viewport.
     *
     * @return  StudioPreviewBlockFragment  Closed element, class, text, visibility, and layout vocabulary.
     *
     * @since   2.0.0
     */
    public function render(
        StudioPreviewBlock $block,
        StudioPreviewBindingResult $binding,
        string $viewport,
    ): StudioPreviewBlockFragment;
}
