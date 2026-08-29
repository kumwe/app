<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContract;
use Kumwe\Extension\Spi\BusinessIntegration\Domain\IntegrationContractValidator;
use InvalidArgumentException;

/**
 * Bounded logical queue declaration for extension jobs and event deliveries.
 *
 * @since  2.0.0
 */
final readonly class QueueContributionDefinition implements IntegrationContract
{
    /**
     * Describe one queue's portable processing limits.
     *
     * @param   string  $queueId          Namespaced logical queue identifier.
     * @param   int     $leaseSeconds     Claim lease, between 5 seconds and one hour.
     * @param   int     $maximumAttempts  Default delivery attempt budget.
     * @param   int     $maximumInFlight  Durable cross-process live-claim ceiling.
     * @param   int     $retentionDays    Completed/dead evidence retention.
     *
     * @throws  InvalidArgumentException  When a queue limit is outside its portable bound.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $queueId,
        private int $leaseSeconds = 60,
        private int $maximumAttempts = 10,
        private int $maximumInFlight = 16,
        private int $retentionDays = 30,
    ) {
        IntegrationContractValidator::identifier($queueId, 'Queue');
        IntegrationContractValidator::token($queueId, 'Queue', 64);
        if (
            $leaseSeconds < 5 || $leaseSeconds > 3600
            || $maximumAttempts < 1 || $maximumAttempts > 100
            || $maximumInFlight < 1 || $maximumInFlight > 1024
            || $retentionDays < 1 || $retentionDays > 3650
        ) {
            throw new InvalidArgumentException('A contributed queue limit is invalid.');
        }
    }

    /**
     * Return the stable identifier for the queue contribution definition.
     *
     * @return  string  Namespaced queue identity.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->queueId;
    }

    /**
     * Return the duration of each worker lease in seconds.
     *
     * @return  int  Claim lease in seconds.
     *
     * @since   2.0.0
     */
    public function leaseSeconds(): int
    {
        return $this->leaseSeconds;
    }

    /**
     * Return the maximum number of delivery attempts.
     *
     * @return  int  Default attempt budget.
     *
     * @since   2.0.0
     */
    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    /**
     * Return the queue concurrency ceiling.
     *
     * @return  int  Durable cross-process live-claim ceiling.
     *
     * @since   2.0.0
     */
    public function maximumInFlight(): int
    {
        return $this->maximumInFlight;
    }

    /**
     * Return the number of days completed queue records are retained.
     *
     * @return  int  Evidence retention in days.
     *
     * @since   2.0.0
     */
    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Serialize the queue contribution definition for durable storage or inspection.
     *
     * @return  array<string, mixed>  Canonical publication representation.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'queue_id' => $this->queueId,
            'lease_seconds' => $this->leaseSeconds,
            'maximum_attempts' => $this->maximumAttempts,
            'maximum_in_flight' => $this->maximumInFlight,
            'retention_days' => $this->retentionDays,
        ];
    }

    /**
     * Reconstitute the queue contribution definition from validated array data.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     *
     * @return  self  Validated queue declaration.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        IntegrationContractValidator::keys($data, [
            'queue_id',
            'lease_seconds',
            'maximum_attempts',
            'maximum_in_flight',
            'retention_days',
        ], 'Queue contribution definition');

        return new self(
            IntegrationContractValidator::string($data, 'queue_id'),
            IntegrationContractValidator::integer($data, 'lease_seconds'),
            IntegrationContractValidator::integer($data, 'maximum_attempts'),
            IntegrationContractValidator::integer($data, 'maximum_in_flight'),
            IntegrationContractValidator::integer($data, 'retention_days'),
        );
    }
}
