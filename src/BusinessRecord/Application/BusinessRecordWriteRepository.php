<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;

/**
 * Port for every write that moves a business-record row or the storage of its relationships.
 *
 * Each method executes against the physical tables one `ResolvedBusinessDefinition` describes, so the
 * caller owes it a definition already pinned to a live installation. Every method except `insert()`
 * also takes the version the caller read and applies the write as a compare-and-set, which is how a
 * request that raced another one is rejected instead of overwriting it. Implementations are expected
 * to run inside the caller's transaction, under the exclusive fence `BusinessRecordService` holds for
 * the definition, because the schema those column names come from must not move mid-statement; the
 * matching read side is `BusinessRecordReadRepository`.
 *
 * @since  2.0.0
 */
interface BusinessRecordWriteRepository
{
    /**
     * Store a newly created record as the first version of its row.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition pinned to the installation whose record
     *          table receives the row.
     * @param   BusinessRecord              $record    Record to store, with its values already validated,
     *          defaulted and encoded-ready.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict  When the row
     *          collides with a unique constraint, such as an identity that already exists in this scope.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict  When a
     *          stored entity reference names a row that does not exist.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          installation does not describe the record table or a column the record needs.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the database refuses the write for a transient reason such as a deadlock.
     *
     * @since   2.0.0
     */
    public function insert(ResolvedBusinessDefinition $resolved, BusinessRecord $record): void;

