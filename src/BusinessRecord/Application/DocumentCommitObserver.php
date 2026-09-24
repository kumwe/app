<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

/**
 * Receives one notification per committed aggregate document, for low-cardinality operational metrics.
 *
 * Implementations must never throw and must not block; the notification is sent after the command
 * committed and carries nothing that identifies the document, its site or its actor.
 *
 * @since  2.0.0
 */
interface DocumentCommitObserver
{
    /**
     * Report one committed document.
     *
     * @param   int    $lines         Owned lines the command carried.
     * @param   float  $milliseconds  Wall time of the whole command.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function committed(int $lines, float $milliseconds): void;
}
