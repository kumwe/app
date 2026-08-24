<?php

declare(strict_types=1);

namespace KumweContract\ManifestSix;

use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRenderer;

/**
 * Safe executable preview half of the fixture's signed grid host binding.
 *
 * @since  2.0.0
 */
final readonly class GridPreviewRenderer implements StudioPreviewBlockRenderer
{
    /**
     * Present the bounded grid column count as plain text through the host's safe fragment vocabulary.
     *
     * @param   StudioPreviewBlock          $block     Immutable copied contributed grid input.
     * @param   StudioPreviewBindingResult  $binding   Authorized value projection, unused by this layout block.
     * @param   string                      $viewport  Active semantic viewport, retained in the visible proof.
     *
     * @return  StudioPreviewBlockFragment  Safe semantic fixture output.
     *
     * @since   2.0.0
     */
    public function render(
        StudioPreviewBlock $block,
        StudioPreviewBindingResult $binding,
        string $viewport,
    ): StudioPreviewBlockFragment {
        $columns = $block->property('columns');
        $text = is_int($columns)
            ? sprintf('Contributed grid: %d columns (%s)', $columns, $viewport)
            : 'Contributed grid';

        return new StudioPreviewBlockFragment(
            'section',
            'studio-preview-extension-grid',
            $text,
            $binding->hidden,
        );
    }
}