    /**
     * Write a mutated record over its row, but only while the stored version still matches.
     *
     * This is the single write behind update, workflow transition, archive, restore and soft delete:
     * the caller hands over an already-transitioned record and its values and lifecycle columns are
     * applied as one compare-and-set, so the new version is published or nothing is.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Definition pinned to the installation holding
     *          the row.
     * @param   BusinessRecord              $record           Record at its new version, as the domain
     *          produced it.
     * @param   int                         $expectedVersion  Version the caller read; the write is refused
     *          when the stored row has moved past it.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound  When no row with
     *          this record's key exists any more.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict  When the
     *          stored version differs from $expectedVersion.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict  When the new
     *          values collide with a unique constraint.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict  When a
     *          stored entity reference names a row that does not exist.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          installation does not describe the record table or a column the record needs.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the database refuses the write for a transient reason such as a deadlock.
     *
     * @since   2.0.0
     */
    public function update(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void;

    /**
     * Erase the record's row outright rather than marking it deleted.
     *
     * This is the delete path for a definition that keeps no soft-deleted rows, so nothing of the
     * record survives in its table. Inbound references are the caller's problem: they must already
     * have been cleared, or the database will refuse the delete.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Definition pinned to the installation holding
     *          the row.
     * @param   BusinessRecord              $record           Record whose row is to be erased.
     * @param   int                         $expectedVersion  Version the caller read; the delete is refused
     *          when the stored row has moved past it.
     *
     * @return  void
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound  When no row with
     *          this record's key exists any more.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict  When the
     *          stored version differs from $expectedVersion.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict  When
     *          another row still references this one.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          installation does not describe the record table or a column the delete needs.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the database refuses the delete for a transient reason such as a deadlock.
     *
     * @since   2.0.0
     */
    public function hardDelete(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void;

    /**
     * Link a target to a source record through one relationship and re-version the source.
     *
     * Where the link lands depends on the relationship: a singular one sets a column on the source
     * row, an owned-line collection inserts the line itself from $ownedLineValues, an ordinary
     * collection inserts a junction row, and a relationship whose canonical storage belongs to the
     * inverse side writes the target's row or junction instead — which is what $targetResolved and
     * $target are for. $position places the link within an ordered or owned-line collection; null
     * appends after the current highest, and a singular relationship refuses a position outright.
     *
     * @param   ResolvedBusinessDefinition   $resolved             Definition pinned to the installation
     *          holding the source row.
     * @param   BusinessRecord               $source               Record the relationship is declared on.
     * @param   RelationshipDefinition       $relationship         Relationship being populated.
     * @param   string                       $targetRecordKey      Internal storage key of the target row, or
     *          of the owned line about to be created.
     * @param   ?int                         $position             Slot in an ordered or owned-line collection,
     *          or null to append after the current highest.
     * @param   string                       $actorId              Actor credited with the source's new
     *          version.
     * @param   DateTimeImmutable            $at                   Instant stamped on every row this write
     *          touches.
     * @param   int                          $expectedVersion      Version of the source the caller read.
     * @param   ?ResolvedBusinessDefinition  $targetResolved       Pinned definition of the target, required
     *          when the inverse side owns the storage.
     * @param   ?BusinessRecord              $target               The target record itself, required on that
     *          same path.
     * @param   ?EntityTypeDefinition        $ownedLineDefinition  Pinned definition of the line type,
     *          required for an owned-line collection.
     * @param   array<string, mixed>         $ownedLineValues      Values of the line to create; accepted
     *          only for an owned-line collection.
     *
     * @return  RelationshipWriteResult  The re-versioned source, carrying the target and the inverse
     *          handle as well when the write also moved the target's own row.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRelationshipRejected  When the
     *          arguments contradict the relationship, such as a position or line values on a singular
     *          relationship, or an owned line with no pinned line definition.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound  When the source row
     *          no longer exists.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict  When the
     *          stored source version differs from $expectedVersion.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict  When the link
     *          already exists, or a created line collides with a unique constraint.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict  When either
     *          end of the link names a row that does not exist.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          installation describes no storage for this relationship or its canonical inverse.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the database refuses the write for a transient reason such as a deadlock.
     *
     * @since   2.0.0
     */
    public function relate(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        ?int $position,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
        ?BusinessRecord $target = null,
        ?EntityTypeDefinition $ownedLineDefinition = null,
        array $ownedLineValues = [],
    ): RelationshipWriteResult;

    /**
     * Remove one link between a source record and a target and re-version the source.
     *
     * A required relationship — or a required inverse that owns the storage — cannot be emptied this
     * way, so a mandatory link is never left dangling. As with `relate()`, the pinned target and its
     * record are needed whenever the canonical storage lives on the inverse side. An owned line is
     * stored in the collection's own table, so detaching one deletes the line row with the link.
     *
     * @param   ResolvedBusinessDefinition   $resolved         Definition pinned to the installation holding
     *          the source row.
     * @param   BusinessRecord               $source           Record the relationship is declared on.
     * @param   RelationshipDefinition       $relationship     Relationship being emptied.
     * @param   string                       $targetRecordKey  Internal storage key of the linked row to
     *          detach.
     * @param   string                       $actorId          Actor credited with the source's new version.
     * @param   DateTimeImmutable            $at               Instant stamped on every row this write
     *          touches.
     * @param   int                          $expectedVersion  Version of the source the caller read.
     * @param   ?ResolvedBusinessDefinition  $targetResolved   Pinned definition of the target, required when
     *          the inverse side owns the storage.
     * @param   ?BusinessRecord              $target           The target record itself, required on that
     *          same path.
     *
     * @return  RelationshipWriteResult  The re-versioned source; it names the inverse relationship
     *          whenever the link was stored on the target's side, and carries the target record too when
     *          that write moved the target's own row.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRelationshipRejected  When the
     *          relationship or its canonical inverse is required, or no such link is stored.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound  When the source row
     *          no longer exists.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict  When the
     *          stored source version differs from $expectedVersion.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          installation describes no storage for this relationship or its canonical inverse.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the database refuses the write for a transient reason such as a deadlock.
     *
     * @since   2.0.0
     */
    public function unrelate(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
        ?BusinessRecord $target = null,
    ): RelationshipWriteResult;

    /**
     * Rewrite the positions of an ordered collection and re-version the source.
     *
     * The supplied keys must be a permutation of exactly the links currently stored for this
     * relationship, so a stale or partial list is rejected rather than quietly reordering a subset.
     * Only a collection whose storage the source side owns can be reordered; when the canonical
     * junction belongs to the inverse definition, ordering has to be requested from that side.
     *
     * @param   ResolvedBusinessDefinition   $resolved           Definition pinned to the installation
     *          holding the source row.
     * @param   BusinessRecord               $source             Record whose collection is reordered.
     * @param   RelationshipDefinition       $relationship       Ordered collection to renumber.
     * @param   list<string>                 $orderedRecordKeys  Internal storage keys of every current link,
     *          in the order to store them.
     * @param   string                       $actorId            Actor credited with the source's new
     *          version.
     * @param   DateTimeImmutable            $at                 Instant stamped on every row this write
     *          touches.
     * @param   int                          $expectedVersion    Version of the source the caller read.
     * @param   ?ResolvedBusinessDefinition  $targetResolved     Pinned definition of the linked type; the
     *          shipped implementation never needs it, because renumbering only touches source-side storage.
     *
     * @return  BusinessRecord  The source at its new version, with the collection renumbered from zero.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRelationshipRejected  When the
     *          relationship is not an ordered collection, its storage belongs to the inverse side, the
     *          keys are not a permutation of the stored links, or a link changed during the rewrite.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordNotFound  When the source row
     *          no longer exists.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordVersionConflict  When the
     *          stored source version differs from $expectedVersion.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          installation does not describe the collection's storage, or a stored link identity is not
     *          a readable key.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable  When
     *          the database refuses the write for a transient reason such as a deadlock.
     *
     * @since   2.0.0
     */
    public function reorder(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $source,
        RelationshipDefinition $relationship,
        array $orderedRecordKeys,
        string $actorId,
        DateTimeImmutable $at,
        int $expectedVersion,
        ?ResolvedBusinessDefinition $targetResolved = null,
    ): BusinessRecord;
}
