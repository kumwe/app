<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;
use Ramsey\Uuid\Uuid;

/**
 * One integration event reserved under a worker-, token- and generation-fenced lease.
 *
 * @since  2.0.0
 */
final readonly class OutboxLease
{
    /**
     * Capture the durable event and proof of its active reservation.
     *
     * @param   IntegrationEvent  $event              Reserved event.
     * @param   int               $attempts           Claims including this attempt.
     * @param   int               $maximumAttempts    Total attempt budget.
     * @param   string            $workerId           Lease owner.
     * @param   string            $leaseToken         Unforgeable fencing token.
     * @param   string            $runtimeGeneration  Exact trusted generation selecting the dispatcher.
     *
     * @throws  InvalidArgumentException  When lease metadata is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public IntegrationEvent $event,
        public int $attempts,
        public int $maximumAttempts,
        public string $workerId,
        public string $leaseToken,
        public string $runtimeGeneration,
    ) {
        if (
            $attempts < 1
            || $maximumAttempts < $attempts
            || !Uuid::isValid($leaseToken)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $workerId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $runtimeGeneration) !== 1
        ) {
            throw new InvalidArgumentException('The outbox lease metadata is invalid.');
        }
    }
}
