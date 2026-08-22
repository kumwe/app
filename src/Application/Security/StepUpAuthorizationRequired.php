<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Security;

/**
 * Marks a refusal that can proceed only after a fresh human authorization proof.
 *
 * Feature application layers own their concrete exceptions, while delivery and protocol adapters need one
 * inward-facing classification that does not couple them to a sibling feature. The marker deliberately has
 * no methods: the public response must use fixed adapter-owned wording rather than an exception message.
 *
 * @since  2.0.0
 */
interface StepUpAuthorizationRequired
{
}
