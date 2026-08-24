<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Studio\Domain\Host\StudioHostSession;

/**
 * Persistence boundary for opaque Studio resource-context bindings.
 *
 * @since  2.0.0
 */
interface StudioHostSessionRepository
{
    /**
     * Persist a newly opened binding before its key is disclosed.
     *
     * @param   StudioHostSession  $session  Fully verified immutable binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(StudioHostSession $session): void;

    /**
     * Resolve an opaque context key without accepting scope coordinates from the caller.
     *
     * @param   string  $resourceContextKey  Canonical host-envelope key.
     *
     * @return  StudioHostSession|null  Stored binding, or null without disclosing why.
     *
     * @since   2.0.0
     */
    public function find(string $resourceContextKey): ?StudioHostSession;
}
