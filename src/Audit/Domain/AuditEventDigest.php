<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Domain;

use InvalidArgumentException;
use Kumwe\App\Application\Automation\CanonicalJson;

/**
 * Canonical per-event fingerprint the tamper-evidence layer stores beside every audit row.
 *
 * The digest is taken over a canonical JSON document of the event's evidentiary fields — identifier,
 * occurrence instant, actor, action, subject, outcome and metadata — prefixed with a versioned context
 * string so a future canonicalization change cannot silently collide with this one. `CanonicalJson`
 * supplies the byte-stable encoding the rest of the platform already digests with: string keys sorted,
 * zero fractions preserved, unicode and slashes unescaped. The occurrence instant is digested exactly
 * as the database stores it (`Y-m-d H:i:s`), and metadata is digested from its decoded array form, so
 * the recorder at write time and the verifier reading rows back through a driver that re-serializes
 * JSON both arrive at the same bytes. The digest is deliberately unkeyed: it makes silent mutation
 * evident, while authenticity of the whole trail is anchored by the chained `audit_anchors` ledger.
 *
 * @since  2.0.0
 */
final class AuditEventDigest
{
    /**
     * Versioned context string prefixed to the canonical document before hashing.
     *
     * Changing the canonical shape requires a new version marker, which turns an algorithm change into
     * an explicit divergence instead of a silent one.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CHAIN_CONTEXT = 'kumwe-audit-event-v1';

    /**
     * The date format audit occurrence instants are stored and digested in.
     *
     * This mirrors the platform datetime storage format, so the digest computed before the insert and
     * the digest recomputed from a fetched row cover identical bytes.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string INSTANT_FORMAT = 'Y-m-d H:i:s';

    /**
     * Compute the canonical digest for one audit event's stored fields.
     *
     * @param   string                $id           Canonical UUID of the event row.
     * @param   string                $occurredAt   Occurrence instant formatted as `Y-m-d H:i:s`.
     * @param   ?string               $actorId      Opaque accountable actor id, or null for a system action.
     * @param   string                $action       Machine token naming what was done.
     * @param   string                $subjectType  Machine token naming the kind of thing acted on.
     * @param   ?string               $subjectId    Opaque id of the thing acted on, or null when none exists.
     * @param   string                $outcome      Machine token for how the action ended.
     * @param   array<string, mixed>  $metadata     Decoded metadata object captured with the event.
     *
     * @return  string  Lowercase hexadecimal SHA-256, 64 characters wide.
     *
     * @throws  InvalidArgumentException  When the metadata cannot be represented as canonical JSON.
     *
     * @since   2.0.0
     */
    public static function compute(
        string $id,
        string $occurredAt,
        ?string $actorId,
        string $action,
        string $subjectType,
        ?string $subjectId,
        string $outcome,
        array $metadata,
    ): string {
        return hash('sha256', self::CHAIN_CONTEXT . "\n" . CanonicalJson::encode([
            'id' => $id,
            'occurred_at' => $occurredAt,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'outcome' => $outcome,
            'metadata' => $metadata,
        ]));
    }
}
