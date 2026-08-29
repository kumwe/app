<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\App\Content\Application\ContentRecord;
use Kumwe\Producer\Render\RenderResult;

/**
 * Optional public rendering boundary for a Content record's exact Studio Blueprint binding.
 *
 * @since  2.0.0
 */
interface StudioPublishedContentRenderer
{
    /**
     * Render an exact published composition, or retain the legacy Content layout when none is active.
     *
     * @param   ContentRecord  $record  Published record selected by the public Content boundary.
     *
     * @return  ?RenderResult  Canonical Producer HTML, complete CSS and zero enhancements, or null when no
     *          published composition applies.
     *
     * @since   2.0.0
     */
    public function render(ContentRecord $record): ?RenderResult;
}
