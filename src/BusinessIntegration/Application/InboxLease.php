<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\IntegrationEvent;
use Ramsey\Uuid\Uuid;

/**
 * Consumer delivery reserved under an expiring token and exact runtime generation.
 *
 * @since  2.0.0
 */
final readonly class InboxLease
{
    /**
     * Capture the delivery and proof of its current ownership.
     *
     * @param   EventConsumerDefinition  $consumer           Consumer contract.
     * @param   IntegrationEvent         $event              Durable event.
     * @param   int                      $attempts           Attempts including this claim.
     * @param   string                   $workerId           Lease owner.
     * @param   string                   $leaseToken         Fencing token.
     * @param   string                   $runtimeGeneration  Pinned trusted generation.
     *
     * @throws  InvalidArgumentException  When lease metadata is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public EventConsumerDefinition $consumer,
        public IntegrationEvent $event,
        public int $attempts,
        public string $workerId,
        public string $leaseToken,
        public string $runtimeGeneration,
    ) {
        if (
            $attempts < 1
            || !Uuid::isValid($leaseToken)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D', $workerId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $runtimeGeneration) !== 1
        ) {
            throw new InvalidArgumentException('The inbox lease metadata is invalid.');
        }
    }
}
