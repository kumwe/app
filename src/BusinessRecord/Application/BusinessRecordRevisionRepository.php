<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\CMS\BusinessRecord\Domain\RecordScope;
use Kumwe\CMS\BusinessSecurity\Application\BusinessRecordAccessPlan;

/**
 * Port for the append-only log that keeps every past state of a business record.
 *
 * `BusinessRecordService` appends one entry per mutation of a definition that has revisions enabled,
 * inside the same transaction as the write itself, so the log and the row it describes move together
 * or not at all. Reading it back gives bounded, newest-first windows over one record's history, and
 * two lookups exist because the row a history request names may already be gone —
 * `history()` addresses the log by the record's internal storage key, while
 * `historyByIdentityDigest()` addresses it by the keyed digest of the record's business identity,
 * which is the only handle that survives the row. Implementations are expected to re-derive
 * `BusinessRecordRevision::checksum()` for every row they read and refuse an entry whose stored digest
 * disagrees, so nothing downstream has to trust the table it came from.
 *
 * @since  2.0.0
 */
interface BusinessRecordRevisionRepository
{
    /**
     * Append one already-assembled entry to a record's history.
     *
     * An entry is final once written and the port offers no way to amend or remove it, so the caller
     * assembles the whole revision before calling. Implementations are expected to write inside the
     * caller's open transaction, which is what makes a rolled-back mutation take its revision with it.
     *
     * @param   BusinessRecordRevision  $revision  History entry to store, describing the state the
     *          mutation left the record in.
     *
     * @return  void
     *
     * @throws  \LogicException  When no application transaction is open for the append to join.
     *
     * @since   2.0.0
     */
    public function append(BusinessRecordRevision $revision): void;

    /**
     * Read one window of a record's history, addressed by the record's internal storage key.
     *
     * This is the lookup used while the record's row still resolves, and it is not scoped by site or
     * organization because the storage key already names exactly one row. A caller that needs to know
     * whether older entries remain asks for one row more than it intends to return and compares.
     *
     * @param   string                         $definitionId  UUID of the entity type whose log is read.
     * @param   string                         $recordKey     Internal storage UUID of the record, not its
     *          caller-facing identity.
     * @param   int                            $limit         Most rows to return; implementations reject an
     *          unbounded or oversized window.
     * @param   ?BusinessRecordRevisionCursor  $before        Exclusive upper bound in the log's ordering
     *          key, taken from the oldest entry of the previous page; null starts at the newest entry.
     *
     * @return  list<BusinessRecordRevision>  Entries ordered by record version, then revision number, then
     *          record key, all descending; empty when the record has no history in range.
     *
     * @throws  \InvalidArgumentException  When the requested window falls outside the bound the
     *          implementation accepts.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When a
     *          stored row is malformed, or its checksum disagrees with the entry rebuilt from it.
     *
     * @since   2.0.0
     */
    public function history(
        string $definitionId,
        string $recordKey,
        int $limit,
        ?BusinessRecordRevisionCursor $before = null,
    ): array;

    /**
     * Read one window of a record's history, addressed by the digest of its business identity.
     *
     * This is the lookup that still answers once the record's row is gone, since the log stores the
     * identity only as a digest and never in the clear. Having no row to scope against, it takes the
     * site and organization the record belonged to as arguments instead. The digest identifies a
     * record within that scope but is not proof of one, which is why the ordering key ends in the record
     * key: two generations of one reused identity number their versions independently, so nothing above
     * that component is unique. A caller that requires a single subject settles that with
     * `recordKeysForIdentityDigest()` before it asks for a page.
     *
     * The immutable row predicate is compiled against the stored revision snapshot and executes in the
     * same statement as the identity digest, scope, ordering and limit. A denied snapshot therefore never
     * leaves persistence and cannot trigger checksum mapping or a definition-version follow-up lookup.
     *
     * @param   ResolvedBusinessDefinition     $resolved              Installed definition whose current
     *          immutable record policy is applied to the stored snapshot.
     * @param   RecordScope                    $scope                 Site and organization the revision must
     *          belong to.
     * @param   BusinessRecordAccessPlan       $access                Default-deny row decision compiled into
     *          the revision query before ordering and limiting.
     * @param   string                         $recordIdentityDigest  Keyed 64-character digest of the record's
     *          identity, as `RecordFingerprint::digest()` produces it.
     * @param   int                            $limit                 Most rows to return; implementations reject
     *          an unbounded or oversized window.
     * @param   ?BusinessRecordRevisionCursor  $before                Exclusive upper bound in the log's ordering
     *          key from the oldest entry of the previous page; null starts at the newest entry.
     *
     * @return  list<BusinessRecordRevision>  Entries ordered by record version, then revision number, then
     *          record key, all descending; empty when no history matches the digest in this scope.
     *
     * @throws  \InvalidArgumentException  When the requested window falls outside the bound the
     *          implementation accepts, or the digest is not 64 hexadecimal characters.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When a
     *          stored row is malformed, or its checksum disagrees with the entry rebuilt from it.
     *
     * @since   2.0.0
     */
    public function historyByIdentityDigest(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        string $recordIdentityDigest,
        int $limit,
        ?BusinessRecordRevisionCursor $before = null,
    ): array;

    /**
     * List the distinct internal record keys one identity digest covers in this scope.
     *
     * A public identity can be reused after the record carrying it is hard-deleted, and the revision log
     * outlives the row, so one digest may name more than one generation. This answers that question over
     * the whole scope rather than over a page, which is the difference that matters: a page-local check
     * cannot see a generation the requested window happened to exclude, so it would hand back history
     * that silently belongs to one subject while another exists. Callers bound the answer to two, because
     * a caller only needs to know whether the digest resolves to exactly one generation.
     *
     * @param   ResolvedBusinessDefinition  $resolved              Installed definition whose immutable
     *          record policy is applied to the stored snapshot.
     * @param   RecordScope                 $scope                 Site and organization the revision must
     *          belong to.
     * @param   BusinessRecordAccessPlan    $access                Default-deny row decision compiled into
     *          the probe, so a denied revision names no generation.
     * @param   string                      $recordIdentityDigest  Keyed 64-character digest of the record's
     *          identity, as `RecordFingerprint::digest()` produces it.
     * @param   int                         $limit                 Most distinct keys to return; two is
     *          enough to separate one generation from several.
     *
     * @return  list<string>  Distinct record keys in ascending order; empty when the digest names no
     *          readable revision in this scope.
     *
     * @throws  \InvalidArgumentException  When the bound is outside what the implementation accepts, or
     *          the digest is not 64 hexadecimal characters.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When a
     *          stored key is not a string.
     *
     * @since   2.0.0
     */
    public function recordKeysForIdentityDigest(
        ResolvedBusinessDefinition $resolved,
        RecordScope $scope,
        BusinessRecordAccessPlan $access,
        string $recordIdentityDigest,
        int $limit,
    ): array;
}
