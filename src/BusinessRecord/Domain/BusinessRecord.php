<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * One instance of a business definition, as the domain holds it between a read and a write.
 *
 * This is the currency of the whole BusinessRecord slice: repositories decode rows into it, the service
 * layer validates and hands it to policy, and every write returns a fresh instance rather than mutating
 * one. Two things make that safe. Construction re-checks the identity, version, workflow, actor and
 * lifecycle invariants on every instance, including the ones a repository builds from storage, so a row
 * that drifted cannot enter the domain. And each lifecycle transition is a named method that produces a
 * successor with `version` already incremented, which is the value the repository presents as the
 * optimistic lock, so no caller has to remember to bump it.
 *
 * The record knows the definition version it was written under and carries it forward unchanged. A
 * record read under an older shape stays on that shape until something migrates it, rather than being
 * silently reinterpreted against the newest definition.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecord
{
    /**
     * Field values keyed by field handle, sorted by handle so two equal records serialize identically.
     *
     * Held privately rather than promoted because the constructor sorts and validates it first.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $values;

    /**
     * Assemble a record and prove every invariant it claims.
     *
     * Used both to create a record and to reconstitute a stored one, so the checks below run against
     * storage as well as against caller input.
     *
     * @param   string                $definitionId       UUID of the business definition this instance is
     *          an instance of.
     * @param   int                   $definitionVersion  Published definition version this instance was
     *          written under, which decoding must agree with.
     * @param   string                $recordKey          Internal storage key, a canonical UUID.
     * @param   string                $recordId           Caller-facing identity, unique within the scope.
     * @param   RecordScope           $scope              Installation, site or organization the record
     *          belongs to.
     * @param   int                   $version            Optimistic lock version; every successor carries
     *          this plus one.
     * @param   ?string               $workflowState      Current workflow state handle, or null when the
     *          definition declares no workflow.
     * @param   array<string, mixed>  $values             Field values keyed by handle; stored sorted by
     *          handle, capped at 256 entries.
     * @param   string                $createdBy          Actor identifier credited with creation.
     * @param   DateTimeImmutable     $createdAt          Instant the record was created.
     * @param   string                $updatedBy          Actor identifier credited with this version.
     * @param   DateTimeImmutable     $updatedAt          Instant this version was written.
     * @param   ?string               $archivedBy         Actor who archived the record; null unless
     *          archived, and paired with $archivedAt.
     * @param   ?DateTimeImmutable    $archivedAt         Instant the record was archived, or null when it
     *          is not archived.
     * @param   ?string               $deletedBy          Actor who soft-deleted the record; null unless
     *          deleted, and paired with $deletedAt.
     * @param   ?DateTimeImmutable    $deletedAt          Instant the record was soft-deleted, or null when
     *          it is live.
     *
     * @throws  InvalidArgumentException  When the definition id or storage key is not a UUID, the record
     *          id is empty, over 191 bytes or holds control characters, either version is below one, the
     *          workflow state is not a bounded lowercase handle, an actor identifier is malformed, an
     *          archive or delete actor and timestamp are not both set or both absent, there are more than
     *          256 fields, a field handle is invalid, or a value is of a type records cannot store.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public string $recordId,
        public RecordScope $scope,
        public int $version,
        public ?string $workflowState,
        array $values,
        public string $createdBy,
        public DateTimeImmutable $createdAt,
        public string $updatedBy,
        public DateTimeImmutable $updatedAt,
        public ?string $archivedBy = null,
        public ?DateTimeImmutable $archivedAt = null,
        public ?string $deletedBy = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {
        if (!Uuid::isValid($definitionId) || !Uuid::isValid($recordKey)) {
            throw new InvalidArgumentException('Business-record definition and internal record keys must be UUIDs.');
        }
        if ($recordId === '' || strlen($recordId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $recordId) === 1) {
            throw new InvalidArgumentException('A business-record identity is invalid.');
        }
        if ($definitionVersion < 1 || $version < 1) {
            throw new InvalidArgumentException('Business record definition and optimistic versions must be positive.');
        }
        if ($workflowState !== null && preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $workflowState) !== 1) {
            throw new InvalidArgumentException('A business-record workflow state is invalid.');
        }
        if (($archivedBy === null) !== ($archivedAt === null) || ($deletedBy === null) !== ($deletedAt === null)) {
            throw new InvalidArgumentException('Business-record lifecycle actor and timestamp pairs are inconsistent.');
        }
        self::assertActor($createdBy);
        self::assertActor($updatedBy);
        if ($archivedBy !== null) {
            self::assertActor($archivedBy);
        }
        if ($deletedBy !== null) {
            self::assertActor($deletedBy);
        }
        if (count($values) > 256) {
            throw new InvalidArgumentException('A business record contains too many fields.');
        }
        foreach ($values as $handle => $value) {
            if (!is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business record contains an invalid field handle.');
            }
            RecordValueGuard::assertValue($value);
        }
        ksort($values, SORT_STRING);
        $this->values = $values;
    }

    /**
     * Expose the whole value set.
     *
     * @return  array<string, mixed>  Every stored field keyed by handle, in handle order; a field the
     *          record does not carry is simply absent rather than present as null.
     *
     * @since   2.0.0
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Read one field by handle.
     *
     * A stored null is a legitimate value and is returned as such, so absence is signalled by the
     * exception rather than by a null return.
     *
     * @param   string  $handle  Field handle to read.
     *
     * @return  mixed  The stored value, which may be a scalar, an array, or one of the domain value
     *          objects records may hold.
     *
     * @throws  InvalidArgumentException  When the record carries no field under that handle.
     *
     * @since   2.0.0
     */
    public function value(string $handle): mixed
    {
        if (!array_key_exists($handle, $this->values)) {
            throw new InvalidArgumentException('The business record field is unavailable.');
        }

        return $this->values[$handle];
    }

    /**
     * Produce the next version of this record carrying a new value set.
     *
     * The values given replace the whole set rather than merging into it, so a caller that means to change
     * one field passes the merged result. Workflow state and any archive marking are carried over
     * untouched; an archived record can still be edited this way.
     *
     * @param   array<string, mixed>  $values  Complete replacement value set, keyed by handle.
     * @param   string                $actor   Actor identifier to credit with the new version.
     * @param   DateTimeImmutable     $now     Instant to stamp on the new version.
     *
     * @return  self  A new record one version higher, with the same identity and scope.
     *
     * @throws  InvalidArgumentException  When the record is soft-deleted and must be restored first, when
     *          the replacement values fail the constructor's checks, or when $actor is not a valid actor
     *          identifier.
     *
     * @since   2.0.0
     */
    public function updated(array $values, string $actor, DateTimeImmutable $now): self
    {
        return $this->copy($values, $actor, $now, $this->workflowState, $this->archivedBy, $this->archivedAt);
    }

    /**
     * Produce the next version of this record sitting in a different workflow state.
     *
     * Values are carried over unchanged. Whether the move from the current state to $state is legal is a
     * policy question the workflow layer answers before calling; this only records the outcome.
     *
     * @param   string             $state  Handle of the workflow state to move into.
     * @param   string             $actor  Actor identifier to credit with the transition.
     * @param   DateTimeImmutable  $now    Instant to stamp on the new version.
     *
     * @return  self  A new record one version higher, in the requested state.
     *
     * @throws  InvalidArgumentException  When the record is soft-deleted and must be restored first, when
     *          $state is not a bounded lowercase handle, or when $actor is not a valid actor identifier.
     *
     * @since   2.0.0
     */
    public function transitioned(string $state, string $actor, DateTimeImmutable $now): self
    {
        return $this->copy($this->values, $actor, $now, $state, $this->archivedBy, $this->archivedAt);
    }

    /**
     * Produce the next version of this record marked as archived.
     *
     * Archiving is a marking, not a removal: values, workflow state and identity survive, and `restored()`
     * reverses it. Archiving twice is refused so the original archive actor and instant are never
     * overwritten.
     *
     * @param   string             $actor  Actor identifier credited with archiving and with the new version.
     * @param   DateTimeImmutable  $now    Instant recorded as both the archive time and the update time.
     *
     * @return  self  A new record one version higher, carrying the archive marking.
     *
     * @throws  InvalidArgumentException  When the record is already archived, when it is soft-deleted and
     *          must be restored first, or when $actor is not a valid actor identifier.
     *
     * @since   2.0.0
     */
    public function archived(string $actor, DateTimeImmutable $now): self
    {
        if ($this->archivedAt !== null) {
            throw new InvalidArgumentException('The business record is already archived.');
        }

        return $this->copy($this->values, $actor, $now, $this->workflowState, $actor, $now);
    }

    /**
     * Produce the next version of this record with both the archive and the delete marking cleared.
     *
     * This is the one transition a soft-deleted record accepts, which is why it builds its successor
     * directly instead of going through the shared copy path. It does not distinguish undeleting from
     * unarchiving: a record that was archived and then deleted comes back live, not archived.
     *
     * @param   string             $actor  Actor identifier to credit with the new version.
     * @param   DateTimeImmutable  $now    Instant to stamp on the new version.
     *
     * @return  self  A new record one version higher, live, with the same values and workflow state.
     *
     * @throws  InvalidArgumentException  When the record is neither archived nor soft-deleted, so there is
     *          nothing to restore, or when $actor is not a valid actor identifier.
     *
     * @since   2.0.0
     */
    public function restored(string $actor, DateTimeImmutable $now): self
    {
        if ($this->archivedAt === null && $this->deletedAt === null) {
            throw new InvalidArgumentException('The business record is not archived or deleted.');
        }

        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->scope,
            $this->version + 1,
            $this->workflowState,
            $this->values,
            $this->createdBy,
            $this->createdAt,
            $actor,
            $now,
        );
    }

    /**
     * Produce the next version of this record marked as soft-deleted.
     *
     * The row keeps its values and its history so `restored()` can bring it back, but every other
     * transition refuses a deleted record from here on. Any existing archive marking is preserved, and
     * deleting twice is refused so the original delete actor and instant are never overwritten.
     *
     * @param   string             $actor  Actor identifier credited with the deletion and the new version.
     * @param   DateTimeImmutable  $now    Instant recorded as both the delete time and the update time.
     *
     * @return  self  A new record one version higher, carrying the delete marking.
     *
     * @throws  InvalidArgumentException  When the record is already soft-deleted, or when $actor is not a
     *          valid actor identifier.
     *
     * @since   2.0.0
     */
    public function softDeleted(string $actor, DateTimeImmutable $now): self
    {
        if ($this->deletedAt !== null) {
            throw new InvalidArgumentException('The business record is already deleted.');
        }

        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->scope,
            $this->version + 1,
            $this->workflowState,
            $this->values,
            $this->createdBy,
            $this->createdAt,
            $actor,
            $now,
            $this->archivedBy,
            $this->archivedAt,
            $actor,
            $now,
        );
    }

    /**
     * Build the successor shared by every transition a live record allows.
     *
     * This is the guard that refuses to move a soft-deleted record, which `updated()`, `transitioned()`
     * and `archived()` inherit by routing through here. The two transitions that change the delete marking
     * build their own successors instead: `softDeleted()` because this path always carries the existing
     * marking across, and `restored()` because it is the one transition a deleted record must accept.
     *
     * @param   array<string, mixed>  $values         Value set the successor carries.
     * @param   string                $actor          Actor identifier to credit with the new version.
     * @param   DateTimeImmutable     $now            Instant to stamp on the new version.
     * @param   ?string               $workflowState  Workflow state the successor sits in, or null for none.
     * @param   ?string               $archivedBy     Archive actor to carry across or set, or null for none.
     * @param   ?DateTimeImmutable    $archivedAt     Archive instant to carry across or set, paired with
     *          $archivedBy.
     *
     * @return  self  A new record one version higher, keeping this record's identity, scope and delete
     *          marking.
     *
     * @throws  InvalidArgumentException  When the record is soft-deleted, or the resulting combination
     *          fails the constructor's checks.
     *
     * @since   2.0.0
     */
    private function copy(
        array $values,
        string $actor,
        DateTimeImmutable $now,
        ?string $workflowState,
        ?string $archivedBy,
        ?DateTimeImmutable $archivedAt,
    ): self {
        if ($this->deletedAt !== null) {
            throw new InvalidArgumentException('A deleted business record must be restored before mutation.');
        }

        return new self(
            $this->definitionId,
            $this->definitionVersion,
            $this->recordKey,
            $this->recordId,
            $this->scope,
            $this->version + 1,
            $workflowState,
            $values,
            $this->createdBy,
            $this->createdAt,
            $actor,
            $now,
            $archivedBy,
            $archivedAt,
            $this->deletedBy,
            $this->deletedAt,
        );
    }

    /**
     * Reject an actor identifier that could not have come from the authentication layer.
     *
     * Actor identifiers are stamped into audit columns, so they are held to a bounded printable form
     * rather than being trusted from whatever the caller passed in.
     *
     * @param   string  $actor  Candidate actor identifier for a create, update, archive or delete stamp.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is empty, over 191 characters, or contains
     *          anything outside letters, digits, dot, underscore, colon and hyphen.
     *
     * @since   2.0.0
     */
    private static function assertActor(string $actor): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $actor) !== 1) {
            throw new InvalidArgumentException('A business-record actor identifier is invalid.');
        }
    }
}
