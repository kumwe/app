<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessReporting\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinitionGuard;

/**
 * Immutable, sequence-ordered input consumed by a deterministic projection rebuild.
 *
 * @since  2.0.0
 */
final readonly class ProjectionEvent
{
    /** @var array<string, mixed> @since 2.0.0 */
    public array $payload;

    /**
     * Capture a versioned event and prove its payload is canonically reproducible.
     *
     * @param   int                  $sequence       Strictly increasing source sequence.
     * @param   string               $id             Canonical event UUID.
     * @param   string               $type           Namespaced event type.
     * @param   int                  $schemaVersion  Positive payload schema version.
     * @param   DateTimeImmutable    $occurredAt     Original event instant.
     * @param   array<string, mixed> $payload        Immutable canonical event object.
     *
     * @throws  InvalidArgumentException  When event identity, version, sequence or payload is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $sequence,
        public string $id,
        public string $type,
        public int $schemaVersion,
        public DateTimeImmutable $occurredAt,
        array $payload,
    ) {
        if ($sequence < 1 || $schemaVersion < 1
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1
        ) {
            throw new InvalidArgumentException('A projection event identity or version is invalid.');
        }
        ReportDefinitionGuard::identifier($type, 'projection event type');
        CanonicalDefinitionJson::encode($payload);
        $this->payload = $payload;
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
