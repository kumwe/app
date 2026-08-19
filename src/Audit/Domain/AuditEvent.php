<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Immutable record of one audited action: who did it, to what, when, and how it ended.
 *
 * The constructor is the only validation point on the audit path. It rejects a non-canonical UUID, an
 * actor or subject identifier that is not an opaque token, an action, subject type or outcome written
 * as prose instead of a machine token, and metadata that is not a JSON object, so a recorder can write
 * an instance straight out to storage without re-checking or escaping anything. The length budgets
 * mirror the `audit_events` column widths in the core schema, which is why a caller that invents a
 * longer token fails here rather than at the database. Metadata is stored verbatim, so it carries safe
 * context only — never credentials, tokens, or raw request bodies.
 *
 * @since  2.0.0
 */
final readonly class AuditEvent
{
    /**
     * Context captured with the action, proven JSON-encodable when the event was built.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $metadata;

    /**
     * Build a validated audit record.
     *
     * Validation happens here so that every later reader — recorder, exporter, administration screen —
     * can trust the fields without repeating the checks. Identifier fields are matched against
     * deliberately narrow patterns: an action, subject type or outcome is a lowercase machine token
     * such as `content.transition` or `success`, not a sentence an operator wrote.
     *
     * @param   string             $id           Canonical UUID identifying this record, unique per action.
     * @param   DateTimeImmutable  $occurredAt   Instant the action happened, not the instant it is written.
     * @param   ?string            $actorId      Opaque id of the accountable actor, or null for a system action.
     * @param   string             $action       Machine token naming what was done, at most 127 bytes.
     * @param   string             $subjectType  Machine token naming the kind of thing acted on, up to 63 bytes.
     * @param   ?string            $subjectId    Opaque id of the thing acted on, or null when there is none.
     * @param   string             $outcome      Machine token for how the action ended, at most 31 bytes.
     * @param   array<mixed>       $metadata     Values must be JSON-serializable. String keys, safe context only.
     *
     * @throws  InvalidArgumentException  When any field is malformed or the metadata is not a JSON object.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $id,
        private DateTimeImmutable $occurredAt,
        private ?string $actorId,
        private string $action,
        private string $subjectType,
        private ?string $subjectId,
        private string $outcome,
        array $metadata = [],
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('The audit event ID must be a canonical UUID.');
        }

        if ($actorId !== null) {
            self::assertOpaqueId($actorId, 'actor');
        }

        if ($subjectId !== null) {
            self::assertOpaqueId($subjectId, 'subject');
        }

        self::assertIdentifier($action, 'action', 127);
        self::assertIdentifier($subjectType, 'subject type', 63);
        self::assertIdentifier($outcome, 'outcome', 31);

        foreach (array_keys($metadata) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Audit metadata must be an object with string keys.');
            }
        }

        try {
            json_encode($metadata, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Audit metadata must be JSON-serializable.', 0, $exception);
        }

        /** @var array<string, mixed> $metadata */
        $this->metadata = $metadata;
    }

    /**
     * Returns the record's own identifier.
     *
     * @return  string  Canonical UUID chosen when the event was built; the primary key of the stored row.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Returns the moment the audited action took place.
     *
     * @return  DateTimeImmutable  Time taken from the use case's clock, not the time of the write.
     *
     * @since   2.0.0
     */
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Returns the actor held accountable for the action.
     *
     * @return  ?string  Opaque actor id, or null when the platform acted with no user behind it.
     *
     * @since   2.0.0
     */
    public function actorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Returns the token naming what was done.
     *
     * @return  string  Lowercase machine token such as `content.transition`, safe to filter the trail on.
     *
     * @since   2.0.0
     */
    public function action(): string
    {
        return $this->action;
    }

    /**
     * Returns the kind of thing the action was performed on.
     *
     * @return  string  Machine token such as `content`, which gives the subject id its namespace.
     *
     * @since   2.0.0
     */
    public function subjectType(): string
    {
        return $this->subjectType;
    }

    /**
     * Returns the identifier of the thing acted on.
     *
     * @return  ?string  Opaque subject id, or null for an action with no single subject.
     *
     * @since   2.0.0
     */
    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    /**
     * Returns how the action ended.
     *
     * @return  string  Machine token such as `success` or `allowed`, at most 31 bytes.
     *
     * @since   2.0.0
     */
    public function outcome(): string
    {
        return $this->outcome;
    }

    /**
     * Returns the context captured alongside the action.
     *
     * @return  array<string, mixed>  Caller-supplied detail keyed by string; empty when the caller gave none.
     *
     * @since   2.0.0
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * Renders the metadata as a JSON document, for a recorder whose column takes an encoded string.
     *
     * Object notation is forced, so empty metadata serializes to `{}` rather than `[]` and every stored
     * row keeps one shape. The constructor already proved this payload encodes, so the call cannot fail.
     *
     * @return  string  JSON object literal of the metadata.
     *
     * @since   2.0.0
     */
    public function metadataAsJson(): string
    {
        return json_encode($this->metadata, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR);
    }

    /**
     * Rejects an actor or subject identifier that is not an opaque, bounded token.
     *
     * The pattern is permissive enough for the identifier shapes actors and subjects arrive as — UUIDs,
     * prefixed keys, namespaced codes — while keeping whitespace, quotes, and anything that reads as
     * free text out of a column other systems join on. The first character must be a letter or digit;
     * the rest may also use `.`, `_`, `:`, and `-`.
     *
     * @param   string  $value  Candidate identifier supplied by the caller.
     * @param   string  $field  Field name used to build the failure message, such as `actor`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is empty, over 191 bytes, or breaks that shape.
     *
     * @since   2.0.0
     */
    private static function assertOpaqueId(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s ID is invalid.', $field));
        }
    }

    /**
     * Rejects an action, subject type or outcome that is not a lowercase machine token.
     *
     * @param   string  $value      Candidate token supplied by the caller.
     * @param   string  $field      Field name used to build the failure message, such as `outcome`.
     * @param   int     $maxLength  Byte budget for the token, mirroring the storage column width.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the token exceeds the budget or is not a lowercase token.
     *
     * @since   2.0.0
     */
    private static function assertIdentifier(string $value, string $field, int $maxLength): void
    {
        if (strlen($value) > $maxLength || preg_match('/^[a-z][a-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The audit %s is invalid.', $field));
        }
    }
}
