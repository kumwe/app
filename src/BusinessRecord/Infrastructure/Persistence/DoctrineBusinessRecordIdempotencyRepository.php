<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use JsonException;
use Kumwe\App\BusinessRecord\Application\BusinessRecordIdempotencyRepository;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordIdempotencyRace;
use Kumwe\App\BusinessRecord\Application\RecordFingerprint;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotency;
use Kumwe\App\BusinessRecord\Domain\BusinessRecordIdempotencyState;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use LogicException;
use Throwable;

/**
 * At-most-once command ledger kept in the `business_command_idempotency` table over Doctrine DBAL.
 *
 * This adapter puts the port's guarantees onto SQL. The race between two commands presenting the same
 * key is settled by the unique index on `scope_digest`: `begin()` simply inserts and translates the
 * driver's unique-constraint violation into `BusinessRecordIdempotencyRace`, so no read-then-write
 * window exists. Every write asserts that the caller already has a transaction open, since the claim
 * and the mutation it guards have to commit or roll back as one. On the way back out nothing is
 * trusted: the stored state, JSON result and result checksum are re-proved, and any row that fails —
 * or that the entity itself rejects — is reported as `BusinessRecordIdempotencyConflict('corrupt')`
 * rather than replayed or leaked as a decoding error.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessRecordIdempotencyRepository implements BusinessRecordIdempotencyRepository
{
    /**
     * Wire the ledger to its connection, physical table naming, and result fingerprinting.
     *
     * @param  Connection         $database      DBAL connection whose open transaction every write joins.
     * @param  TableNames         $tables        Resolver for the prefixed `business_command_idempotency`
     *         table name.
     * @param  RecordFingerprint  $fingerprints  Keyed digest used to re-prove a result before it is
     *         stored and again before it is replayed.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private RecordFingerprint $fingerprints,
    ) {
    }

    /**
     * Read the entry stored under a scope digest and prove it before handing it back.
     *
     * This is the ledger's only read, and unlike the writes it does not insist on an open transaction.
     * The service calls it before claiming a key, so an entry found here sends the repeated command
     * down the replay path instead of letting it mutate a second time.
     *
     * @param   string  $scopeDigest  Digest naming one logical command; the table carries at most one row
     *          per value.
     *
     * @return  ?BusinessRecordIdempotency  The reconstituted entry, or null when no row carries this digest.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the stored row cannot be reconstituted, or a
     *          completed entry's result no longer matches its stored checksum.
     *
     * @since   2.0.0
     */
    public function find(string $scopeDigest): ?BusinessRecordIdempotency
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE scope_digest = ?',
            $this->tables->quoted('business_command_idempotency'),
        ), [$scopeDigest]);

        return $row === false ? null : $this->map($row);
    }

    /**
     * Claim the key by inserting the in-progress entry, letting the unique index settle any race.
     *
     * The insert is the whole arbitration: no read decides the winner, so two concurrent commands on one
     * scope digest cannot both proceed. It joins the caller's transaction, which is what makes an
     * abandoned command give its claim back along with the work it guarded. Lease, result, checksum and
     * completion columns are written null, and the identifier, timestamp and JSON columns are bound with
     * explicit Doctrine types, which the driver cannot infer from an object or a null.
     *
     * @param   BusinessRecordIdempotency  $entry  Claim to store, in its in-progress state.
     *
     * @return  void
     *
     * @throws  BusinessRecordIdempotencyRace  When the insert violates the unique constraint on the scope
     *          digest because a concurrent command claimed it first.
     * @throws  LogicException  When no transaction is open around the write.
     *
     * @since   2.0.0
     */
    public function begin(BusinessRecordIdempotency $entry): void
    {
        $this->assertTransaction();
        try {
            $this->database->insert($this->tables->raw('business_command_idempotency'), [
                'id' => $entry->id,
                'scope_digest' => $entry->scopeDigest,
                'site_identifier' => $entry->siteIdentifier,
                'organization_identifier' => $entry->organizationIdentifier,
                'actor_id' => $entry->actorId,
                'operation' => $entry->operation,
                'operation_id' => $entry->operationId,
                'request_fingerprint' => $entry->requestFingerprint,
                'authorization_fingerprint' => $entry->authorizationFingerprint,
                'state' => $entry->state->value,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'result' => null,
                'result_checksum' => null,
                'created_at' => $entry->createdAt,
                'completed_at' => null,
                'expires_at' => $entry->expiresAt,
            ], [
                'id' => Types::GUID,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'result' => Types::JSON,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'completed_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRecordIdempotencyRace();
        }
    }

    /**
     * Record the mutation result on a claim that is still in progress.
     *
     * Two guards make the stored replay trustworthy: the checksum is re-derived from the result before
     * anything is written, and the UPDATE matches on the in-progress state as well as the ID, so a
     * mislabelled result is refused, and a second completion of the same claim updates no row and is
     * reported as a conflict rather than overwriting what an earlier replay would already have returned.
     *
     * @param   string                $id              UUID of the entry `begin()` claimed.
     * @param   array<string, mixed>  $result          Outcome to store for a later replay to hand back.
     * @param   string                $resultChecksum  Digest the caller believes describes $result.
     * @param   DateTimeImmutable     $completedAt     Instant the guarded mutation finished.
     *
     * @return  void
     *
     * @throws  BusinessRecordIdempotencyConflict  When the checksum does not describe the result, or no
     *          in-progress row with this ID was updated.
     * @throws  LogicException  When no transaction is open around the write.
     *
     * @since   2.0.0
     */
    public function complete(
        string $id,
        array $result,
        string $resultChecksum,
        DateTimeImmutable $completedAt,
    ): void {
        $this->assertTransaction();
        if ($result === [] || array_is_list($result)) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        if (!hash_equals($resultChecksum, $this->fingerprints->digest($result))) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        $affected = $this->database->update($this->tables->raw('business_command_idempotency'), [
            'state' => BusinessRecordIdempotencyState::Completed->value,
            'result' => $result,
            'result_checksum' => $resultChecksum,
            'completed_at' => $completedAt,
        ], [
            'id' => $id,
            'state' => BusinessRecordIdempotencyState::InProgress->value,
        ], [
            'result' => Types::JSON,
            'completed_at' => Types::DATETIME_IMMUTABLE,
        ]);
        if ($affected !== 1) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
    }

    /**
     * Delete a bounded batch of expired entries, oldest expiry first.
     *
     * The batch is chosen by an ordered `SELECT id` and then removed by a single `DELETE ... IN`, so the
     * bound holds on platforms that will not accept a limit on a delete, and taking the oldest expiries
     * first is what lets repeated calls drain a backlog. A completed entry is collectable once it has
     * expired; an in-progress one only once it has expired and holds no live lease, which frees a key
     * whose command died mid-transaction without cancelling one that is still running.
     *
     * @param   DateTimeImmutable  $now    Instant expiry and lease liveness are measured against.
     * @param   int                $limit  Most entries to delete in this call, 1 to 1000.
     *
     * @return  int  Rows deleted; 0 when nothing was collectable, and below $limit when the table held
     *          fewer collectable entries than that.
     *
     * @throws  LogicException  When no transaction is open around the write, or $limit falls outside 1
     *          to 1000.
     *
     * @since   2.0.0
     */
    public function purgeExpired(DateTimeImmutable $now, int $limit): int
    {
        $this->assertTransaction();
        if ($limit < 1 || $limit > 1000) {
            throw new LogicException('The idempotency purge batch is outside its bounded range.');
        }
        $ids = $this->database->createQueryBuilder()
            ->select('id')
            ->from($this->tables->raw('business_command_idempotency'))
            ->where('expires_at <= :now')
            ->andWhere(
                '(state = :completed '
                . 'OR (state = :progress AND (lease_expires_at IS NULL OR lease_expires_at <= :now)))',
            )
            ->orderBy('expires_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('completed', BusinessRecordIdempotencyState::Completed->value)
            ->setParameter('progress', BusinessRecordIdempotencyState::InProgress->value)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();
        if ($ids === []) {
            return 0;
        }

        return (int) $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE id IN (?)',
            $this->tables->quoted('business_command_idempotency'),
        ), [$ids], [ArrayParameterType::STRING]);
    }

    /**
     * Reconstitute a stored row into an entry, refusing anything it cannot prove.
     *
     * The result column is accepted either as JSON text or as an already decoded value, since drivers
     * differ, and must reduce to a string-keyed map. A completed entry additionally has to carry both a
     * result and a checksum that still matches it, so a truncated or tampered row is reported rather
     * than replayed. Every failure, including one raised by the entity's own constructor, is folded into
     * the same corrupt conflict, so no decoding or date-parsing error escapes this adapter.
     *
     * @param   array<string, mixed>  $row  Associative row fetched from `business_command_idempotency`.
     *
     * @return  BusinessRecordIdempotency  The entry, proved against its own checksum where it claims to
     *          be complete.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the state is unknown, the result column is not
     *          valid JSON or not a string-keyed map, a completed entry's checksum does not match, or the
     *          entry rejects its own values.
     *
     * @since   2.0.0
     */
    private function map(array $row): BusinessRecordIdempotency
    {
        $state = BusinessRecordIdempotencyState::tryFrom($this->string($row, 'state'))
            ?? throw new BusinessRecordIdempotencyConflict('corrupt');
        $result = $row['result'] ?? null;
        if (is_string($result)) {
            try {
                $result = json_decode($result, true, 16, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new BusinessRecordIdempotencyConflict('corrupt');
            }
        }
        if ($result !== null && (!is_array($result) || array_is_list($result))) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
        /** @var non-empty-array<string, mixed>|null $result */

        $resultChecksum = $this->nullableString($row, 'result_checksum');
        if (
            $state === BusinessRecordIdempotencyState::Completed
            && ($result === null || $resultChecksum === null
                || !hash_equals($resultChecksum, $this->fingerprints->digest($result)))
        ) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        try {
            return new BusinessRecordIdempotency(
                $this->string($row, 'id'),
                $this->string($row, 'scope_digest'),
                $this->string($row, 'site_identifier'),
                $this->nullableString($row, 'organization_identifier'),
                $this->string($row, 'actor_id'),
                $this->string($row, 'operation'),
                $this->string($row, 'operation_id'),
                $this->string($row, 'request_fingerprint'),
                $this->string($row, 'authorization_fingerprint'),
                $state,
                $result,
                $resultChecksum,
                $this->date($row['created_at'] ?? null),
                $this->nullableDate($row['completed_at'] ?? null),
                $this->date($row['expires_at'] ?? null),
            );
        } catch (BusinessRecordIdempotencyConflict $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }
    }

    /**
     * Read a column that every valid entry carries as a string.
     *
     * @param   array<string, mixed>  $row  Associative row from the ledger table.
     * @param   string                $key  Column name to read.
     *
     * @return  string  The stored value.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the column is absent or holds a non-string.
     *
     * @since   2.0.0
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        return $value;
    }

    /**
     * Read a column that a valid entry carries as a string or leaves null.
     *
     * @param   array<string, mixed>  $row  Associative row from the ledger table.
     * @param   string                $key  Column name to read.
     *
     * @return  ?string  The stored value, or null when the column is absent or stored null.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the column holds something other than a string.
     *
     * @since   2.0.0
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new BusinessRecordIdempotencyConflict('corrupt');
        }

        return $value;
    }

    /**
     * Normalise an optional timestamp column, passing a null column straight through.
     *
     * @param   mixed  $value  Raw column value, as the driver returned it.
     *
     * @return  ?DateTimeImmutable  The instant, or null when the column was null.
     *
     * @throws  BusinessRecordIdempotencyConflict  When a non-null column is neither a date-time nor a
     *          string.
     *
     * @since   2.0.0
     */
    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date($value);
    }

    /**
     * Normalise a timestamp column into an immutable instant.
     *
     * Drivers hand these back either already converted or as a string, so both are accepted: an
     * immutable is returned untouched, another `DateTimeInterface` is re-read from its ATOM form, and a
     * bare string with no offset of its own is read as UTC.
     *
     * @param   mixed  $value  Raw column value, as the driver returned it.
     *
     * @return  DateTimeImmutable  The instant the column represents.
     *
     * @throws  BusinessRecordIdempotencyConflict  When the column is neither a date-time nor a string.
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
        throw new BusinessRecordIdempotencyConflict('corrupt');
    }

    /**
     * Refuse a ledger write that is not already inside the caller's transaction.
     *
     * The claim and the mutation it guards have to commit or roll back together, so this is checked
     * before every write rather than left to the caller to remember. Failing it is a programming error
     * in the calling service, not a condition an operator can act on, which is why it is a
     * `LogicException` and not a record exception.
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
            throw new LogicException('Business-record idempotency writes require an active application transaction.');
        }
    }
}
