<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\Extension\Spi\BusinessReporting\Application\ProjectionEvent;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportDefinitionGuard;

/**
 * Immutable journal event supplied directly to an SDK projection builder.
 *
 * @since  2.0.0
 */
final readonly class JournalProjectionEvent implements ProjectionEvent
{
    /**
     * Validated canonical event payload.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $payload;

    /**
     * Capture one versioned event after the durable journal envelope has been verified.
     *
     * @param   int                   $sequence       Strictly increasing source sequence.
     * @param   string                $id             Canonical event UUID.
     * @param   string                $type           Namespaced event type.
     * @param   int                   $schemaVersion  Positive payload schema version.
     * @param   DateTimeImmutable     $occurredAt     Original event instant.
     * @param   array<string, mixed>  $payload        Canonical event object exposed to the builder.
     *
     * @throws  InvalidArgumentException  When event identity, version, sequence or payload is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private int $sequence,
        private string $id,
        private string $type,
        private int $schemaVersion,
        private DateTimeImmutable $occurredAt,
        array $payload,
    ) {
        if (
            $sequence < 1 || $schemaVersion < 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1
        ) {
            throw new InvalidArgumentException('A projection event identity or version is invalid.');
        }
        ReportDefinitionGuard::identifier($type, 'projection event type');
        CanonicalDefinitionJson::encode($payload);
        $this->payload = $payload;
    }

    /**
     * Return the immutable journal position.
     *
     * @return  int  Positive global source sequence.
     *
     * @since   2.0.0
     */
    public function sequence(): int
    {
        return $this->sequence;
    }

    /**
     * Return the canonical event identity.
     *
     * @return  string  Lowercase UUID from the verified journal envelope.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return the namespaced event type.
     *
     * @return  string  Type constrained by the active projection declaration.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Return the immutable payload schema version.
     *
     * @return  int  Positive version constrained by the active projection declaration.
     *
     * @since   2.0.0
     */
    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /**
     * Return the original event instant.
     *
     * @return  DateTimeImmutable  Instant carried by the verified journal envelope.
     *
     * @since   2.0.0
     */
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Return the canonical event payload.
     *
     * @return  array<string, mixed>  Payload validated before builder execution.
     *
     * @since   2.0.0
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Fingerprint the immutable event input independently of storage encoding.
     *
     * @return  string  Lowercase SHA-256 over sequence, identity, type, version, instant and payload.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum([
            'sequence' => $this->sequence,
            'id' => strtolower($this->id),
            'type' => $this->type,
            'schema_version' => $this->schemaVersion,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
            'payload' => $this->payload,
        ]);
    }
}
