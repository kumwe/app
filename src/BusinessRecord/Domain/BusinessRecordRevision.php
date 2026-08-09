<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Ramsey\Uuid\Uuid;

/**
 * One immutable entry in a business record's history, carrying the state the record was left in.
 *
 * `BusinessRecordService` appends a revision for every mutation of a definition that has revisions
 * enabled, and `DoctrineBusinessRecordRevisionRepository` re-derives `checksum()` when it reads a row
 * back and refuses one whose stored digest disagrees. That round trip is what forces the
 * canonicalisation done in the constructor: the snapshot is key-sorted and the changed-field list is
 * de-duplicated and sorted, so a revision hashes the same however the caller happened to order it,
 * and the record's own identity is kept as a digest so history stays queryable without storing the
 * business identity again in the clear.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordRevision
{
    /**
     * Field values as at this revision, keyed by field handle and sorted by handle.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $snapshot;

    /**
     * Handles the mutation touched, de-duplicated and sorted.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $changedFields;

    /**
     * Assemble a revision, validating every part of it and canonicalising what the checksum covers.
     *
     * @param   string                $revisionId              UUID of this history entry.
     * @param   string                $definitionId            UUID of the entity type the record belongs to.
     * @param   int                   $definitionVersion       Definition version the record was written
     *          against; at least 1.
     * @param   string                $siteIdentifier          Site the record lives in.
     * @param   string|null           $organizationIdentifier  Organization branch within that site, or
     *          null when the record is site-wide.
     * @param   string                $recordKey               UUID of the record — its internal key, not
     *          its business identity.
     * @param   string                $recordIdentityDigest    Digest of the record's business identity,
     *          which is how history is found without storing that identity in the clear.
     * @param   int                   $recordVersion           Optimistic version of the record this entry
     *          captures; at least 1.
     * @param   int                   $revisionNumber          Position of this entry in the record's
     *          history; at least 1.
     * @param   string                $operation               Lowercase name of the mutation, such as
     *          `create`, `update` or `relate.<relationship>`.
     * @param   array<string, mixed>  $snapshot                Field values as at this revision, keyed by
     *          handle; each value must be one the record layer can carry.
     * @param   list<string>          $changedFields           Handles the mutation touched; order and
     *          duplicates are irrelevant, since the list is normalised here.
     * @param   string                $actorId                 Identity credited with the mutation.
     * @param   DateTimeImmutable     $occurredAt              Instant the mutation was applied.
     *
     * @throws  InvalidArgumentException  When an identifier is not a canonical UUID, the identity
     *          digest is not a 64-character hex digest, a version, operation, site or organization is
     *          malformed, or the snapshot holds an invalid handle or an unsupported value.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $revisionId,
        public string $definitionId,
        public int $definitionVersion,
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public string $recordKey,
        public string $recordIdentityDigest,
        public int $recordVersion,
        public int $revisionNumber,
        public string $operation,
        array $snapshot,
        array $changedFields,
        public string $actorId,
        public DateTimeImmutable $occurredAt,
    ) {
        if (!Uuid::isValid($revisionId) || !Uuid::isValid($definitionId)) {
            throw new InvalidArgumentException('Business-record revision and definition IDs must be canonical UUIDs.');
        }
        if (!Uuid::isValid($recordKey)) {
            throw new InvalidArgumentException('A business-record revision record key is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $recordIdentityDigest) !== 1) {
            throw new InvalidArgumentException('A business-record revision identity digest is invalid.');
        }
        if (
            $definitionVersion < 1 || $recordVersion < 1 || $revisionNumber < 1
            || preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $operation) !== 1
        ) {
            throw new InvalidArgumentException('A business-record revision version or operation is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $siteIdentifier) !== 1) {
            throw new InvalidArgumentException('A business-record revision site is invalid.');
        }
        if (
            $organizationIdentifier !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $organizationIdentifier) !== 1
        ) {
            throw new InvalidArgumentException('A business-record revision organization is invalid.');
        }
        foreach ($snapshot as $handle => $value) {
            if (!is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business-record revision contains an invalid field handle.');
            }
            RecordValueGuard::assertValue($value);
        }
        foreach ($changedFields as $handle) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business-record revision changed-field handle is invalid.');
            }
        }
        $changedFields = array_values(array_unique($changedFields));
        sort($changedFields, SORT_STRING);
        ksort($snapshot, SORT_STRING);
        $this->snapshot = $snapshot;
        $this->changedFields = $changedFields;
    }

    /**
     * Return the field values the record held at this revision.
     *
     * @return  array<string, mixed>  Values keyed by field handle, sorted by handle; the same ordering
     *          the checksum was taken over.
     *
     * @since   2.0.0
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * Return the handles this mutation changed.
     *
     * @return  list<string>  Sorted, de-duplicated handles; empty when no field value moved, as an
     *          archive, restore or delete records.
     *
     * @since   2.0.0
     */
    public function changedFields(): array
    {
        return $this->changedFields;
    }

    /**
     * Derive the digest that proves this revision is the one that was written.
     *
     * The digest covers every part of the revision, including the snapshot and changed-field list in
     * their canonical order, so it is stable across processes and is what the repository compares a
     * stored row against before handing the revision on.
     *
     * @return  string  Lowercase 64-character SHA-256 digest of the revision's canonical JSON form.
     *
     * @throws  InvalidArgumentException  When the snapshot cannot be encoded as JSON and so cannot be
     *          checksummed.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        try {
            $json = json_encode(
                RecordValueGuard::canonical([
                    'revision_id' => $this->revisionId,
                    'definition_id' => $this->definitionId,
                    'definition_version' => $this->definitionVersion,
                    'site_identifier' => $this->siteIdentifier,
                    'organization_identifier' => $this->organizationIdentifier,
                    'record_key' => $this->recordKey,
                    'record_identity_digest' => $this->recordIdentityDigest,
                    'record_version' => $this->recordVersion,
                    'revision_number' => $this->revisionNumber,
                    'operation' => $this->operation,
                    'snapshot' => $this->snapshot,
                    'changed_fields' => $this->changedFields,
                    'actor_id' => $this->actorId,
                    'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
                ]),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'A business-record revision snapshot cannot be checksummed.',
                0,
                $exception,
            );
        }

        return hash('sha256', $json);
    }
}
