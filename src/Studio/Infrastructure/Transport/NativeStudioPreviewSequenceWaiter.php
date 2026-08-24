<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Transport;

use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceWaiter;

/**
 * Native portable pause for a bounded immediate-predecessor transport wait.
 *
 * @since  2.0.0
 */
final readonly class NativeStudioPreviewSequenceWaiter implements StudioPreviewSequenceWaiter
{
    /**
     * Yield for ten milliseconds without retaining a database lock or transaction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function pause(): void
    {
        usleep(10_000);
    }
}
