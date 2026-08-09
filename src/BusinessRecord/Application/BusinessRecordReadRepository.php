<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessRecord\Query\RecordQuerySpecification;

/**
 * Port for every read of the physical tables one business definition has installed.
 *
 * Records live in tables the schema installer generated, so a reader needs two things before it can
 * decode a row: the definition version the row was written under, and the tenant scope the row must
 * belong to. Both arrive as arguments here rather than being rediscovered inside the store, which is
 * what keeps scope isolation and version pinning decisions in `BusinessRecordService` where they can
 * be authorized. Implementations therefore apply the scope as a filter on every statement and decode
 * against the definition they are handed, refusing rather than guessing when a row does not fit it.
 * The service calls these under a shared `BusinessRecordMutationFence` so the schema cannot move
 * mid-read; writes go through `BusinessRecordWriteRepository` instead.
 *
 * @since  2.0.0
 */
interface BusinessRecordReadRepository
{
    /**
     * List the records that point at one target record through a given relationship.
     *
     * This is the inbound half of the relationship graph, which deletion needs before it can clear or
     * refuse references to a record that is going away. Callers that must detect an overflow ask for
     * one row more than their own limit and compare.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Source definition whose table is scanned,
     *          paired with its installation.
     * @param   RecordScope                 $scope            Site and organization the sources must
     *          belong to.
     * @param   RelationshipDefinition      $relationship     Relationship on the source definition that
     *          may hold the reference.
     * @param   string                      $targetRecordKey  Internal UUID key of the referenced record.
     * @param   int                         $limit            Most rows to return; implementations reject
     *          an unbounded or oversized request.
     *
     * @return  list<BusinessRecord>  Referencing records ordered by their stored key; empty when none
     *          references the target.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          bound is out of range, or the requested scope disagrees with the installed columns.
     *
     * @since   2.0.0
     */
    public function referencing(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RelationshipDefinition $relationship,
        string $targetRecordKey,
        int $limit,
    ): array;

    /**
     * Resolve a caller-facing record id to the stored row's identity, without decoding its values.
     *
     * The version this returns is what lets a caller re-resolve the definition a row was written under
     * before it reads that row, so a record written under an older shape is decoded with that shape
     * instead of the installed one.
     *
     * @param   ResolvedBusinessDefinition  $resolved        Definition whose identity column is matched.
     * @param   RecordScope                 $scope           Site and organization the row must belong to.
     * @param   string                      $recordId        Caller-facing identity, already normalized.
     * @param   bool                        $includeDeleted  True to also match a soft-deleted row.
     *
     * @return  StoredRecordIdentity|null  Internal key, pinned definition version and optimistic-lock
     *          version, or null when no row matches in this scope.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          requested scope disagrees with the installed columns, or a stored identity is malformed.
     *
     * @since   2.0.0
     */
    public function identity(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        string $recordId,
        bool $includeDeleted = false,
    ): ?StoredRecordIdentity;

    /**
     * Load one record and decode it against the definition version it was written under.
     *
     * Pass the pinned definition that `identity()` reported, not the installed one: a row whose stored
     * version disagrees with `$resolved` is refused rather than decoded with the wrong shape. Archived
     * and soft-deleted rows stay invisible unless explicitly admitted, which is how a normal read and
     * a history or restore read differ.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Pinned definition to decode the row with.
     * @param   RecordScope                 $scope            Site and organization the row must belong to.
     * @param   string                      $recordId         Caller-facing identity, already normalized.
     * @param   bool                        $includeArchived  True to also load an archived row.
     * @param   bool                        $includeDeleted   True to also load a soft-deleted row.
     *
     * @return  BusinessRecord|null  The decoded record, or null when no row matches in this scope.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          stored row was written under a different definition version, the scope disagrees with
     *          the installed columns, or a stored value does not match its physical column.
     *
     * @since   2.0.0
     */
    public function get(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        string $recordId,
        bool $includeArchived = false,
        bool $includeDeleted = false,
    ): ?BusinessRecord;

    /**
     * Project a loaded record into the disclosure-safe view a caller is allowed to see.
     *
     * Fields the definition does not expose to readers are dropped, restricted and secret values are
     * redacted, and stored entity references are exchanged for the target's caller-facing identity so
     * a view never leaks an internal key.
     *
     * @param   ResolvedBusinessDefinition  $resolved    Definition the record was decoded with.
     * @param   RecordScope                 $scope       Scope the referenced targets are resolved in.
     * @param   BusinessRecord              $record      Record to project.
     * @param   list<string>                $projection  Field handles to keep, or empty for every
     *          readable field.
     *
     * @return  BusinessRecordView  The projected record, without relationship includes.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When a
     *          stored entity reference is malformed or has no target in this scope.
     *
     * @since   2.0.0
     */
    public function view(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecord $record,
        array $projection = [],
    ): BusinessRecordView;

    /**
     * Run a compiled query and return one bounded page of projected records.
     *
     * Each row is decoded with the definition version it was written under, so a page may span several
     * pinned shapes. The specification's includes are resolved for the page as a whole rather than per
     * row, and its aggregates are computed over the whole match rather than the page.
     *
     * @param   ResolvedBusinessDefinition  $resolved       Definition whose table is queried, paired
     *          with its installation.
     * @param   RecordScope                 $scope          Site and organization the page is confined to.
     * @param   RecordQuerySpecification    $specification  Filter, search, sort, page bound, cursor and
     *          projection to compile.
     *
     * @return  RecordBrowseResult  Views for this page, the cursor to continue from when more rows
     *          matched, and any requested aggregates.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\InvalidBusinessRecordQuery  When the
     *          specification cannot be compiled — a cursor raised against a different query, or an
     *          aggregate over a field that cannot be reported.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          query names something the installed schema does not carry, a row's pinned definition is
     *          unavailable, or a stored value does not match its physical column.
     *
     * @since   2.0.0
     */
    public function browse(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        RecordQuerySpecification $specification,
    ): RecordBrowseResult;

    /**
     * Resolve a line's caller-facing id inside one owner record's owned-line collection.
     *
     * Owned lines have no identity of their own outside their owner, so the lookup is scoped by the
     * owner's key rather than by a record scope, and a line belonging to another owner is simply not
     * found.
     *
     * @param   ResolvedBusinessDefinition  $owner           Owner definition whose installation carries
     *          the line table.
     * @param   BusinessRecord              $ownerRecord     Owner record the line must belong to.
     * @param   RelationshipDefinition      $relationship    Owned-line relationship naming that table.
     * @param   EntityTypeDefinition        $lineDefinition  Definition describing the line's shape.
     * @param   string                      $lineId          Caller-facing identity of the line.
     *
     * @return  StoredRecordIdentity|null  Internal key and optimistic-lock version of the line, with the
     *          version of the line definition passed in; null when this owner holds no such line.
     *
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When the
     *          owned-line table or its identity column is absent from the installed schema, or a stored
     *          value is malformed.
     *
     * @since   2.0.0
     */
    public function ownedLineIdentity(
        ResolvedBusinessDefinition $owner,
        BusinessRecord $ownerRecord,
        RelationshipDefinition $relationship,
        EntityTypeDefinition $lineDefinition,
        string $lineId,
    ): ?StoredRecordIdentity;
}
