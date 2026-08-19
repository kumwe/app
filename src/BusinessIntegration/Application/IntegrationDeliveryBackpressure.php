<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use InvalidArgumentException;
use RuntimeException;

/**
 * Signals normal durable-delivery backpressure without spending the enclosing outbox attempt budget.
 *
 * @since  2.0.0
 */
final class IntegrationDeliveryBackpressure extends RuntimeException
{
    /**
     * Capture a bounded delay before the outbox fan-out should try the busy target again.
     *
     * @param   string  $message       Operator-safe backpressure explanation.
     * @param   int     $delaySeconds  Delay from one to 300 seconds.
     *
     * @throws  InvalidArgumentException  When the delay is outside the operational bound.
     *
     * @since   2.0.0
     */
    public function __construct(string $message, public readonly int $delaySeconds = 5)
    {
        if ($delaySeconds < 1 || $delaySeconds > 300) {
            throw new InvalidArgumentException('Integration backpressure delay must be between 1 and 300 seconds.');
        }
        parent::__construct($message);
    }
}
