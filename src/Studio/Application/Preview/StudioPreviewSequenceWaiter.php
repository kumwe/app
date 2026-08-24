<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

/**
 * Bounded scheduling pause between atomic claims of one immediate-future preview sequence.
 *
 * @since  2.0.0
 */
interface StudioPreviewSequenceWaiter
{
    /**
     * Yield briefly so the immediately preceding HTTP request can claim its sequence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function pause(): void;
}
