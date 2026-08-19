<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessIntegration\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\CanonicalJson;
use Ramsey\Uuid\Uuid;

/**
 * Immutable, versioned metadata and bounded payload shared by every business event.
 *
 * Human and system attribution are mutually exclusive. Site and aggregate identity are always present,
 * while organization is nullable for installation- or site-wide facts. The payload is a JSON object whose
 * canonical encoding, depth and member count are bounded before it can reach persistence or a handler.
 *
 * @since  2.0.0
 */
abstract readonly class EventEnvelope
{
    /**
     * Maximum canonical payload size accepted by the default event contract.
     *
     * @var    int  Largest canonical payload accepted by the default contract.
     * @since  2.0.0
     */
    public const int MAX_PAYLOAD_BYTES = 65_536;

    /**
     * Maximum nesting depth accepted by the default event contract.
     *
     * @var    int  Deepest payload nesting accepted.
     * @since  2.0.0
     */
    public const int MAX_PAYLOAD_DEPTH = 16;

    /**
     * Maximum number of members accepted across one event payload.
     *
     * @var    int  Most scalar and array members accepted across one payload.
     * @since  2.0.0
     */
    public const int MAX_PAYLOAD_NODES = 2_048;

    /**
     * Validated business payload protected by the envelope bounds.
     *
     * @var    array<string, mixed>  Validated business payload.
     * @since  2.0.0
     */
    private array $payload;

    /**
     * Build a complete event envelope.
     *
     * @param   string                $eventType         Stable namespaced event contract identifier.
     * @param   int                   $schemaVersion     Payload contract revision, starting at one.
     * @param   string                $eventId           Canonical UUID identifying this fact across retries.
     * @param   DateTimeImmutable     $occurredAt        Instant the authoritative mutation recorded the fact.
     * @param   ?string               $actorId           Human actor identifier, null for a system action.
     * @param   ?string               $systemIdentity    System identity, null for a human action.
     * @param   string                $siteIdentifier    Site in which the fact occurred.
     * @param   ?string               $organizationId    Owning organization, when the fact is organization scoped.
     * @param   string                $aggregateType     Stable aggregate or entity type.
     * @param   string                $aggregateId       Aggregate or entity identity.
     * @param   int                   $aggregateVersion  Authoritative version after the mutation.
     * @param   string                $correlationId     Identifier shared by the end-to-end operation.
     * @param   string                $causationId       Event, command or request that directly caused this fact.
     * @param   EventSensitivity      $sensitivity       Disclosure classification for the complete event.
     * @param   array<string, mixed>  $payload           Bounded JSON object defined by the event schema.
     *
     * @throws  InvalidArgumentException  When metadata or payload violates an event invariant.
     *
     * @since   2.0.0
     */
    final public function __construct(
        private string $eventType,
        private int $schemaVersion,
        private string $eventId,
        private DateTimeImmutable $occurredAt,
        private ?string $actorId,
        private ?string $systemIdentity,
        private string $siteIdentifier,
        private ?string $organizationId,
        private string $aggregateType,
        private string $aggregateId,
        private int $aggregateVersion,
        private string $correlationId,
        private string $causationId,
        private EventSensitivity $sensitivity,
        array $payload,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._:-][a-z0-9]+){1,15}$/D', $eventType) !== 1) {
            throw new InvalidArgumentException('An event type must be a lowercase namespaced identifier.');
        }
        if ($schemaVersion < 1 || $schemaVersion > 65_535 || $aggregateVersion < 1) {
            throw new InvalidArgumentException('Event schema and aggregate versions must be positive.');
        }
        if (
            !Uuid::isValid($eventId) || strtolower($eventId) !== $eventId
            || Uuid::fromString($eventId)->toString() !== $eventId
        ) {
            throw new InvalidArgumentException('An event ID must be a canonical lowercase UUID.');
        }
        if (($actorId === null) === ($systemIdentity === null)) {
            throw new InvalidArgumentException('An event requires exactly one human or system identity.');
        }
        self::assertIdentity($actorId ?? $systemIdentity ?? '', 'actor');
        self::assertIdentity($siteIdentifier, 'site');
        if ($organizationId !== null) {
            self::assertIdentity($organizationId, 'organization');
        }
        self::assertIdentity($aggregateType, 'aggregate type');
        self::assertIdentity($aggregateId, 'aggregate');
        self::assertIdentity($correlationId, 'correlation');
        self::assertIdentity($causationId, 'causation');
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException('An event payload must be a JSON object.');
        }
        $nodes = 0;
        self::measurePayload($payload, 1, $nodes);
        if (strlen(CanonicalJson::encode($payload)) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('An event payload exceeds the maximum encoded size.');
        }
        $this->payload = $payload;
    }

    /**
     * Return the versioned event type accepted by this contract.
     *
     * @return  string  Stable event contract identifier.
     *
     * @since   2.0.0
     */
    final public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * Return the event payload schema version.
     *
     * @return  int  Payload contract revision.
     *
     * @since   2.0.0
     */
    final public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * Return the event ID carried by this event envelope.
     *
     * @return  string  Canonical event UUID.
     *
     * @since   2.0.0
     */
    final public function eventId(): string
    {
        return $this->eventId;
    }

    /**
     * Return the occurred at carried by this event envelope.
     *
     * @return  DateTimeImmutable  Occurrence instant.
     *
     * @since   2.0.0
     */
    final public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Return the actor ID carried by this event envelope.
     *
     * @return  ?string  Human actor identity.
     *
     * @since   2.0.0
     */
    final public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Return the system identity carried by this event envelope.
     *
     * @return  ?string  System actor identity.
     *
     * @since   2.0.0
     */
    final public function systemIdentity(): ?string
    {
        return $this->systemIdentity;
    }

    /**
     * Return the site identifier carried by this event envelope.
     *
     * @return  string  Owning site identity.
     *
     * @since   2.0.0
     */
    final public function siteIdentifier(): string
    {
        return $this->siteIdentifier;
    }

    /**
     * Return the organization ID carried by this event envelope.
     *
     * @return  ?string  Owning organization identity.
     *
     * @since   2.0.0
     */
    final public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    /**
     * Return the aggregate type carried by this event envelope.
     *
     * @return  string  Aggregate type.
     *
     * @since   2.0.0
     */
    final public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    /**
     * Return the aggregate ID carried by this event envelope.
     *
     * @return  string  Aggregate identity.
     *
     * @since   2.0.0
     */
    final public function aggregateId(): string
    {
        return $this->aggregateId;
    }

    /**
     * Return the aggregate version carried by this event envelope.
     *
     * @return  int  Aggregate version after the mutation.
     *
     * @since   2.0.0
     */
    final public function aggregateVersion(): int
    {
        return $this->aggregateVersion;
    }

    /**
     * Derive the process correlation identifier from the event.
     *
     * @return  string  End-to-end correlation identity.
     *
     * @since   2.0.0
     */
    final public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Return the causation ID carried by this event envelope.
     *
     * @return  string  Direct cause identity.
     *
     * @since   2.0.0
     */
    final public function causationId(): string
    {
        return $this->causationId;
    }

    /**
     * Return the sensitivity carried by this event envelope.
     *
     * @return  EventSensitivity  Disclosure classification.
     *
     * @since   2.0.0
     */
    final public function sensitivity(): EventSensitivity
    {
        return $this->sensitivity;
    }

    /**
     * Return the validated payload.
     *
     * @return  array<string, mixed>  Validated payload object.
     *
     * @since   2.0.0
     */
    final public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Export the envelope into its durable transport representation.
     *
     * @return  array<string, mixed>  Complete metadata and payload.
     *
     * @since   2.0.0
     */
    final public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'schema_version' => $this->schemaVersion,
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'actor_id' => $this->actorId,
            'system_identity' => $this->systemIdentity,
            'site_identifier' => $this->siteIdentifier,
            'organization_id' => $this->organizationId,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'aggregate_version' => $this->aggregateVersion,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'sensitivity' => $this->sensitivity->value,
            'payload' => $this->payload,
        ];
    }

    /**
     * Rehydrate the concrete event class from a durable representation.
     *
     * @param   array<string, mixed>  $data  Complete envelope representation.
     *
     * @return  static  Validated event of the class the factory was called on.
     *
     * @throws  InvalidArgumentException  When a required value is absent or malformed.
     *
     * @since   2.0.0
     */
    final public static function fromArray(array $data): static
    {
        IntegrationContractValidator::keys($data, [
            'event_type',
            'schema_version',
            'event_id',
            'occurred_at',
            'actor_id',
            'system_identity',
            'site_identifier',
            'organization_id',
            'aggregate_type',
            'aggregate_id',
            'aggregate_version',
            'correlation_id',
            'causation_id',
            'sensitivity',
            'payload',
        ], 'Stored event envelope');
        $payload = $data['payload'] ?? null;
        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new InvalidArgumentException('A stored event payload must be an object.');
        }
        /** @var array<string, mixed> $payload */

        try {
            $occurredAtValue = self::string($data, 'occurred_at');
            $occurredAt = new DateTimeImmutable($occurredAtValue);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('A stored event occurrence time is invalid.', 0, $exception);
        }
        if ($occurredAt->format('Y-m-d\TH:i:s.uP') !== $occurredAtValue) {
            throw new InvalidArgumentException('A stored event occurrence time is not canonical.');
        }

        return new static(
            self::string($data, 'event_type'),
            self::integer($data, 'schema_version'),
            self::string($data, 'event_id'),
            $occurredAt,
            self::nullableString($data, 'actor_id'),
            self::nullableString($data, 'system_identity'),
            self::string($data, 'site_identifier'),
            self::nullableString($data, 'organization_id'),
            self::string($data, 'aggregate_type'),
            self::string($data, 'aggregate_id'),
            self::integer($data, 'aggregate_version'),
            self::string($data, 'correlation_id'),
            self::string($data, 'causation_id'),
            EventSensitivity::tryFrom(self::string($data, 'sensitivity'))
                ?? throw new InvalidArgumentException('A stored event sensitivity is invalid.'),
            $payload,
        );
    }

    /**
     * Read a required string from the supplied data.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  string  Required string stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Stored event field "%s" is invalid.', $key));
        }
        return $value;
    }

    /**
     * Read an optional string from the supplied data.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  ?string  String stored under the key, or null when the member is absent.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new InvalidArgumentException(sprintf('Stored event field "%s" is invalid.', $key));
        }
        return $value;
    }

    /**
     * Read and validate an integer value.
     *
     * @param   array<string, mixed>  $data  Validated contribution data from which the named member is read.
     * @param   string                $key   Array or row key whose value is being read.
     *
     * @return  int  Integer stored under the requested key.
     *
     * @since   2.0.0
     */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new InvalidArgumentException(sprintf('Stored event field "%s" is invalid.', $key));
        }
        return (int) $value;
    }

    /**
     * Validate identity before continuing.
     *
     * @param   string  $value  Candidate value being validated or normalized.
     * @param   string  $label  Human-readable field name used in validation errors.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertIdentity(string $value, string $label): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/D', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The event %s identity is invalid.', $label));
        }
    }

    /**
     * Count payload depth and members while enforcing the envelope bounds.
     *
     * @param   array<mixed>  $value  Candidate value being validated or normalized.
     * @param   int           $depth  Current recursive depth used to enforce payload bounds.
     * @param   int           $nodes  Running node count used to enforce payload bounds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function measurePayload(array $value, int $depth, int &$nodes): void
    {
        if ($depth > self::MAX_PAYLOAD_DEPTH) {
            throw new InvalidArgumentException('An event payload exceeds the maximum nesting depth.');
        }
        foreach ($value as $key => $item) {
            $nodes++;
            if ($nodes > self::MAX_PAYLOAD_NODES) {
                throw new InvalidArgumentException('An event payload exceeds the maximum member count.');
            }
            if (is_string($key) && (strlen($key) > 191 || preg_match('/[\x00-\x1F\x7F]/D', $key) === 1)) {
                throw new InvalidArgumentException('An event payload key is invalid.');
            }
            if (is_array($item)) {
                self::measurePayload($item, $depth + 1, $nodes);
            }
        }
    }
}
