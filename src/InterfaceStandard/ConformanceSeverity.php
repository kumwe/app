<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

/**
 * Merge significance of one deterministic interface conformance diagnostic.
 *
 * @since  2.0.0
 */
enum ConformanceSeverity: string
{
    /**
     * Contract breach that prevents the declaration from being admitted.
     *
     * @since  2.0.0
     */
    case Error = 'error';

    /**
     * Non-blocking improvement that remains visible in conformance evidence.
     *
     * @since  2.0.0
     */
    case Warning = 'warning';
}
