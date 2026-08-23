<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipDefinition;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessRecord\Application\BusinessRecordWriteRepository;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordTemporarilyUnavailable;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordUniqueConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\OwnedLineWrite;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\RelationshipWriteResult;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use LogicException;

/**
 * Doctrine adapter that writes business-record rows into the physical tables an installation describes.
 *
 * No table or column name is compiled into this class: every statement is built from the
 * `PhysicalTableBlueprint` the caller's `ResolvedBusinessDefinition` carries, so a record is always
 * addressed through the shape the installer actually applied. Three rules then hold across every entry
 * point. The caller must already have a transaction open, because the compare-and-set writes below are
 * only meaningful under the fence `BusinessRecordService` holds for the definition. Every write except
 * `insert()` matches on the row's version column, and a statement that touched no row is followed by a
 * re-read that says whether the record is gone or has simply moved on. And every DBAL failure is
 * translated into the BusinessRecord exception vocabulary, so a unique index, a foreign key, or a
 * deadlock reaches the caller as a domain exception rather than as a driver one.
 *
 * One entry point writes a collection rather than a row. `writeOwnedLines()` stores a whole document's
 * lines in statements bounded by the parameter budget instead of one call per line, and leaves the owner
 * row alone because the caller has already written the header at its new version in the same transaction.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessRecordWriteRepository implements BusinessRecordWriteRepository
{
    /**
     * Largest number of owned lines one statement carries, whichever ceiling binds first.
     *
     * A hundred rows keeps a batched insert's bound parameter list small enough to stay comfortable on
     * every supported engine even for a wide line entity, and keeps the statement text well inside the
     * packet each engine will accept. It is a ceiling rather than a target: the effective batch is
     * whichever of this and the parameter budget is smaller.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int OWNED_LINE_BATCH = 100;

    /**
     * Largest number of links one set-based reorder statement renumbers.
     *
     * A reorder pair binds three parameters — the link key twice and its new position — so a hundred
     * links keeps a statement comfortably inside the same parameter and packet ceilings the owned-line
     * batch reasons from, while a thousand-line document renumbers in ten statements instead of a
     * thousand (P4-B).
     *
     * @var    int
     * @since  2.0.0
     */
    private const int REORDER_BATCH = 100;

    /**
     * Largest number of bound parameters one owned-line statement is allowed to carry.
     *
     * MariaDB, MySQL and PostgreSQL all refuse a statement past 65,535 placeholders, so the budget sits an
     * order of magnitude below that: a batch is sized from the line entity's own column count, which means
     * a wide line simply batches fewer rows instead of failing on the widest engine.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_PARAMETERS = 4096;

    /**
     * Wire the adapter to the connection it writes through and the codec that shapes field values.
     *
     * @param  Connection        $database  DBAL connection whose already-open transaction every write joins.
     * @param  RecordValueCodec  $codec     Encoder that spreads record field values across physical columns.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private RecordValueCodec $codec)
    {
    }

    /**
     * Insert a new record as one row carrying its control columns and its encoded field columns.
     *
     * Only the control columns the installed table actually declares are written, so a definition that
     * keeps no scope, workflow, or soft-delete columns simply inserts without them.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition pinned to the installation whose record
     *          table receives the row.
     * @param   BusinessRecord              $record    Record to store; its version is written as it stands
     *          and its field values are encoded into columns by the codec.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessRecordSchemaUnavailable  When the installation describes no record table, or the
     *          table lacks a column the row needs.
     * @throws  BusinessRecordUniqueConflict  When the row collides with a unique index, such as an identity
     *          already in use in this scope.
     * @throws  BusinessRecordReferenceConflict  When a stored reference names a row that does not exist.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the insert for any other
     *          reason, such as a deadlock.
     *
     * @since   2.0.0
     */
    public function insert(ResolvedBusinessDefinition $resolved, BusinessRecord $record): void
    {
        $this->assertTransaction();
        $table = $this->recordTable($resolved);
        $values = [
            $this->physical($table, 'record_id') => $record->recordKey,
            $this->physical($table, 'definition_version') => $record->definitionVersion,
            $this->physical($table, 'version') => $record->version,
            $this->physical($table, 'created_by') => $record->createdBy,
            $this->physical($table, 'created_at') => $record->createdAt,
            $this->physical($table, 'updated_by') => $record->updatedBy,
            $this->physical($table, 'updated_at') => $record->updatedAt,
            ...$this->optionalControlValues($table, $record),
            ...$this->codec->encodeColumns($resolved->definition, $table, $record->values()),
        ];
        try {
            $this->database->insert($table->physicalName, $values, $this->types($table, $values));
        } catch (DbalException $exception) {
            $this->map($exception);
        }
    }

    /**
     * Rewrite the record's row, but only while the stored version still equals $expectedVersion.
     *
     * The version, the audit stamps, whichever lifecycle columns the table declares, and the encoded field
     * values all move in one UPDATE, so the whole new version lands or none of it does. Nothing here
     * distinguishes an edit from an archive, a restore, a workflow move, or a soft delete: the record
     * arrives already transitioned, and the columns follow from what it carries.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Definition pinned to the installation holding
     *          the row.
     * @param   BusinessRecord              $record           Record at its new version, as the domain
     *          produced it.
     * @param   int                         $expectedVersion  Version the caller read; the UPDATE matches on
     *          it and writes nothing once the row has moved past it.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessRecordNotFound  When no row with this record's storage key exists any more.
     * @throws  BusinessRecordVersionConflict  When the stored version differs from $expectedVersion.
     * @throws  BusinessRecordSchemaUnavailable  When the installation describes no record table, or a
     *          column the write names is not in its blueprint.
     * @throws  BusinessRecordUniqueConflict  When the new values collide with a unique index.
     * @throws  BusinessRecordReferenceConflict  When a stored reference names a row that does not exist.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the update for any other
     *          reason, such as a deadlock.
     *
     * @since   2.0.0
     */
    public function update(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void {
        $this->assertTransaction();
        $table = $this->recordTable($resolved);
        $values = [
            $this->physical($table, 'version') => $record->version,
            $this->physical($table, 'updated_by') => $record->updatedBy,
            $this->physical($table, 'updated_at') => $record->updatedAt,
            ...$this->optionalControlValues($table, $record),
            ...$this->codec->encodeColumns($resolved->definition, $table, $record->values()),
        ];
        $this->casUpdate($table, $record->recordKey, $expectedVersion, $values);
    }

    /**
     * Delete the record's row outright, but only while the stored version still equals $expectedVersion.
     *
     * A delete that matched no row is diagnosed rather than ignored: the version is re-read, so a row that
     * is already gone is reported differently from one another writer has moved on.
     *
     * @param   ResolvedBusinessDefinition  $resolved         Definition pinned to the installation holding
     *          the row.
     * @param   BusinessRecord              $record           Record whose row is erased; only its storage
     *          key is read.
     * @param   int                         $expectedVersion  Version the caller read; the DELETE matches on
     *          it and removes nothing once the row has moved past it.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessRecordNotFound  When no row with this record's storage key exists any more.
     * @throws  BusinessRecordVersionConflict  When the stored version differs from $expectedVersion.
     * @throws  BusinessRecordSchemaUnavailable  When the installation describes no record table, or its key
     *          or version column is not in the blueprint.
     * @throws  BusinessRecordReferenceConflict  When another row still references this one.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the delete for any other
     *          reason, such as a deadlock.
     *
     * @since   2.0.0
     */
    public function hardDelete(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $record,
        int $expectedVersion,
    ): void {
        $this->assertTransaction();
        $table = $this->recordTable($resolved);
        try {
            $affected = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE %s = ? AND %s = ?',
                $this->quote($table->physicalName),
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($this->physical($table, 'version')),
            ), [$record->recordKey, $expectedVersion], [
                $this->type($table, 'record_id'),
                Types::INTEGER,
            ]);
        } catch (DbalException $exception) {
            $this->map($exception);
        }
        if ($affected !== 1) {
            $this->conflict($table, $record->recordKey, $expectedVersion);
        }
    }

    /**
     * Store one link from the source record to a target and re-version the source row.
     *
     * Where the link lands is decided by the installed blueprint rather than by the caller. A singular
     * relationship whose target column sits on the source table is written as part of the source's own
     * compare-and-set. An owned-line collection inserts the line itself, built from $ownedLineValues under
     * $ownedLineDefinition. An ordinary collection inserts a junction row. When the source installation
     * describes no storage at all, the canonical storage belongs to the inverse side, so the target's own
     * column or junction is written instead — which is what $targetResolved and $target are for — and the
     * source is then re-versioned by a statement of its own. A junction or line row is stamped at version
     * one and inherits the source's scope; ordered collections and owned lines take $position, or one past
     * the highest stored position when it is null, while an unordered collection stores no position.
     *
     * @param   ResolvedBusinessDefinition   $resolved             Definition pinned to the installation
     *          holding the source row.
     * @param   BusinessRecord               $source               Record the relationship is declared on.
     * @param   RelationshipDefinition       $relationship         Relationship being populated.
     * @param   string                       $targetRecordKey      Storage key of the target row, or of the
     *          owned line this call creates.
     * @param   ?int                         $position             Slot in an ordered or owned-line
     *          collection, or null to append after the highest stored position.
     * @param   string                       $actorId              Actor credited with every row this write
     *          re-versions.
     * @param   DateTimeImmutable            $at                   Instant stamped on every row this write
     *          touches.
     * @param   int                          $expectedVersion      Version of the source the caller read.
     * @param   ?ResolvedBusinessDefinition  $targetResolved       Pinned definition of the target, required
     *          once the inverse side owns the storage.
     * @param   ?BusinessRecord              $target               Target record itself, required on that
     *          same path and matched against $targetRecordKey.
     * @param   ?EntityTypeDefinition        $ownedLineDefinition  Pinned definition of the line type, whose
     *          fields the line values are encoded against.
     * @param   array<string, mixed>         $ownedLineValues      Values of the line to create, keyed by
     *          field handle; accepted only for an owned-line collection.
     *
     * @return  RelationshipWriteResult  The re-versioned source, carrying the target and the inverse handle
     *          as well when the link was written onto the target's own row.
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessRelationshipRejected  When a position or line values are given for a relationship
     *          that stores neither, or an owned line arrives without its pinned line definition.
     * @throws  BusinessRecordNotFound  When the source row, or a target row this write re-versions, no
     *          longer exists.
     * @throws  BusinessRecordVersionConflict  When a row this write re-versions no longer carries the
     *          version the caller read for it.
     * @throws  BusinessRecordSchemaUnavailable  When neither side of the installation describes storage for
     *          the relationship, a column the write names is not in its blueprint, or the target cannot be
     *          matched to its pinned definition.
     * @throws  BusinessRecordUniqueConflict  When the link already exists, or a created line collides with
     *          a unique index.
     * @throws  BusinessRecordReferenceConflict  When either end of the link names a row that does not
     *          exist.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the write for any other
     *          reason, such as a deadlock.
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
    ): RelationshipWriteResult {
        $this->assertTransaction();
        $recordTable = $this->recordTable($resolved);
        $updated = $source->updated($source->values(), $actorId, $at);
        try {
            $sourceDirect = $recordTable->column('relation:' . $relationship->handle . '.target_id');
            if ($sourceDirect !== null) {
                if ($position !== null || $ownedLineValues !== []) {
                    throw new BusinessRelationshipRejected('A singular relationship does not accept line values.');
                }
                $this->casUpdate($recordTable, $source->recordKey, $expectedVersion, [
                    $sourceDirect->physicalName => $targetRecordKey,
                    $this->physical($recordTable, 'version') => $updated->version,
                    $this->physical($recordTable, 'updated_by') => $actorId,
                    $this->physical($recordTable, 'updated_at') => $at,
                ]);
                return new RelationshipWriteResult($updated);
            }

            $association = $this->associationTableOrNull($resolved, $relationship);
            if ($relationship->kind === RelationshipKind::OwnedLineCollection) {
                if ($association === null) {
                    throw new BusinessRecordSchemaUnavailable('The installed owned-line table is unavailable.');
                }
                if ($ownedLineDefinition === null) {
                    throw new BusinessRelationshipRejected('An owned line requires its pinned line definition.');
                }
                $values = [
                    $this->physical($association, 'owner_id') => $source->recordKey,
                    $this->physical($association, 'line_id') => $targetRecordKey,
                    $this->physical($association, 'position') =>
                        $this->position($association, 'owner_id', $source->recordKey, $position),
                    $this->physical($association, 'version') => 1,
                    $this->physical($association, 'created_by') => $actorId,
                    $this->physical($association, 'created_at') => $at,
                    $this->physical($association, 'updated_by') => $actorId,
                    $this->physical($association, 'updated_at') => $at,
                    ...$this->associationScopeValues($association, $source),
                    ...$this->codec->encodeColumns($ownedLineDefinition, $association, $ownedLineValues),
                ];
            } elseif ($association !== null) {
                if ($ownedLineValues !== []) {
                    throw new BusinessRelationshipRejected('Only an owned line accepts embedded target values.');
                }
                $values = [
                    $this->physical($association, 'source_id') => $source->recordKey,
                    $this->physical($association, 'target_id') => $targetRecordKey,
                    $this->physical($association, 'position') => $relationship->ordered
                        ? $this->position($association, 'source_id', $source->recordKey, $position)
                        : null,
                    $this->physical($association, 'version') => 1,
                    $this->physical($association, 'created_by') => $actorId,
                    $this->physical($association, 'created_at') => $at,
                    $this->physical($association, 'updated_by') => $actorId,
                    $this->physical($association, 'updated_at') => $at,
                    ...$this->associationScopeValues($association, $source),
                ];
            } else {
                [$targetResolved, $target, $inverse] = $this->inverseStorage(
                    $resolved,
                    $relationship,
                    $targetResolved,
                    $target,
                    $targetRecordKey,
                );
                $targetTable = $this->recordTable($targetResolved);
                $targetDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
                if ($targetDirect !== null) {
                    if ($position !== null || $ownedLineValues !== []) {
                        throw new BusinessRelationshipRejected('A singular inverse relationship accepts no position.');
                    }
                    $targetUpdated = $target->updated($target->values(), $actorId, $at);
                    $this->casUpdate($targetTable, $target->recordKey, $target->version, [
                        $targetDirect->physicalName => $source->recordKey,
                        $this->physical($targetTable, 'version') => $targetUpdated->version,
                        $this->physical($targetTable, 'updated_by') => $actorId,
                        $this->physical($targetTable, 'updated_at') => $at,
                    ]);
                    $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);

                    return new RelationshipWriteResult($updated, $targetUpdated, $inverse->handle);
                }
                $association = $targetResolved->installation->blueprint->table('relation:' . $inverse->handle)
                    ?? throw new BusinessRecordSchemaUnavailable('The canonical inverse junction is unavailable.');
                if ($ownedLineValues !== [] || ($position !== null && !$inverse->ordered)) {
                    throw new BusinessRelationshipRejected('The inverse junction does not accept these line values.');
                }
                $values = [
                    $this->physical($association, 'source_id') => $target->recordKey,
                    $this->physical($association, 'target_id') => $source->recordKey,
                    $this->physical($association, 'position') => $inverse->ordered
                        ? $this->position($association, 'source_id', $target->recordKey, $position)
                        : null,
                    $this->physical($association, 'version') => 1,
                    $this->physical($association, 'created_by') => $actorId,
                    $this->physical($association, 'created_at') => $at,
                    $this->physical($association, 'updated_by') => $actorId,
                    $this->physical($association, 'updated_at') => $at,
                    ...$this->associationScopeValues($association, $source),
                ];
            }
            $this->database->insert($association->physicalName, $values, $this->types($association, $values));
            $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);
        } catch (DbalException $exception) {
            $this->map($exception, $relationship->handle);
        }

        return new RelationshipWriteResult($updated);
    }

    /**
     * Remove one link between the source record and a target, and re-version the source row.
     *
     * The link is cleared wherever it is actually stored: a singular target column is nulled under a
     * compare-and-set that also matches the key currently in it, a junction or owned-line row is deleted,
     * and where the canonical storage belongs to the inverse side it is the target's column or junction
     * that changes. Detaching an owned line deletes the line itself, because the line lives in that table.
     * A relationship marked required — on whichever side owns the storage — is refused rather than emptied,
     * so a mandatory link is never left dangling.
     *
     * @param   ResolvedBusinessDefinition   $resolved         Definition pinned to the installation holding
     *          the source row.
     * @param   BusinessRecord               $source           Record the relationship is declared on.
     * @param   RelationshipDefinition       $relationship     Relationship being emptied.
     * @param   string                       $targetRecordKey  Storage key of the linked row to detach.
     * @param   string                       $actorId          Actor credited with every row this write
     *          re-versions.
     * @param   DateTimeImmutable            $at               Instant stamped on every row this write
     *          touches.
     * @param   int                          $expectedVersion  Version of the source the caller read.
     * @param   ?ResolvedBusinessDefinition  $targetResolved   Pinned definition of the target, required once
     *          the inverse side owns the storage.
     * @param   ?BusinessRecord              $target           Target record itself, required on that same
     *          path and matched against $targetRecordKey.
     *
     * @return  RelationshipWriteResult  The re-versioned source; it names the inverse handle whenever the
     *          link was stored on the target's side, and carries the target record too when that storage
     *          was a column on the target's own row.
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessRelationshipRejected  When the relationship or its canonical inverse is required, or
     *          no such link is stored.
     * @throws  BusinessRecordNotFound  When the source row, or a target row this write re-versions, no
     *          longer exists.
     * @throws  BusinessRecordVersionConflict  When a row this write re-versions no longer carries the
     *          version the caller read for it.
     * @throws  BusinessRecordSchemaUnavailable  When neither side of the installation describes storage for
     *          the relationship, a column the write names is not in its blueprint, or the target cannot be
     *          matched to its pinned definition.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the write for a transient
     *          reason such as a deadlock.
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
    ): RelationshipWriteResult {
        $this->assertTransaction();
        $recordTable = $this->recordTable($resolved);
        $updated = $source->updated($source->values(), $actorId, $at);
        try {
            $sourceDirect = $recordTable->column('relation:' . $relationship->handle . '.target_id');
            if ($sourceDirect !== null) {
                if ($relationship->required) {
                    throw new BusinessRelationshipRejected('A required relationship cannot be removed.');
                }
                $this->casClearRelationship(
                    $recordTable,
                    $source->recordKey,
                    $expectedVersion,
                    $sourceDirect->physicalName,
                    $targetRecordKey,
                    [
                        $this->physical($recordTable, 'version') => $updated->version,
                        $this->physical($recordTable, 'updated_by') => $actorId,
                        $this->physical($recordTable, 'updated_at') => $at,
                    ],
                );
                return new RelationshipWriteResult($updated);
            }

            $association = $this->associationTableOrNull($resolved, $relationship);
            $sourceLogical = $relationship->kind === RelationshipKind::OwnedLineCollection
                ? 'owner_id'
                : 'source_id';
            $targetLogical = $relationship->kind === RelationshipKind::OwnedLineCollection
                ? 'line_id'
                : 'target_id';
            $sourceKey = $source->recordKey;
            $storedTargetKey = $targetRecordKey;
            $targetUpdated = null;
            $targetRelationship = null;
            if ($association === null) {
                [$targetResolved, $target, $inverse] = $this->inverseStorage(
                    $resolved,
                    $relationship,
                    $targetResolved,
                    $target,
                    $targetRecordKey,
                );
                $targetTable = $this->recordTable($targetResolved);
                $targetDirect = $targetTable->column('relation:' . $inverse->handle . '.target_id');
                if ($targetDirect !== null) {
                    if ($inverse->required) {
                        throw new BusinessRelationshipRejected('A required inverse relationship cannot be removed.');
                    }
                    $targetUpdated = $target->updated($target->values(), $actorId, $at);
                    $this->casClearRelationship(
                        $targetTable,
                        $target->recordKey,
                        $target->version,
                        $targetDirect->physicalName,
                        $source->recordKey,
                        [
                            $this->physical($targetTable, 'version') => $targetUpdated->version,
                            $this->physical($targetTable, 'updated_by') => $actorId,
                            $this->physical($targetTable, 'updated_at') => $at,
                        ],
                    );
                    $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);

                    return new RelationshipWriteResult($updated, $targetUpdated, $inverse->handle);
                }
                $association = $targetResolved->installation->blueprint->table('relation:' . $inverse->handle)
                    ?? throw new BusinessRecordSchemaUnavailable('The canonical inverse junction is unavailable.');
                $sourceLogical = 'source_id';
                $targetLogical = 'target_id';
                $sourceKey = $target->recordKey;
                $storedTargetKey = $source->recordKey;
                $targetRelationship = $inverse->handle;
            }
            $affected = $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE %s = ? AND %s = ?',
                $this->quote($association->physicalName),
                $this->quote($this->physical($association, $sourceLogical)),
                $this->quote($this->physical($association, $targetLogical)),
            ), [$sourceKey, $storedTargetKey], [
                $this->type($association, $sourceLogical),
                $this->type($association, $targetLogical),
            ]);
            if ($affected !== 1) {
                throw new BusinessRelationshipRejected('The requested relationship does not exist.');
            }
            $this->bump($recordTable, $source->recordKey, $expectedVersion, $updated);
        } catch (DbalException $exception) {
            $this->map($exception, $relationship->handle);
        }

        return new RelationshipWriteResult($updated, $targetUpdated, $targetRelationship);
    }

    /**
     * Renumber an ordered collection into the given order and re-version the source row.
     *
     * The keys must be exactly the links currently stored, so a stale or partial list is refused before
     * anything is written rather than quietly reordering a subset. The rewrite then runs in two passes:
     * every position for this source is first flipped to a negative value, and the new positions are then
     * written set-based, one bounded statement per hundred links rather than one statement per link, which
     * keeps a unique index over source and position satisfied throughout because every not-yet-renumbered
     * row still holds a negative position. A statement that renumbers fewer rows than its chunk carries
     * means a link moved underneath the caller, and the reorder is refused. Only a collection whose
     * storage the source side owns can be renumbered here; when the canonical junction belongs to the
     * inverse definition, the reorder has to be asked of that side.
     *
     * @param   ResolvedBusinessDefinition   $resolved           Definition pinned to the installation
     *          holding the source row.
     * @param   BusinessRecord               $source             Record whose collection is renumbered.
     * @param   RelationshipDefinition       $relationship       Ordered collection to renumber.
     * @param   list<string>                 $orderedRecordKeys  Storage keys of every current link, in the
     *          order to store them; each key's offset becomes its stored position.
     * @param   string                       $actorId            Actor credited with the source's new version
     *          and with every renumbered link row.
     * @param   DateTimeImmutable            $at                 Instant stamped on every row this write
     *          touches.
     * @param   int                          $expectedVersion    Version of the source the caller read.
     * @param   ?ResolvedBusinessDefinition  $targetResolved     Pinned definition of the linked type; unused
     *          here, because renumbering only touches source-side storage.
     *
     * @return  BusinessRecord  The source at its new version, with the collection numbered from zero.
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessRelationshipRejected  When the relationship is not an ordered collection, its storage
     *          belongs to the inverse side, the keys are not a permutation of the stored links, or a link
     *          changed while the order was being written.
     * @throws  BusinessRecordNotFound  When the source row no longer exists.
     * @throws  BusinessRecordVersionConflict  When the stored source version differs from $expectedVersion.
     * @throws  BusinessRecordSchemaUnavailable  When the installation does not describe the collection's
     *          storage, or a stored link identity is not a readable key.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the write for a transient
     *          reason such as a deadlock.
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
    ): BusinessRecord {
        $this->assertTransaction();
        if (
            !$relationship->ordered || in_array(
                $relationship->kind,
                [RelationshipKind::OneToOne, RelationshipKind::ManyToOne],
                true,
            )
        ) {
            throw new BusinessRelationshipRejected('Only an ordered collection relationship can be reordered.');
        }
        $association = $this->associationTableOrNull($resolved, $relationship);
        if ($association === null) {
            throw new BusinessRelationshipRejected(
                'Source-side ordering is unavailable when an inverse definition owns relationship storage.',
            );
        }
        $sourceLogical = $relationship->kind === RelationshipKind::OwnedLineCollection ? 'owner_id' : 'source_id';
        $targetLogical = $relationship->kind === RelationshipKind::OwnedLineCollection ? 'line_id' : 'target_id';
        $sourceColumn = $this->physical($association, $sourceLogical);
        $targetColumn = $this->physical($association, $targetLogical);
        $positionColumn = $this->physical($association, 'position');
        $rows = $this->database->fetchFirstColumn(sprintf(
            'SELECT %s FROM %s WHERE %s = ? ORDER BY %s, %s',
            $this->quote($targetColumn),
            $this->quote($association->physicalName),
            $this->quote($sourceColumn),
            $this->quote($positionColumn),
            $this->quote($targetColumn),
        ), [$source->recordKey], [$this->type($association, $sourceLogical)]);
        $stored = array_map(static function (mixed $value): string {
            if (!is_string($value)) {
                throw new BusinessRecordSchemaUnavailable('A stored relationship identity is invalid.');
            }

            return $value;
        }, $rows);
        $expectedSet = $orderedRecordKeys;
        sort($stored, SORT_STRING);
        sort($expectedSet, SORT_STRING);
        if ($stored !== $expectedSet) {
            throw new BusinessRelationshipRejected('A reorder must contain every current relationship exactly once.');
        }

        try {
            $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s = -%s - 1 WHERE %s = ?',
                $this->quote($association->physicalName),
                $this->quote($positionColumn),
                $this->quote($positionColumn),
                $this->quote($sourceColumn),
            ), [$source->recordKey], [$this->type($association, $sourceLogical)]);
            $targetType = $this->type($association, $targetLogical);
            foreach (array_chunk($orderedRecordKeys, self::REORDER_BATCH, true) as $chunk) {
                $cases = [];
                $parameters = [];
                $types = [];
                foreach ($chunk as $position => $targetRecordKey) {
                    $cases[] = 'WHEN ? THEN ?';
                    $parameters[] = $targetRecordKey;
                    $types[] = $targetType;
                    $parameters[] = $position;
                    $types[] = Types::INTEGER;
                }
                $parameters[] = $actorId;
                $types[] = Types::STRING;
                $parameters[] = $at;
                $types[] = Types::DATETIME_IMMUTABLE;
                $parameters[] = $source->recordKey;
                $types[] = $this->type($association, $sourceLogical);
                foreach ($chunk as $targetRecordKey) {
                    $parameters[] = $targetRecordKey;
                    $types[] = $targetType;
                }
                $affected = $this->database->executeStatement(sprintf(
                    'UPDATE %s SET %s = CASE %s %s END, %s = %s + 1, %s = ?, %s = ? '
                    . 'WHERE %s = ? AND %s IN (%s)',
                    $this->quote($association->physicalName),
                    $this->quote($positionColumn),
                    $this->quote($targetColumn),
                    implode(' ', $cases),
                    $this->quote($this->physical($association, 'version')),
                    $this->quote($this->physical($association, 'version')),
                    $this->quote($this->physical($association, 'updated_by')),
                    $this->quote($this->physical($association, 'updated_at')),
                    $this->quote($sourceColumn),
                    $this->quote($targetColumn),
                    implode(', ', array_fill(0, count($chunk), '?')),
                ), $parameters, $types);
                if ($affected !== count($chunk)) {
                    throw new BusinessRelationshipRejected('A relationship changed while its order was updated.');
                }
            }
            $updated = $source->updated($source->values(), $actorId, $at);
            $this->bump($this->recordTable($resolved), $source->recordKey, $expectedVersion, $updated);
        } catch (DbalException $exception) {
            $this->map($exception, $relationship->handle);
        }

        return $updated;
    }

    /**
     * Store one owner's whole owned-line collection, in statements bounded by the change rather than by it.
     *
     * Nothing here touches the owner row: the caller has already written the header at its new version
     * inside the same transaction, and a second bump would make one document write count as two. What is
     * done here is the collection, in a fixed order that keeps the unique index over owner and position
     * satisfied at every step — remove what the document dropped, park every survivor on a negative slot
     * when the order moved, write the survivors back at their final slots, then insert what is new.
     *
     * Statement growth is deliberately sublinear in the collection. Inserts and deletes go in bounded
     * batches sized so a thousand-line document stays well inside the parameter ceiling every supported
     * engine enforces, and a line the caller did not mark modified issues nothing at all.
     *
     * @param   ResolvedBusinessDefinition  $resolved           Definition pinned to the installation
     *          holding the owner row and its line table.
     * @param   BusinessRecord              $owner              Owner record the lines belong to.
     * @param   RelationshipDefinition      $relationship       Owned-line collection being written.
     * @param   EntityTypeDefinition        $lineDefinition     Pinned line definition the values encode
     *          against.
     * @param   list<OwnedLineWrite>        $lines              The whole collection in position order.
     * @param   list<string>                $removedRecordKeys  Storage keys the document no longer holds.
     * @param   bool                        $renumber           True when at least one surviving line moves.
     * @param   string                      $actorId            Actor credited with every row this touches.
     * @param   DateTimeImmutable           $at                 Instant stamped on every row this touches.
     *
     * @return  void
     *
     * @throws  LogicException  When the caller has no transaction open.
     * @throws  BusinessRelationshipRejected  When the relationship is not an owned-line collection, or a
     *          line did not move under the version the caller named for it.
     * @throws  BusinessRecordSchemaUnavailable  When the installation describes no line table for this
     *          collection, or a column the write names is not in its blueprint.
     * @throws  BusinessRecordUniqueConflict  When a line collides with a unique index.
     * @throws  BusinessRecordReferenceConflict  When a line's stored reference names a row that is absent.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses a statement for any other
     *          reason, such as a deadlock.
     *
     * @since   2.0.0
     */
    public function writeOwnedLines(
        ResolvedBusinessDefinition $resolved,
        BusinessRecord $owner,
        RelationshipDefinition $relationship,
        EntityTypeDefinition $lineDefinition,
        array $lines,
        array $removedRecordKeys,
        bool $renumber,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $this->assertTransaction();
        if ($relationship->kind !== RelationshipKind::OwnedLineCollection) {
            throw new BusinessRelationshipRejected('Only an owned-line collection is written as a document.');
        }
        $table = $this->associationTableOrNull($resolved, $relationship)
            ?? throw new BusinessRecordSchemaUnavailable('The installed owned-line table is unavailable.');
        try {
            $this->deleteOwnedLines($table, $owner->recordKey, $removedRecordKeys);
            if ($renumber) {
                $this->database->executeStatement(sprintf(
                    'UPDATE %s SET %s = -%s - 1 WHERE %s = ?',
                    $this->quote($table->physicalName),
                    $this->quote($this->physical($table, 'position')),
                    $this->quote($this->physical($table, 'position')),
                    $this->quote($this->physical($table, 'owner_id')),
                ), [$owner->recordKey], [$this->type($table, 'owner_id')]);
            }
            $inserts = [];
            foreach ($lines as $line) {
                if ($line->storedVersion === null) {
                    $inserts[] = $this->ownedLineRow($table, $lineDefinition, $owner, $line, $actorId, $at);
                    continue;
                }
                if (!$line->modified) {
                    continue;
                }
                $this->updateOwnedLine($table, $lineDefinition, $owner, $line, $actorId, $at);
            }
            $this->insertOwnedLines($table, $inserts);
        } catch (DbalException $exception) {
            $this->map($exception, $relationship->handle);
        }
    }

    /**
     * Delete the lines a document no longer holds, in batches bounded by the parameter ceiling.
     *
     * The owner's key is part of every predicate, so a key that belongs to another document deletes
     * nothing rather than reaching outside the collection being written.
     *
     * @param   PhysicalTableBlueprint  $table       Installed line table holding the rows.
     * @param   string                  $ownerKey    Storage key of the owner whose lines are removed.
     * @param   list<string>            $recordKeys  Storage keys of the lines to delete.
     *
     * @return  void
     *
     * @throws  BusinessRecordSchemaUnavailable  When a column this delete names is not in the blueprint.
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses the delete.
     *
     * @since   2.0.0
     */
    private function deleteOwnedLines(PhysicalTableBlueprint $table, string $ownerKey, array $recordKeys): void
    {
        foreach (array_chunk($recordKeys, self::OWNED_LINE_BATCH) as $batch) {
            $this->database->executeStatement(sprintf(
                'DELETE FROM %s WHERE %s = ? AND %s IN (?)',
                $this->quote($table->physicalName),
                $this->quote($this->physical($table, 'owner_id')),
                $this->quote($this->physical($table, 'line_id')),
            ), [$ownerKey, $batch], [
                $this->type($table, 'owner_id'),
                ArrayParameterType::STRING,
            ]);
        }
    }

    /**
     * Build the whole column set one new line row is inserted with.
     *
     * A line inherits its owner's tenancy rather than declaring its own, starts at version one, and takes
     * the slot the caller assigned it from the submitted order.
     *
     * @param   PhysicalTableBlueprint  $table           Installed line table receiving the row.
     * @param   EntityTypeDefinition    $lineDefinition  Pinned line definition the values encode against.
     * @param   BusinessRecord          $owner           Owner record supplying the key and the scope.
     * @param   OwnedLineWrite          $line            Prepared line to store.
     * @param   string                  $actorId         Actor credited with the new row.
     * @param   DateTimeImmutable       $at              Instant stamped on the new row.
     *
     * @return  array<string, mixed>  Column values keyed by physical name.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a column the row needs is not in the blueprint, or
     *          the owner carries a scope value the line table has nowhere to record.
     *
     * @since   2.0.0
     */
    private function ownedLineRow(
        PhysicalTableBlueprint $table,
        EntityTypeDefinition $lineDefinition,
        BusinessRecord $owner,
        OwnedLineWrite $line,
        string $actorId,
        DateTimeImmutable $at,
    ): array {
        return [
            $this->physical($table, 'owner_id') => $owner->recordKey,
            $this->physical($table, 'line_id') => $line->recordKey,
            $this->physical($table, 'position') => $line->position,
            $this->physical($table, 'version') => 1,
            $this->physical($table, 'created_by') => $actorId,
            $this->physical($table, 'created_at') => $at,
            $this->physical($table, 'updated_by') => $actorId,
            $this->physical($table, 'updated_at') => $at,
            ...$this->associationScopeValues($table, $owner),
            ...$this->codec->encodeColumns($lineDefinition, $table, $line->values),
        ];
    }

    /**
     * Insert prepared line rows as multi-row statements bounded by the parameter ceiling.
     *
     * Every row carries the same column set, which is what lets one statement hold a batch of them; the
     * batch size is chosen from the column count so the bound parameter list stays far below the limit
     * MariaDB, MySQL and PostgreSQL each impose, and a thousand-line document therefore costs a handful of
     * statements rather than a thousand.
     *
     * @param   PhysicalTableBlueprint      $table  Installed line table receiving the rows.
     * @param   list<array<string, mixed>>  $rows   Column values keyed by physical name, one entry per row.
     *
     * @return  void
     *
     * @throws  BusinessRecordSchemaUnavailable  When a named column is not in the table's blueprint.
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses the insert.
     *
     * @since   2.0.0
     */
    private function insertOwnedLines(PhysicalTableBlueprint $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $columns = array_keys($rows[0]);
        $columnTypes = $this->types($table, $rows[0]);
        $batch = max(1, min(self::OWNED_LINE_BATCH, intdiv(self::MAXIMUM_PARAMETERS, max(1, count($columns)))));
        $quoted = implode(', ', array_map($this->quote(...), $columns));
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, $batch) as $chunk) {
            /** @var list<mixed> $parameters */
            $parameters = [];
            /** @var list<string> $types */
            $types = [];
            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $parameters[] = $row[$column] ?? null;
                    $types[] = $columnTypes[$column];
                }
            }
            $this->database->executeStatement(sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $this->quote($table->physicalName),
                $quoted,
                implode(', ', array_fill(0, count($chunk), $placeholders)),
            ), $parameters, $types);
        }
    }

    /**
     * Rewrite one existing line under the version the caller read for it.
     *
     * The predicate names the owner as well as the line, so a line cannot be moved into a document it does
     * not belong to, and it names the version so a line that changed under the command is refused rather
     * than overwritten — which is what carries the aggregate's optimistic concurrency down to the row.
     *
     * @param   PhysicalTableBlueprint  $table           Installed line table holding the row.
     * @param   EntityTypeDefinition    $lineDefinition  Pinned line definition the values encode against.
     * @param   BusinessRecord          $owner           Owner record the line must still belong to.
     * @param   OwnedLineWrite          $line            Prepared line, carrying the version it was read at.
     * @param   string                  $actorId         Actor credited with the new line version.
     * @param   DateTimeImmutable       $at              Instant stamped on the rewritten row.
     *
     * @return  void
     *
     * @throws  BusinessRelationshipRejected  When the line did not move under the version the caller named.
     * @throws  BusinessRecordSchemaUnavailable  When a column this write names is not in the blueprint.
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses the update.
     *
     * @since   2.0.0
     */
    private function updateOwnedLine(
        PhysicalTableBlueprint $table,
        EntityTypeDefinition $lineDefinition,
        BusinessRecord $owner,
        OwnedLineWrite $line,
        string $actorId,
        DateTimeImmutable $at,
    ): void {
        $values = [
            $this->physical($table, 'position') => $line->position,
            $this->physical($table, 'version') => ($line->storedVersion ?? 0) + 1,
            $this->physical($table, 'updated_by') => $actorId,
            $this->physical($table, 'updated_at') => $at,
            ...$this->codec->encodeColumns($lineDefinition, $table, $line->values),
        ];
        /** @var list<string> $assignments */
        $assignments = [];
        /** @var list<mixed> $parameters */
        $parameters = [];
        /** @var list<string> $types */
        $types = [];
        $columnTypes = $this->types($table, $values);
        foreach ($values as $column => $value) {
            $assignments[] = $this->quote($column) . ' = ?';
            $parameters[] = $value;
            $types[] = $columnTypes[$column];
        }
        array_push($parameters, $owner->recordKey, $line->recordKey, $line->storedVersion);
        array_push(
            $types,
            $this->type($table, 'owner_id'),
            $this->type($table, 'line_id'),
            Types::INTEGER,
        );
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET %s WHERE %s = ? AND %s = ? AND %s = ?',
            $this->quote($table->physicalName),
            implode(', ', $assignments),
            $this->quote($this->physical($table, 'owner_id')),
            $this->quote($this->physical($table, 'line_id')),
            $this->quote($this->physical($table, 'version')),
        ), $parameters, $types);
        if ($affected !== 1) {
            throw new BusinessRelationshipRejected('A document line changed while the document was being written.');
        }
    }

    /**
     * Collect the control columns this installed table happens to declare, ready to insert or update.
     *
     * Scope, workflow, archive, and soft-delete columns exist only where the definition asked for them, so
     * each is looked up in the blueprint and skipped when the table carries no column for it.
     *
     * @param   PhysicalTableBlueprint  $table   Installed record table whose columns decide what is written.
     * @param   BusinessRecord          $record  Record the control values are read from.
     *
     * @return  array<string, mixed>  Values keyed by physical column name; empty when the table declares
     *          none of these columns.
     *
     * @since   2.0.0
     */
    private function optionalControlValues(PhysicalTableBlueprint $table, BusinessRecord $record): array
    {
        $logical = [
            'site_identifier' => $record->scope->siteIdentifier,
            'organization_identifier' => $record->scope->organizationIdentifier,
            'workflow_state' => $record->workflowState,
            'archived_by' => $record->archivedBy,
            'archived_at' => $record->archivedAt,
            'deleted_by' => $record->deletedBy,
            'deleted_at' => $record->deletedAt,
        ];
        $values = [];
        foreach ($logical as $handle => $value) {
            $column = $table->column($handle);
            if ($column !== null) {
                $values[$column->physicalName] = $value;
            }
        }

        return $values;
    }

    /**
     * Copy the source record's scope onto the junction or owned-line row about to be written.
     *
     * A link is only as scoped as its own row, so a record that belongs to a site or an organization may
     * not be linked through a table with nowhere to record that; the mismatch is reported instead of being
     * dropped silently. A null scope value against a missing column is simply nothing to write.
     *
     * @param   PhysicalTableBlueprint  $table   Installed association or line table receiving the row.
     * @param   BusinessRecord          $source  Record whose scope the link inherits.
     *
     * @return  array<string, string|null>  Scope values keyed by physical column name.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the source carries a scope value the association table
     *          has no column for.
     *
     * @since   2.0.0
     */
    private function associationScopeValues(PhysicalTableBlueprint $table, BusinessRecord $source): array
    {
        $values = [];
        foreach (
            [
            'site_identifier' => $source->scope->siteIdentifier,
            'organization_identifier' => $source->scope->organizationIdentifier,
            ] as $logical => $value
        ) {
            $column = $table->column($logical);
            if ($column !== null) {
                $values[$column->physicalName] = $value;
            } elseif ($value !== null) {
                throw new BusinessRecordSchemaUnavailable('Relationship scope disagrees with installed storage.');
            }
        }

        return $values;
    }

    /**
     * Apply one compare-and-set UPDATE to a record row, keyed by storage key and expected version.
     *
     * This is the shared compare-and-set behind `update()` and behind every relationship write that
     * re-versions a row: the statement changes one row or none, and a miss is turned into the exception
     * that says which of the two reasons it was.
     *
     * @param   PhysicalTableBlueprint  $table            Installed record table holding the row.
     * @param   string                  $recordKey        Storage key of the row to update.
     * @param   int                     $expectedVersion  Version the row must still carry for the write to
     *          land.
     * @param   array<string, mixed>    $values           Column values keyed by physical name; their DBAL
     *          binding types come from the blueprint.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When no row carries that storage key.
     * @throws  BusinessRecordVersionConflict  When the row exists but is at a different version.
     * @throws  BusinessRecordSchemaUnavailable  When a column named here is not in the table's blueprint.
     * @throws  BusinessRecordUniqueConflict  When the new values collide with a unique index.
     * @throws  BusinessRecordReferenceConflict  When a written reference names a row that does not exist.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the update for any other
     *          reason, such as a deadlock.
     *
     * @since   2.0.0
     */
    private function casUpdate(
        PhysicalTableBlueprint $table,
        string $recordKey,
        int $expectedVersion,
        array $values,
    ): void {
        /** @var list<string> $assignments */
        $assignments = [];
        /** @var list<mixed> $parameters */
        $parameters = [];
        /** @var list<string> $types */
        $types = [];
        $columnTypes = $this->types($table, $values);
        foreach ($values as $column => $value) {
            $assignments[] = $this->quote($column) . ' = ?';
            $parameters[] = $value;
            $types[] = $columnTypes[$column];
        }
        $parameters[] = $recordKey;
        $parameters[] = $expectedVersion;
        $types[] = $this->type($table, 'record_id');
        $types[] = Types::INTEGER;
        try {
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s WHERE %s = ? AND %s = ?',
                $this->quote($table->physicalName),
                implode(', ', $assignments),
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($this->physical($table, 'version')),
            ), $parameters, $types);
        } catch (DbalException $exception) {
            $this->map($exception);
        }
        if ($affected !== 1) {
            $this->conflict($table, $recordKey, $expectedVersion);
        }
    }

    /**
     * Null a singular relationship column under a compare-and-set that also matches the stored target.
     *
     * The extra guard is what makes "unlink this target" mean it, since a column already pointing somewhere
     * else is left alone. When nothing matched, the row's version is re-read to separate a record that
     * vanished or moved on from a link that was simply not the one asked for.
     *
     * @param   PhysicalTableBlueprint  $table               Installed record table holding the row.
     * @param   string                  $recordKey           Storage key of the row to clear.
     * @param   int                     $expectedVersion     Version the row must still carry for the write
     *          to land.
     * @param   string                  $relationshipColumn  Physical relationship column set to NULL.
     * @param   string                  $expectedTargetKey   Key that column must currently hold.
     * @param   array<string, mixed>    $values              Other column values keyed by physical name,
     *          being the new version and audit stamps.
     *
     * @return  void
     *
     * @throws  BusinessRelationshipRejected  When the row is at the expected version but its column does not
     *          hold $expectedTargetKey.
     * @throws  BusinessRecordNotFound  When no row carries that storage key.
     * @throws  BusinessRecordVersionConflict  When the row exists but is at a different version.
     * @throws  BusinessRecordSchemaUnavailable  When a column named here is not in the table's blueprint, or
     *          the version read back is not a readable integer.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the update, such as on a
     *          deadlock.
     *
     * @since   2.0.0
     */
    private function casClearRelationship(
        PhysicalTableBlueprint $table,
        string $recordKey,
        int $expectedVersion,
        string $relationshipColumn,
        string $expectedTargetKey,
        array $values,
    ): void {
        /** @var list<string> $assignments */
        $assignments = [];
        /** @var list<mixed> $parameters */
        $parameters = [];
        /** @var list<string> $types */
        $types = [];
        $columnTypes = $this->types($table, $values);
        foreach ($values as $column => $value) {
            $assignments[] = $this->quote($column) . ' = ?';
            $parameters[] = $value;
            $types[] = $columnTypes[$column];
        }
        $assignments[] = $this->quote($relationshipColumn) . ' = NULL';
        $relationship = $this->columnByPhysical($table, $relationshipColumn);
        array_push($parameters, $recordKey, $expectedVersion, $expectedTargetKey);
        array_push($types, $this->type($table, 'record_id'), Types::INTEGER, $relationship->doctrineType);
        try {
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s WHERE %s = ? AND %s = ? AND %s = ?',
                $this->quote($table->physicalName),
                implode(', ', $assignments),
                $this->quote($this->physical($table, 'record_id')),
                $this->quote($this->physical($table, 'version')),
                $this->quote($relationshipColumn),
            ), $parameters, $types);
        } catch (DbalException $exception) {
            $this->map($exception);
        }
        if ($affected === 1) {
            return;
        }
        $actual = $this->database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, 'record_id')),
        ), [$recordKey], [$this->type($table, 'record_id')]);
        if ($actual === false || $this->storedInteger($actual) !== $expectedVersion) {
            $this->conflict($table, $recordKey, $expectedVersion);
        }
        throw new BusinessRelationshipRejected('The requested singular relationship does not exist.');
    }

    /**
     * Re-version a record row whose relationship a write changed somewhere else.
     *
     * Writing a link into a junction, into a line table, or onto the other side's row leaves the record
     * itself untouched, so its new version, actor, and timestamp are carried over by a compare-and-set of
     * their own — which is also what makes a concurrent edit of that record lose the race.
     *
     * @param   PhysicalTableBlueprint  $table            Installed record table holding the row.
     * @param   string                  $recordKey        Storage key of the row to re-version.
     * @param   int                     $expectedVersion  Version the row must still carry for the write to
     *          land.
     * @param   BusinessRecord          $updated          Successor record supplying the new version and
     *          audit stamps.
     *
     * @return  void
     *
     * @throws  BusinessRecordNotFound  When no row carries that storage key.
     * @throws  BusinessRecordVersionConflict  When the row exists but is at a different version.
     * @throws  BusinessRecordSchemaUnavailable  When the version or audit columns are not in the table's
     *          blueprint.
     * @throws  BusinessRecordTemporarilyUnavailable  When the driver refuses the update, such as on a
     *          deadlock.
     *
     * @since   2.0.0
     */
    private function bump(
        PhysicalTableBlueprint $table,
        string $recordKey,
        int $expectedVersion,
        BusinessRecord $updated,
    ): void {
        $this->casUpdate($table, $recordKey, $expectedVersion, [
            $this->physical($table, 'version') => $updated->version,
            $this->physical($table, 'updated_by') => $updated->updatedBy,
            $this->physical($table, 'updated_at') => $updated->updatedAt,
        ]);
    }

    /**
     * Decide which position a new link takes in an ordered or owned-line collection.
     *
     * A requested slot is taken at face value, duplicates included, since contiguous numbering is only
     * restored by `reorder()`. Without one, the link is appended one past the highest position stored for
     * this owner, and an owner with no links yet starts at zero.
     *
     * @param   PhysicalTableBlueprint  $table          Installed association or line table to measure.
     * @param   string                  $sourceLogical  Logical name of the owning column, `owner_id` for an
     *          owned line and `source_id` otherwise.
     * @param   string                  $sourceId       Storage key of the owner whose collection is
     *          measured.
     * @param   ?int                    $requested      Position the caller asked for, or null to append.
     *
     * @return  int  The position to store on the new row.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the position or owning column is not in the table's
     *          blueprint, or the stored maximum is not a readable integer.
     *
     * @since   2.0.0
     */
    private function position(
        PhysicalTableBlueprint $table,
        string $sourceLogical,
        string $sourceId,
        ?int $requested,
    ): int {
        if ($requested !== null) {
            return $requested;
        }
        $value = $this->database->fetchOne(sprintf(
            'SELECT MAX(%s) FROM %s WHERE %s = ?',
            $this->quote($this->physical($table, 'position')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, $sourceLogical)),
        ), [$sourceId], [$this->type($table, $sourceLogical)]);

        return $value === false || $value === null ? 0 : $this->storedInteger($value) + 1;
    }

    /**
     * Report why a compare-and-set matched no row, by re-reading that row's version.
     *
     * @param   PhysicalTableBlueprint  $table            Installed record table to re-read.
     * @param   string                  $recordKey        Storage key the failed write named.
     * @param   int                     $expectedVersion  Version the failed write expected to find.
     *
     * @return  never
     *
     * @throws  BusinessRecordNotFound  When the row is gone.
     * @throws  BusinessRecordVersionConflict  When the row is still there at another version, carrying both
     *          the expected and the stored value for the caller to report.
     * @throws  BusinessRecordSchemaUnavailable  When the key or version column is not in the table's
     *          blueprint, or the stored version is not a readable integer.
     *
     * @since   2.0.0
     */
    private function conflict(PhysicalTableBlueprint $table, string $recordKey, int $expectedVersion): never
    {
        $actual = $this->database->fetchOne(sprintf(
            'SELECT %s FROM %s WHERE %s = ?',
            $this->quote($this->physical($table, 'version')),
            $this->quote($table->physicalName),
            $this->quote($this->physical($table, 'record_id')),
        ), [$recordKey], [$this->type($table, 'record_id')]);
        if ($actual === false) {
            throw new BusinessRecordNotFound();
        }
        throw new BusinessRecordVersionConflict($expectedVersion, $this->storedInteger($actual));
    }

    /**
     * Resolve the installed record table of a pinned definition.
     *
     * @param   ResolvedBusinessDefinition  $resolved  Definition pinned to the installation to read.
     *
     * @return  PhysicalTableBlueprint  Blueprint of the `record` table every column name in this class is
     *          resolved against.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the installed schema describes no record table.
     *
     * @since   2.0.0
     */
    private function recordTable(ResolvedBusinessDefinition $resolved): PhysicalTableBlueprint
    {
        return $resolved->installation->blueprint->table('record')
            ?? throw new BusinessRecordSchemaUnavailable('The installed schema has no record table.');
    }

    /**
     * Look up the table this installation keeps the relationship's links in, if it keeps one at all.
     *
     * Null is not a failure here: it means this side stores nothing of its own for the relationship, which
     * is the case both for a singular link held as a column on the record table and for a relationship
     * whose canonical storage belongs to the inverse definition.
     *
     * @param   ResolvedBusinessDefinition  $resolved      Definition pinned to the installation to search.
     * @param   RelationshipDefinition      $relationship  Relationship whose storage is wanted.
     *
     * @return  ?PhysicalTableBlueprint  The line table for an owned-line collection, the junction table for
     *          any other relationship that has one, or null when this side stores neither.
     *
     * @since   2.0.0
     */
    private function associationTableOrNull(
        ResolvedBusinessDefinition $resolved,
        RelationshipDefinition $relationship,
    ): ?PhysicalTableBlueprint {
        $logical = $relationship->kind === RelationshipKind::OwnedLineCollection
            ? 'line:' . $relationship->handle
            : 'relation:' . $relationship->handle;

        return $resolved->installation->blueprint->table($logical);
    }

    /**
     * Prove that the target side really owns this relationship's storage, and hand back the three parts.
     *
     * Reached only once the source installation turns out to describe no storage of its own, so everything
     * the caller passed optionally becomes mandatory and is re-checked here: the target must be pinned to
     * its own definition, must be the record $targetRecordKey names, and must declare the reciprocal
     * relationship pointing back at the source definition.
     *
     * @param   ResolvedBusinessDefinition   $sourceResolved   Pinned definition the relationship is declared
     *          on.
     * @param   RelationshipDefinition       $relationship     Relationship whose inverse is being located.
     * @param   ?ResolvedBusinessDefinition  $targetResolved   Pinned definition of the target, as the caller
     *          supplied it.
     * @param   ?BusinessRecord              $target           Target record, as the caller supplied it.
     * @param   string                       $targetRecordKey  Storage key the target record must match.
     *
     * @return  array{ResolvedBusinessDefinition, BusinessRecord, RelationshipDefinition}  The proven target
     *          definition and record, and the inverse relationship that owns the storage.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the target or its pinned definition is absent or does
     *          not match, the relationship names no inverse, or the target declares no reciprocal
     *          relationship back to the source.
     *
     * @since   2.0.0
     */
    private function inverseStorage(
        ResolvedBusinessDefinition $sourceResolved,
        RelationshipDefinition $relationship,
        ?ResolvedBusinessDefinition $targetResolved,
        ?BusinessRecord $target,
        string $targetRecordKey,
    ): array {
        if (
            $targetResolved === null || $target === null || $relationship->inverse === null
            || $target->definitionId !== $targetResolved->definition->id
            || !hash_equals($target->recordKey, $targetRecordKey)
        ) {
            throw new BusinessRecordSchemaUnavailable('Canonical inverse relationship storage cannot be resolved.');
        }
        $inverse = null;
        foreach ($targetResolved->definition->relationships() as $candidate) {
            if (
                $candidate->handle === $relationship->inverse
                && $candidate->target === $sourceResolved->definition->handle
            ) {
                $inverse = $candidate;
                break;
            }
        }
        if ($inverse === null) {
            throw new BusinessRecordSchemaUnavailable('The canonical inverse relationship definition is unavailable.');
        }

        return [$targetResolved, $target, $inverse];
    }

    /**
     * Resolve a logical column handle to the physical column name the installer gave it.
     *
     * @param   PhysicalTableBlueprint  $table    Installed table to resolve against.
     * @param   string                  $logical  Logical column name, such as `record_id`, `version`, or
     *          `relation:<handle>.target_id`.
     *
     * @return  string  Physical column name, ready to be quoted into a statement.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the installed table declares no such column.
     *
     * @since   2.0.0
     */
    private function physical(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->physicalName
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column is unavailable.');
    }

    /**
     * Resolve a logical column handle to the Doctrine type its parameters must be bound as.
     *
     * @param   PhysicalTableBlueprint  $table    Installed table to resolve against.
     * @param   string                  $logical  Logical column name whose binding type is wanted.
     *
     * @return  string  Doctrine type name, from the portable set a blueprint may declare.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the installed table declares no such column.
     *
     * @since   2.0.0
     */
    private function type(PhysicalTableBlueprint $table, string $logical): string
    {
        return $table->column($logical)->doctrineType
            ?? throw new BusinessRecordSchemaUnavailable('An installed business-record column type is unavailable.');
    }

    /**
     * Pair every column in a value set with the Doctrine type DBAL must bind it as.
     *
     * Binding types are read from the blueprint rather than inferred from the PHP value, so a null, a
     * date-time object, or a JSON payload is bound the way the installed column expects it.
     *
     * @param   PhysicalTableBlueprint  $table   Installed table the column names belong to.
     * @param   array<string, mixed>    $values  Values keyed by physical column name; only the keys are read.
     *
     * @return  array<string, string>  Doctrine type names keyed by the same physical column names.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a named column is not in the table's blueprint.
     *
     * @since   2.0.0
     */
    private function types(PhysicalTableBlueprint $table, array $values): array
    {
        $types = [];
        foreach ($values as $physical => $_value) {
            $column = $this->columnByPhysical($table, $physical);
            $types[$physical] = $column->doctrineType;
        }

        return $types;
    }

    /**
     * Find a column of the blueprint by the physical name it was installed under.
     *
     * @param   PhysicalTableBlueprint  $table     Installed table to search.
     * @param   string                  $physical  Physical column name to look for.
     *
     * @return  PhysicalColumnBlueprint  The matching column, with its Doctrine type and options.
     *
     * @throws  BusinessRecordSchemaUnavailable  When no column of the table carries that physical name.
     *
     * @since   2.0.0
     */
    private function columnByPhysical(PhysicalTableBlueprint $table, string $physical): PhysicalColumnBlueprint
    {
        foreach ($table->columns() as $column) {
            if ($column->physicalName === $physical) {
                return $column;
            }
        }
        throw new BusinessRecordSchemaUnavailable('A physical business-record column is not in its blueprint.');
    }

    /**
     * Quote one identifier in the connected platform's own syntax.
     *
     * Every table and column name this class interpolates into a statement passes through here, so a name
     * that came from an installed blueprint is never read as SQL.
     *
     * @param   string  $identifier  Physical table or column name to quote.
     *
     * @return  string  The identifier, quoted for the platform in use.
     *
     * @since   2.0.0
     */
    private function quote(string $identifier): string
    {
        return $this->database->getDatabasePlatform()->quoteSingleIdentifier($identifier);
    }

    /**
     * Read a value the driver returned for an integer column, whichever representation it chose.
     *
     * Drivers are free to hand an integer column back as a decimal string, so both are accepted and
     * anything else is treated as storage that no longer matches its blueprint.
     *
     * @param   mixed  $value  Value as fetched from the database.
     *
     * @return  int  The value as an integer.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the value is neither an integer nor an optionally
     *          signed string of decimal digits.
     *
     * @since   2.0.0
     */
    private function storedInteger(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record integer is invalid.');
        }

        return (int) $value;
    }

    /**
     * Refuse to write outside the caller's transaction.
     *
     * Every public entry point starts here. The compare-and-set writes in this class, the fence the service
     * holds over the definition, and the revision rows written beside these ones only add up to one atomic
     * change while a transaction is open, so an absent one is a programming error, not a runtime condition.
     *
     * @return  void
     *
     * @throws  LogicException  When the connection has no active transaction.
     *
     * @since   2.0.0
     */
    private function assertTransaction(): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new LogicException('Business-record writes require an active application transaction.');
        }
    }

    /**
     * Translate a DBAL failure into the BusinessRecord vocabulary, so no driver exception leaves the class.
     *
     * A unique violation and a foreign-key violation each carry the relationship handle where one is known,
     * which is how the caller learns which link collided rather than only that something did. Every other
     * failure — a deadlock, a lock timeout, a lost connection, or an error this adapter cannot classify —
     * is reported as temporarily unavailable, keeping the driver exception as its previous.
     *
     * @param   DbalException  $exception     Driver failure caught around a statement.
     * @param   ?string        $relationship  Handle of the relationship being written, or null for a plain
     *          record write.
     *
     * @return  never
     *
     * @throws  BusinessRecordUniqueConflict  When the failure is a unique constraint violation.
     * @throws  BusinessRecordReferenceConflict  When the failure is a foreign key constraint violation.
     * @throws  BusinessRecordTemporarilyUnavailable  For every other driver failure, retryable or not.
     *
     * @since   2.0.0
     */
    private function map(DbalException $exception, ?string $relationship = null): never
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            throw new BusinessRecordUniqueConflict($relationship);
        }
        if ($exception instanceof ForeignKeyConstraintViolationException) {
            throw new BusinessRecordReferenceConflict($relationship);
        }
        if ($exception instanceof RetryableException) {
            throw new BusinessRecordTemporarilyUnavailable($exception);
        }
        throw new BusinessRecordTemporarilyUnavailable($exception);
    }
}
