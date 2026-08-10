<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

/**
 * Transaction-local fact dispatched synchronously to deterministic domain listeners.
 *
 * @since  2.0.0
 */
final readonly class DomainEvent extends EventEnvelope
{
}
