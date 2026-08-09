<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;

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
     * @param   string  $definitionId   UUID of the entity type whose log is read.
     * @param   string  $recordKey      Internal storage UUID of the record, not its caller-facing
     *          identity.
     * @param   int     $limit          Most rows to return; implementations reject an unbounded or
     *          oversized window.
     * @param   ?int    $beforeVersion  Exclusive upper bound on record version, taken from the oldest
     *          entry of the previous page; null starts at the newest entry.
     *
     * @return  list<BusinessRecordRevision>  Entries ordered by record version and then revision number,
     *          both descending; empty when the record has no history in range.
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
        ?int $beforeVersion = null,
    ): array;

    /**
     * Read one window of a record's history, addressed by the digest of its business identity.
     *
     * This is the lookup that still answers once the record's row is gone, since the log stores the
     * identity only as a digest and never in the clear. Having no row to scope against, it takes the
     * site and organization the record belonged to as arguments instead. The digest identifies a
     * record within that scope but is not proof of one: a caller that requires a single subject checks
     * that the returned entries all carry the same record key.
     *
     * @param   string   $definitionId            UUID of the entity type whose log is read.
     * @param   string   $siteIdentifier          Site the record belonged to.
     * @param   ?string  $organizationIdentifier  Organization branch within that site, or null to match
     *          the entries written site-wide.
     * @param   string   $recordIdentityDigest    Keyed 64-character digest of the record's caller-facing
     *          identity, as `RecordFingerprint::digest()` produces it.
     * @param   int      $limit                   Most rows to return; implementations reject an
     *          unbounded or oversized window.
     * @param   ?int     $beforeVersion           Exclusive upper bound on record version, taken from the
     *          oldest entry of the previous page; null starts at the newest entry.
     *
     * @return  list<BusinessRecordRevision>  Entries ordered by record version and then revision number,
     *          both descending; empty when no history matches the digest in this scope.
     *
     * @throws  \InvalidArgumentException  When the requested window falls outside the bound the
     *          implementation accepts, or the digest is not 64 hexadecimal characters.
     * @throws  \Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable  When a
     *          stored row is malformed, or its checksum disagrees with the entry rebuilt from it.
     *
     * @since   2.0.0
     */
    public function historyByIdentityDigest(
        string $definitionId,
        string $siteIdentifier,
        ?string $organizationIdentifier,
        string $recordIdentityDigest,
        int $limit,
        ?int $beforeVersion = null,
    ): array;
}
