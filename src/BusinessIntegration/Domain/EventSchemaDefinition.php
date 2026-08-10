<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

use InvalidArgumentException;

/**
 * Versioned, data-only event payload contract compiled into a trusted runtime generation.
 *
 * @since  2.0.0
 */
final readonly class EventSchemaDefinition implements IntegrationContract
{
    /**
     * Closed, bounded JSON schema for this event revision.
     *
     * @var    array<string, mixed>  Bounded JSON Schema subset.
     * @since  2.0.0
     */
    private array $payloadSchema;

    /**
     * Define one immutable event schema revision.
     *
     * @param   string                $eventType      Stable namespaced event identifier.
     * @param   int                   $schemaVersion  Contract revision.
     * @param   EventSensitivity      $sensitivity    Minimum sensitivity of messages under this contract.
     * @param   array<string, mixed>  $payloadSchema  Declarative JSON Schema subset for payload validation.
     * @param   int                   $maximumBytes   Per-contract payload ceiling, no higher than the envelope.
     *
     * @throws  InvalidArgumentException  When an identifier, version, schema or size is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $eventType,
        private int $schemaVersion,
        private EventSensitivity $sensitivity,
        array $payloadSchema,
        private int $maximumBytes = EventEnvelope::MAX_PAYLOAD_BYTES,
    ) {
        IntegrationContractValidator::identifier($eventType, 'Event type');
        if ($schemaVersion < 1 || $schemaVersion > 65_535) {
            throw new InvalidArgumentException('An event schema version is invalid.');
        }
        if ($maximumBytes < 2 || $maximumBytes > EventEnvelope::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('An event schema payload ceiling is invalid.');
        }
        IntegrationContractValidator::object($payloadSchema, 'Event payload schema');
        $this->payloadSchema = $payloadSchema;
    }

    /**
     * Return the versioned event type accepted by this contract.
     *
     * @return  string  Event type.
     *
     * @since   2.0.0
     */
    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * Return the event payload schema version.
     *
     * @return  int  Schema revision.
     *
     * @since   2.0.0
     */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * Return the sensitivity carried by this event schema definition.
     *
     * @return  EventSensitivity  Required sensitivity.
     *
     * @since   2.0.0
     */
    public function sensitivity(): EventSensitivity
    {
        return $this->sensitivity;
    }

    /**
     * Return the bounded JSON schema governing the payload.
     *
     * @return  array<string, mixed>  JSON Schema subset.
     *
     * @since   2.0.0
     */
    public function payloadSchema(): array
    {
        return $this->payloadSchema;
    }

    /**
     * Return the maximum bytes carried by this event schema definition.
     *
     * @return  int  Maximum payload bytes.
     *
     * @since   2.0.0
     */
    public function maximumBytes(): int
    {
        return $this->maximumBytes;
    }

    /**
     * Return the stable identifier for the event schema definition.
     *
     * @return  string  Type and revision registry key.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->eventType . '@' . $this->schemaVersion;
    }

    /**
     * Serialize the event schema definition for durable storage or inspection.
     *
     * @return  array<string, mixed>  Canonical publication representation.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'schema_version' => $this->schemaVersion,
            'sensitivity' => $this->sensitivity->value,
            'payload_schema' => $this->payloadSchema,
            'maximum_bytes' => $this->maximumBytes,
        ];
    }

    /**
     * Parse the closed manifest representation of an event schema.
     *
     * @param   array<string, mixed>  $data  Manifest contribution object.
     *
     * @return  self  Validated event schema definition.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        IntegrationContractValidator::keys($data, [
            'event_type', 'schema_version', 'sensitivity', 'payload_schema', 'maximum_bytes',
        ], 'Event schema definition');
        $sensitivity = EventSensitivity::tryFrom(IntegrationContractValidator::string($data, 'sensitivity'))
            ?? throw new InvalidArgumentException('The event schema sensitivity is invalid.');
        return new self(
            IntegrationContractValidator::string($data, 'event_type'),
            IntegrationContractValidator::integer($data, 'schema_version'),
            $sensitivity,
            IntegrationContractValidator::objectField($data, 'payload_schema'),
            IntegrationContractValidator::integer($data, 'maximum_bytes'),
        );
    }
}
