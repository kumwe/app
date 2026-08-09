<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use JsonException;
use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordRevisionRepository;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordSchemaUnavailable;
use Kumwe\CMS\BusinessRecord\Domain\BusinessRecordRevision;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use LogicException;

/**
 * Append-only revision log for business records, held in `business_record_revisions` over Doctrine DBAL.
 *
 * This adapter puts the port's guarantees onto SQL. An append asserts that the caller already holds an
 * open transaction and then joins it, so a revision cannot outlive a mutation that rolled back, and the
 * identifier, integer, JSON and timestamp columns carry explicit Doctrine types the driver cannot infer
 * from an object or an array. Reads come back as bounded windows ordered by record version and then
 * revision number, both descending; the row limit is range-checked before it is interpolated into the
 * statement, while every value the caller supplies is bound as a parameter. On the way back out nothing
 * is trusted: each row is rebuilt into a `BusinessRecordRevision`, its checksum re-derived and compared
 * with `hash_equals()`, and a column that is absent, wrongly typed, malformed as JSON or no longer
 * agrees with that digest is reported as `BusinessRecordSchemaUnavailable` rather than handed onward.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessRecordRevisionRepository implements BusinessRecordRevisionRepository
{
    /**
     * Wire the log to its connection and to physical table naming.
     *
     * @param  Connection  $database  DBAL connection whose open transaction every append joins, and that
     *         every history window is read from.
     * @param  TableNames  $tables    Resolver for the prefixed `business_record_revisions` table name.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Insert one assembled revision as a new row in the log.
     *
     * The insert joins the caller's transaction rather than opening one, which is what makes a rolled-back
     * mutation take its revision with it. The snapshot is written in the guard's canonical spelling, and
     * the checksum column is taken from the revision rather than computed here, so it is the same digest
     * `map()` re-derives when the row is read back. Nothing here amends or replaces an existing row: the
     * unique index on definition, record and revision number is what stops a revision number landing twice.
     *
     * @param   BusinessRecordRevision  $revision  History entry to store, already validated and
     *          canonicalised by its own constructor.
     *
     * @return  void
     *
     * @throws  LogicException  When no application transaction is open for the insert to join.
     *
     * @since   2.0.0
     */
    public function append(BusinessRecordRevision $revision): void
    {
        $this->assertTransaction();
        $this->database->insert($this->tables->raw('business_record_revisions'), [
            'id' => $revision->revisionId,
            'definition_id' => $revision->definitionId,
            'definition_version' => $revision->definitionVersion,
            'site_identifier' => $revision->siteIdentifier,
            'organization_identifier' => $revision->organizationIdentifier,
            'record_id' => $revision->recordKey,
            'record_identity_digest' => $revision->recordIdentityDigest,
            'record_version' => $revision->recordVersion,
            'revision_number' => $revision->revisionNumber,
            'action' => $revision->operation,
            'actor_id' => $revision->actorId,
            'snapshot' => RecordValueGuard::canonical($revision->snapshot()),
            'checksum' => $revision->checksum(),
            'changed_fields' => $revision->changedFields(),
            'created_at' => $revision->occurredAt,
        ], [
            'id' => Types::GUID,
            'definition_id' => Types::GUID,
            'definition_version' => Types::INTEGER,
            'record_version' => Types::INTEGER,
            'revision_number' => Types::INTEGER,
            'snapshot' => Types::JSON,
            'changed_fields' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Read one bounded, newest-first window of a record's history, addressed by its storage key.
     *
     * The window is not scoped by site or organization, because the record key already names exactly one
     * record. The limit is checked against the 1-to-201 bound before it reaches the statement it is
     * interpolated into — 201 leaves room for the one lookahead row `BusinessRecordService` adds to its
     * page size to learn whether a further page exists — while the definition, record and version values
     * travel as bound parameters.
     *
     * @param   string  $definitionId   UUID of the entity type whose log is read.
     * @param   string  $recordKey      Internal storage UUID of the record, matched against `record_id`.
     * @param   int     $limit          Most rows to return; 1 to 201.
     * @param   ?int    $beforeVersion  Exclusive upper bound on `record_version`, taken from the oldest
     *          entry of the previous page; null starts at the newest entry.
     *
     * @return  list<BusinessRecordRevision>  Entries ordered by record version and then revision number,
     *          both descending; empty when the record has no history in range.
     *
     * @throws  InvalidArgumentException  When $limit falls outside 1 to 201, or a stored row is well typed
     *          but holds values the revision itself rejects.
     * @throws  BusinessRecordSchemaUnavailable  When a stored row is malformed, or its checksum disagrees
     *          with the entry rebuilt from it.
     *
     * @since   2.0.0
     */
    public function history(
        string $definitionId,
        string $recordKey,
        int $limit,
        ?int $beforeVersion = null,
    ): array {
        if ($limit < 1 || $limit > 201) {
            throw new InvalidArgumentException('A revision repository window must contain 1 to 201 rows.');
        }
        $parameters = [$definitionId, $recordKey];
        $types = [Types::GUID, Types::GUID];
        $version = '';
        if ($beforeVersion !== null) {
            $version = ' AND record_version < ?';
            $parameters[] = $beforeVersion;
            $types[] = Types::INTEGER;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE definition_id = ? AND record_id = ?%s '
            . 'ORDER BY record_version DESC, revision_number DESC LIMIT %d',
            $this->tables->quoted('business_record_revisions'),
            $version,
            $limit,
        ), $parameters, $types);

        return array_map($this->map(...), $rows);
    }

    /**
     * Read one bounded, newest-first window of history, addressed by the digest of a record's identity.
     *
     * This is the lookup that still answers once the record's row is gone, so it takes the site and
     * organization to scope against rather than reading them off a row. A null organization is compiled to
     * `organization_identifier IS NULL`, which matches the entries written site-wide rather than every
     * organization, and the digest must be 64 lowercase hexadecimal characters before it reaches the
     * statement. The digest is a scope, not proof of a single subject: a caller that needs one record
     * checks that the returned entries all carry the same record key.
     *
     * @param   string   $definitionId            UUID of the entity type whose log is read.
     * @param   string   $siteIdentifier          Site the record belonged to.
     * @param   ?string  $organizationIdentifier  Organization branch within that site, or null to match the
     *          entries written site-wide.
     * @param   string   $recordIdentityDigest    Keyed 64-character digest of the record's business
     *          identity, which the log stores in place of that identity.
     * @param   int      $limit                   Most rows to return; 1 to 201.
     * @param   ?int     $beforeVersion           Exclusive upper bound on `record_version`, taken from the
     *          oldest entry of the previous page; null starts at the newest entry.
     *
     * @return  list<BusinessRecordRevision>  Entries ordered by record version and then revision number,
     *          both descending; empty when no history matches the digest in this scope.
     *
     * @throws  InvalidArgumentException  When $limit falls outside 1 to 201, the digest is not 64
     *          hexadecimal characters, or a stored row holds values the revision itself rejects.
     * @throws  BusinessRecordSchemaUnavailable  When a stored row is malformed, or its checksum disagrees
     *          with the entry rebuilt from it.
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
    ): array {
        if ($limit < 1 || $limit > 201 || preg_match('/^[a-f0-9]{64}$/D', $recordIdentityDigest) !== 1) {
            throw new InvalidArgumentException('A revision identity window is invalid.');
        }
        $where = ['definition_id = ?', 'site_identifier = ?', 'record_identity_digest = ?'];
        $parameters = [$definitionId, $siteIdentifier, $recordIdentityDigest];
        $types = [Types::GUID, Types::STRING, Types::STRING];
        if ($organizationIdentifier === null) {
            $where[] = 'organization_identifier IS NULL';
        } else {
            $where[] = 'organization_identifier = ?';
            $parameters[] = $organizationIdentifier;
            $types[] = Types::STRING;
        }
        if ($beforeVersion !== null) {
            $where[] = 'record_version < ?';
            $parameters[] = $beforeVersion;
            $types[] = Types::INTEGER;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE %s ORDER BY record_version DESC, revision_number DESC LIMIT %d',
            $this->tables->quoted('business_record_revisions'),
            implode(' AND ', $where),
            $limit,
        ), $parameters, $types);

        return array_map($this->map(...), $rows);
    }

    /**
     * Rebuild one stored row into a revision and prove it against its own checksum.
     *
     * The digest is re-derived from the reconstituted entry and compared with the stored one using
     * `hash_equals()`, so a row edited outside the application is refused instead of being handed on.
     * Unlike the column readers, the revision's constructor is left to raise its own validation failures,
     * so a row whose columns are well typed but not well formed — an identifier that is not a canonical
     * UUID, a version below 1 — surfaces as an `InvalidArgumentException` rather than a schema failure.
     *
     * @param   array<string, mixed>  $row  Associative row fetched from `business_record_revisions`.
     *
     * @return  BusinessRecordRevision  The reconstituted entry, proved against its stored checksum.
     *
     * @throws  BusinessRecordSchemaUnavailable  When a column is absent or wrongly typed, a JSON column
     *          does not hold the expected shape, or the stored checksum disagrees with the rebuilt entry.
     * @throws  InvalidArgumentException  When the revision refuses the stored values, or its snapshot
     *          cannot be encoded for checksumming.
     *
     * @since   2.0.0
     */
    private function map(array $row): BusinessRecordRevision
    {
        $snapshot = $this->jsonObject($row['snapshot'] ?? null);
        $changed = $this->jsonList($row['changed_fields'] ?? null);
        $revision = new BusinessRecordRevision(
            $this->string($row, 'id'),
            $this->string($row, 'definition_id'),
            $this->integer($row, 'definition_version'),
            $this->string($row, 'site_identifier'),
            $this->nullableString($row, 'organization_identifier'),
            $this->string($row, 'record_id'),
            $this->string($row, 'record_identity_digest'),
            $this->integer($row, 'record_version'),
            $this->integer($row, 'revision_number'),
            $this->string($row, 'action'),
            $snapshot,
            $changed,
            $this->string($row, 'actor_id'),
            $this->date($row['created_at'] ?? null),
        );
        if (!hash_equals($this->string($row, 'checksum'), $revision->checksum())) {
            throw new BusinessRecordSchemaUnavailable('A business-record revision failed integrity verification.');
        }

        return $revision;
    }

    /**
     * Decode a stored snapshot column into the handle-keyed map the revision expects.
     *
     * An empty array is accepted, since a snapshot holding no fields encodes to `[]` and cannot be told
     * apart from an empty list once it comes back; any other list-shaped payload is refused.
     *
     * @param   mixed  $value  Raw `snapshot` column value, as JSON text or as the driver already decoded it.
     *
     * @return  array<string, mixed>  Field values as at the revision, keyed by field handle.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is not valid JSON, or decodes to something
     *          other than a string-keyed map.
     *
     * @since   2.0.0
     */
    private function jsonObject(mixed $value): array
    {
        $decoded = $this->json($value);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision snapshot is malformed.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Decode a stored changed-field column into a list of field handles.
     *
     * Each member is checked individually rather than the decoded list being trusted in bulk, because the
     * revision takes this as a `list<string>` and matches every handle against a string pattern.
     *
     * @param   mixed  $value  Raw `changed_fields` column value, as JSON text or as the driver already
     *          decoded it.
     *
     * @return  list<string>  Handles the mutation touched, in the order they were stored.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is not valid JSON, does not decode to a
     *          list, or holds a member that is not a string.
     *
     * @since   2.0.0
     */
    private function jsonList(mixed $value): array
    {
        $decoded = $this->json($value);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new BusinessRecordSchemaUnavailable('Stored revision changed fields are malformed.');
        }
        $result = [];
        foreach ($decoded as $item) {
            if (!is_string($item)) {
                throw new BusinessRecordSchemaUnavailable('Stored revision changed fields contain a non-string.');
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Decode a JSON column, tolerating a driver that decoded it already.
     *
     * A non-string value is passed straight through, because platforms differ over whether a JSON column
     * arrives as text or as a decoded structure. Objects are decoded to associative arrays and large
     * integers to strings, so a snapshot value keeps its digits instead of being narrowed to a float that
     * the record layer would refuse. The decoder's `JsonException` is caught and reported as a schema
     * failure, so no driver-level decoding error escapes this adapter.
     *
     * @param   mixed  $value  Raw JSON column value, as the driver returned it.
     *
     * @return  mixed  The decoded value, or $value unchanged when it was not a string.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the text is not valid JSON, or nests deeper than the
     *          16 levels allowed.
     *
     * @since   2.0.0
     */
    private function json(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        try {
            return json_decode($value, true, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BusinessRecordSchemaUnavailable('Stored revision JSON is invalid.');
        }
    }

    /**
     * Read a column that every valid revision row carries as a string.
     *
     * @param   array<string, mixed>  $row  Associative row fetched from `business_record_revisions`.
     * @param   string                $key  Column name to read.
     *
     * @return  string  The stored value.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is absent or holds a non-string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision string is invalid.');
        }

        return $value;
    }

    /**
     * Read a column that a valid revision row carries as a string or leaves null.
     *
     * @param   array<string, mixed>  $row  Associative row fetched from `business_record_revisions`.
     * @param   string                $key  Column name to read.
     *
     * @return  ?string  The stored value, or null when the column is absent or was stored null.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column holds something other than a string.
     *
     * @since   2.0.0
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision value is invalid.');
        }

        return $value;
    }

    /**
     * Read a column that every valid revision row carries as a whole number.
     *
     * Drivers differ over whether an integer column arrives typed or as decimal text, so an unsigned run
     * of digits is accepted and cast. Nothing looser is: a signed or fractional string is refused rather
     * than silently truncated, which keeps a corrupted version or revision number from being read as one
     * the log never wrote.
     *
     * @param   array<string, mixed>  $row  Associative row fetched from `business_record_revisions`.
     * @param   string                $key  Column name to read.
     *
     * @return  int  The stored value.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is absent, or holds neither an integer nor
     *          a run of digits.
     *
     * @since   2.0.0
     */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new BusinessRecordSchemaUnavailable('A stored business-record revision integer is invalid.');
        }

        return (int) $value;
    }

    /**
     * Normalise the stored timestamp column into an immutable instant.
     *
     * Drivers hand these back either already converted or as a string, so both are accepted: an immutable
     * is returned untouched, another `DateTimeInterface` is re-read from its ATOM form so the offset it
     * carries survives, and a bare string that states no offset of its own is read as UTC, which is the
     * zone revisions are written in.
     *
     * @param   mixed  $value  Raw `created_at` column value, as the driver returned it.
     *
     * @return  DateTimeImmutable  The instant the mutation was applied.
     *
     * @throws  BusinessRecordSchemaUnavailable  When the column is neither a date-time nor a string.
     * @throws  \DateMalformedStringException  When the string spells no readable date and time.
     *
     * @since   2.0.0
     */
    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface || is_string($value)) {
            return new DateTimeImmutable(
                $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value,
                new DateTimeZone('UTC'),
            );
        }
        throw new BusinessRecordSchemaUnavailable('A stored business-record revision timestamp is invalid.');
    }

    /**
     * Refuse an append that is not already inside the caller's transaction.
     *
     * The revision and the mutation it describes have to commit or roll back as one, so this is checked
     * before the insert rather than left to the calling service to remember. Failing it is a programming
     * error in that caller and not a condition an operator can act on, which is why it raises a
     * `LogicException` rather than a record exception.
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
            throw new LogicException('Business-record revisions require an active application transaction.');
        }
    }
}
