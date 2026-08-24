<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

/**
 * Durable admission result for one preview render attempt.
 *
 * @since  2.0.0
 */
enum StudioPreviewRenderAdmission
{
    /**
     * The unique attempt is pending and may enter the renderer.
     *
     * @since  2.0.0
     */
    case Accepted;

    /**
     * A newer cancellation or render already suppresses this transport sequence.
     *
     * @since  2.0.0
     */
    case Cancelled;

    /**
     * The request identity was already observed and cannot be reused.
     *
     * @since  2.0.0
     */
    case Replayed;
}
