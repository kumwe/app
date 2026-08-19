<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

/**
 * Application boundary implemented by one extension-specific business record action.
 *
 * Implementations receive concurrency, idempotency, approval, and execution context as typed values.
 * They must perform mutations through authorized, audited transactional application services rather than
 * accepting a transport request, resolving a container, or writing core persistence directly.
 *
 * @since  2.0.0
 */
interface CustomBusinessActionHandler
{
    /**
     * Execute or replay the custom action and return its bounded versioned result.
     *
     * @param   CustomBusinessActionCommand  $command  Schema-validated command carrying all mutation guards.
     *
     * @return  CustomBusinessActionResult  Result tied to the command's operation identity.
     *
     * @since   2.0.0
     */
    public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult;
}
