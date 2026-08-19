<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

/**
 * Verifies that a long-lived dispatcher still runs the exact trusted runtime generation it loaded.
 *
 * @since  2.0.0
 */
interface TrustedRuntimeGenerationGuard
{
    /**
     * Reject a claim when its pinned runtime generation is no longer authoritative.
     *
     * @param   string  $generation  Generation pinned onto the durable claim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertCurrent(string $generation): void;
}
