<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use Kumwe\Extension\Spi\BusinessIntegration\Domain\DomainEvent;

/**
 * Transaction-local fact dispatched synchronously to deterministic domain listeners.
 *
 * @since  2.0.0
 */
final readonly class RecordedDomainEvent extends RecordedEventEnvelope implements DomainEvent
{
}
