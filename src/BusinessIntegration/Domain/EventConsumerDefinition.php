<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

use InvalidArgumentException;

/**
 * Durable consumer declaration, including versions, ordering and retry semantics.
 *
 * @since  2.0.0
 */
final readonly class EventConsumerDefinition implements IntegrationContract
{
    /** @var list<int> Accepted event schema versions. @since 2.0.0 */
    private array $schemaVersions;

    /**
     * Declare one durable idempotent consumer.
     *
     * @param   string            $consumerId          Globally unique namespaced consumer identity.
     * @param   string            $eventType           Event contract consumed.
     * @param   list<int>         $schemaVersions      Explicitly accepted schema revisions.
     * @param   string            $handlerVersion      Immutable executable handler revision.
     * @param   string            $queue               Logical delivery queue.
     * @param   bool              $aggregateOrdered    Whether aggregate versions are processed in order.
     * @param   ConsumerIdempotency $idempotency       Durable duplicate-key contract.
     * @param   int               $maximumAttempts     Attempt budget before poison quarantine.
     * @param   EventSensitivity  $sensitivityCeiling  Most sensitive message accepted by this consumer.
     *
     * @throws  InvalidArgumentException  When a declaration value is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $consumerId,
        private string $eventType,
        array $schemaVersions,
        private string $handlerVersion,
        private string $queue = 'default',
        private bool $aggregateOrdered = true,
        private ConsumerIdempotency $idempotency = ConsumerIdempotency::EVENT_ID,
        private int $maximumAttempts = 10,
        private EventSensitivity $sensitivityCeiling = EventSensitivity::INTERNAL,
    ) {
        IntegrationContractValidator::identifier($consumerId, 'Event consumer');
        IntegrationContractValidator::identifier($eventType, 'Consumed event type');
        IntegrationContractValidator::token($handlerVersion, 'Consumer handler version', 64);
        IntegrationContractValidator::token($queue, 'Consumer queue', 64);
        foreach ($schemaVersions as $version) {
            if ($version < 1 || $version > 65_535) {
                throw new InvalidArgumentException('A consumer schema version is invalid.');
            }
        }
        $schemaVersions = array_values(array_unique($schemaVersions));
        sort($schemaVersions, SORT_NUMERIC);
        if ($schemaVersions === [] || $maximumAttempts < 1 || $maximumAttempts > 100) {
            throw new InvalidArgumentException('A consumer needs versions and a valid attempt budget.');
        }
        if ($idempotency === ConsumerIdempotency::AGGREGATE_VERSION && !$aggregateOrdered) {
            throw new InvalidArgumentException('Aggregate-version idempotency requires aggregate ordering.');
        }
        $this->schemaVersions = $schemaVersions;
    }

    /** @return string Consumer identity. @since 2.0.0 */
    public function identifier(): string
    {
        return $this->consumerId;
    }

    /** @return string Event type. @since 2.0.0 */
    public function eventType(): string
    {
        return $this->eventType;
    }

    /** @return list<int> Accepted schema versions. @since 2.0.0 */
    public function schemaVersions(): array
    {
        return $this->schemaVersions;
    }

    /** @return string Handler revision. @since 2.0.0 */
    public function handlerVersion(): string
    {
        return $this->handlerVersion;
    }

    /** @return string Delivery queue. @since 2.0.0 */
    public function queue(): string
    {
        return $this->queue;
    }

    /** @return bool Whether aggregate sequence is enforced. @since 2.0.0 */
    public function aggregateOrdered(): bool
    {
        return $this->aggregateOrdered;
    }

    /** @return ConsumerIdempotency Durable duplicate-key contract. @since 2.0.0 */
    public function idempotency(): ConsumerIdempotency
    {
        return $this->idempotency;
    }

    /** @return int Attempt budget. @since 2.0.0 */
    public function maximumAttempts(): int
    {
        return $this->maximumAttempts;
    }

    /** @return EventSensitivity Consumer disclosure ceiling. @since 2.0.0 */
    public function sensitivityCeiling(): EventSensitivity
    {
        return $this->sensitivityCeiling;
    }

    /** @return bool Whether this exact schema revision is accepted. @since 2.0.0 */
    public function acceptsVersion(int $version): bool
    {
        return in_array($version, $this->schemaVersions, true);
    }

    /** @return array<string, mixed> Canonical publication representation. @since 2.0.0 */
    public function toArray(): array
    {
        return [
            'consumer_id' => $this->consumerId,
            'event_type' => $this->eventType,
            'schema_versions' => $this->schemaVersions,
            'handler_version' => $this->handlerVersion,
            'queue' => $this->queue,
            'aggregate_ordered' => $this->aggregateOrdered,
            'idempotency' => $this->idempotency->value,
            'maximum_attempts' => $this->maximumAttempts,
            'sensitivity_ceiling' => $this->sensitivityCeiling->value,
        ];
    }

    /**
     * Parse the closed manifest representation of a durable consumer.
     *
     * @param   array<string, mixed>  $data  Manifest contribution object.
     *
     * @return  self  Validated consumer definition.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        IntegrationContractValidator::keys($data, [
            'consumer_id', 'event_type', 'schema_versions', 'handler_version', 'queue',
            'aggregate_ordered', 'idempotency', 'maximum_attempts', 'sensitivity_ceiling',
        ], 'Event consumer definition');
        $versions = IntegrationContractValidator::listField($data, 'schema_versions');
        foreach ($versions as $version) {
            if (!is_int($version)) {
                throw new InvalidArgumentException('Consumer schema versions must be integers.');
            }
        }
        /** @var list<int> $versions */
        $sensitivity = EventSensitivity::tryFrom(
            IntegrationContractValidator::string($data, 'sensitivity_ceiling'),
        ) ?? throw new InvalidArgumentException('The consumer sensitivity ceiling is invalid.');
        $idempotency = ConsumerIdempotency::tryFrom(
            IntegrationContractValidator::string($data, 'idempotency'),
        ) ?? throw new InvalidArgumentException('The consumer idempotency contract is invalid.');
        return new self(
            IntegrationContractValidator::string($data, 'consumer_id'),
            IntegrationContractValidator::string($data, 'event_type'),
            $versions,
            IntegrationContractValidator::string($data, 'handler_version'),
            IntegrationContractValidator::string($data, 'queue'),
            IntegrationContractValidator::boolean($data, 'aggregate_ordered'),
            $idempotency,
            IntegrationContractValidator::integer($data, 'maximum_attempts'),
            $sensitivity,
        );
    }
}
