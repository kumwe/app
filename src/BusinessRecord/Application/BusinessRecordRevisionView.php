<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\Sensitivity;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\CMS\BusinessSecurity\Application\FieldDisclosurePlan;

/**
 * Disclosure-safe view over one integrity-verified revision, as a history page hands it to a caller.
 *
 * A `BusinessRecordRevision` carries the record's whole snapshot exactly as it was stored, including
 * values the reader may not see and entity references held as internal record keys. This view is where
 * that is narrowed for release: `fromRevision()` replaces a withheld value with `['redacted' => true]`
 * instead of dropping its key, so a caller can tell withheld from never-set, and it redacts against the
 * definition version the revision was written under rather than the installed one — which is what lets a
 * history page that spans a definition upgrade judge each entry by the rules in force when it was made.
 * `RecordHistoryResult` carries a page of these; nothing outside the history path should assemble one
 * from a raw revision.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordRevisionView
{
    /**
     * Field values as at this revision, keyed by field handle and already redacted for release.
     *
     * A withheld value is present as `['redacted' => true]`. Keys the definition does not describe pass
     * through untouched, which is how the runtime relation evidence a relationship write records reaches
     * a reader.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $snapshot;

    /**
     * Field handles the mutation behind this revision touched, sorted and de-duplicated by the domain.
     *
     * Handles are never redacted, so a restricted field appears here by name even though its value in
     * `$snapshot` is withheld. Empty when no field value moved, as for an archive, restore or delete.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $changedFields;

    /**
     * Capture one already-redacted projection of a revision.
     *
     * The constructor withholds nothing of its own beyond re-indexing the changed-field list; it trusts
     * that redaction has already been applied, which is why `fromRevision()` is the sanctioned way in.
     *
     * @param  string                $revisionId         UUID of this history entry.
     * @param  string                $definitionId       UUID of the entity type the record belongs to.
     * @param  int                   $definitionVersion  Definition version the revision was written
     *         against, and the version its redaction was judged by.
     * @param  string                $recordKey          Internal storage UUID of the record, not its
     *         caller-facing identity.
     * @param  int                   $recordVersion      Optimistic version of the record this entry
     *         captures.
     * @param  int                   $revisionNumber     Position of this entry in the record's history.
     * @param  string                $operation          Lowercase name of the mutation, such as `create`,
     *         `update` or `relate.<relationship>`.
     * @param  array<string, mixed>  $snapshot           Field values as at this revision, keyed by handle
     *         and already redacted.
     * @param  list<string>          $changedFields      Handles the mutation touched; re-indexed here so
     *         the stored list is contiguous from zero.
     * @param  string                $actorId            Identity credited with the mutation.
     * @param  DateTimeImmutable     $occurredAt         Instant the mutation was applied.
     * @param  string                $integrityChecksum  Digest the revision derives from itself, which the
     *         repository already checked the stored row against.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $revisionId,
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public int $recordVersion,
        public int $revisionNumber,
        public string $operation,
        array $snapshot,
        array $changedFields,
        public string $actorId,
        public DateTimeImmutable $occurredAt,
        public string $integrityChecksum,
    ) {
        $this->snapshot = $snapshot;
        $this->changedFields = array_values($changedFields);
    }

    /**
     * Project a stored revision into the view a caller may be shown.
     *
     * Pass the definition version the revision was written under, not the installed one: it is the
     * per-field sensitivity of that version that decides what is withheld. Every entity-reference value
     * is withheld unconditionally, because a snapshot holds the target's internal record key and never
     * its public identity. Nothing else is dropped — there is no read-visibility filter and no
     * projection here, unlike the record read path, so the rest of the snapshot is carried through as
     * stored.
     *
     * @param   BusinessRecordRevision  $revision    Integrity-verified revision to project.
     * @param   EntityTypeDefinition    $definition  Definition at the version the revision was written
     *          under, supplying each field's type and sensitivity.
     * @param   ?FieldDisclosurePlan    $disclosure  Explicit audit-field allow-list, or null for the
     *          legacy definition-only projection.
     *
     * @return  self  View over the redacted snapshot, carrying the revision's identity and the checksum
     *          re-derived from it.
     *
     * @throws  \InvalidArgumentException  When the revision cannot be canonicalised and encoded, so the
     *          checksum this view reports cannot be derived.
     * @throws  \JsonException  When a disclosure checksum cannot encode its bounded projection.
     *
     * @since   2.0.0
     */
    public static function fromRevision(
        BusinessRecordRevision $revision,
        EntityTypeDefinition $definition,
        ?FieldDisclosurePlan $disclosure = null,
    ): self {
        $sensitive = [];
        foreach ($definition->fields() as $field) {
            if (
                $field->type === 'core.entity_reference'
                || in_array($field->sensitivity, [Sensitivity::Restricted, Sensitivity::Secret], true)
            ) {
                $sensitive[$field->handle] = true;
            }
        }
        $snapshot = $revision->snapshot();
        $changedFields = $revision->changedFields();
        if ($disclosure !== null) {
            $allowed = array_fill_keys($disclosure->fields(FieldAccessUsage::Audit), true);
            $snapshot = array_intersect_key($snapshot, $allowed);
            $changedFields = array_values(array_filter(
                $changedFields,
                static fn (string $handle): bool => isset($allowed[$handle]),
            ));
        }
        foreach ($snapshot as $handle => $_value) {
            if (isset($sensitive[$handle])) {
                $snapshot[$handle] = ['redacted' => true];
            }
        }

        $integrityChecksum = $disclosure === null ? $revision->checksum() : hash('sha256', json_encode(
            RecordValueGuard::canonical([
                'revision_id' => $revision->revisionId,
                'snapshot' => $snapshot,
                'changed_fields' => $changedFields,
            ]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return new self(
            $revision->revisionId,
            $revision->definitionId,
            $revision->definitionVersion,
            $revision->recordKey,
            $revision->recordVersion,
            $revision->revisionNumber,
            $revision->operation,
            $snapshot,
            $changedFields,
            $revision->actorId,
            $revision->occurredAt,
            $integrityChecksum,
        );
    }
}
