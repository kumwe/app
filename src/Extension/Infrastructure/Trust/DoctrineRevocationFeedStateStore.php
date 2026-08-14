<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Exception;
use Kumwe\CMS\Extension\Application\Trust\RevocationFeedState;
use Kumwe\CMS\Extension\Application\Trust\RevocationFeedStateStore;
use Kumwe\CMS\Extension\Application\Trust\RevocationList;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Stores one revocation-feed position row per configured origin.
 *
 * The primary key is the SHA-256 of the origin rather than the origin itself, so a long URL cannot
 * collide with the portable identifier length limit and so re-pointing an installation at a new origin
 * starts a fresh sequence instead of inheriting the old one — which is the safe direction, since a new
 * issuer's list 1 must not be refused for being older than the previous issuer's list 9.
 *
 * Every read is guarded by a table-existence check, so an installation that has not yet taken the
 * supply-chain migration reports an unconfigured feed rather than failing a page render.
 *
 * @since  2.0.0
 */
final readonly class DoctrineRevocationFeedStateStore implements RevocationFeedStateStore
{
    /**
     * Bind the store to its connection and prefixed table map.
     *
     * @param  Connection  $database  Connection every feed-state read and write goes through.
     * @param  TableNames  $tables    Prefix-aware resolver for the feed-state table.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Read the recorded state of one origin.
     *
     * @param   string  $origin           Configured feed origin.
     * @param   int     $maxStaleSeconds  Freshness budget stamped onto the returned state.
     *
     * @return  RevocationFeedState  Recorded state, or one carrying nothing applied.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    public function read(string $origin, int $maxStaleSeconds): RevocationFeedState
    {
        $row = $this->row($origin);
        if ($row === null) {
            return new RevocationFeedState($origin, null, 0, null, 0, null, null, null, null, 0, $maxStaleSeconds);
        }

        return new RevocationFeedState(
            $origin,
            $this->stringOrNull($row['issuer'] ?? null),
            $this->counter($row['applied_sequence'] ?? null),
            $this->stringOrNull($row['document_sha256'] ?? null),
            $this->counter($row['revoked_key_count'] ?? null),
            $this->instant($row['last_success_at'] ?? null),
            $this->instant($row['last_attempt_at'] ?? null),
            $this->instant($row['last_failure_at'] ?? null),
            $this->stringOrNull($row['last_failure_reason'] ?? null),
            $this->counter($row['consecutive_failures'] ?? null),
            $maxStaleSeconds,
        );
    }

    /**
     * Record that a list verified, was newer than the stored sequence, and was applied.
     *
     * @param   string             $origin           Configured feed origin.
     * @param   RevocationList     $list             The list that was applied.
     * @param   DateTimeImmutable  $at               Instant recorded as the success.
     * @param   int                $revokedKeyCount  Keys actually withdrawn from the trust store.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the write.
     *
     * @since   2.0.0
     */
    public function recordSuccess(
        string $origin,
        RevocationList $list,
        DateTimeImmutable $at,
        int $revokedKeyCount,
    ): void {
        $this->write($origin, [
            'issuer' => $list->issuer,
            'applied_sequence' => $list->sequence,
            'document_sha256' => $list->documentSha256,
            'revoked_key_count' => $revokedKeyCount,
            'last_success_at' => $at,
            'last_attempt_at' => $at,
            'consecutive_failures' => 0,
        ], [
            'last_success_at' => Types::DATETIME_IMMUTABLE,
            'last_attempt_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Record that a fetch was attempted and did not result in an applied list.
     *
     * @param   string             $origin  Configured feed origin.
     * @param   DateTimeImmutable  $at      Instant recorded as the failure.
     * @param   string             $reason  Why the attempt did not apply a list.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the write.
     *
     * @since   2.0.0
     */
    public function recordFailure(string $origin, DateTimeImmutable $at, string $reason): void
    {
        $existing = $this->row($origin);
        $failures = $existing === null ? 0 : $this->counter($existing['consecutive_failures'] ?? null);
        $this->write($origin, [
            'last_attempt_at' => $at,
            'last_failure_at' => $at,
            'last_failure_reason' => substr($reason, 0, 500),
            'consecutive_failures' => $failures + 1,
        ], [
            'last_attempt_at' => Types::DATETIME_IMMUTABLE,
            'last_failure_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    /**
     * Insert or update the row for one origin.
     *
     * @param   string                 $origin  Configured feed origin.
     * @param   array<string, mixed>   $values  Columns to write, excluding the identity columns.
     * @param   array<string, string>  $types   DBAL types for the columns that need them.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the write.
     *
     * @since   2.0.0
     */
    private function write(string $origin, array $values, array $types): void
    {
        $table = $this->tables->raw('extension_revocation_feed_state');
        if (!$this->database->createSchemaManager()->tablesExist([$table])) {
            return;
        }
        $digest = hash('sha256', $origin);
        if ($this->row($origin) === null) {
            $this->database->insert($table, [
                'origin_digest' => $digest,
                'origin' => substr($origin, 0, 500),
                'applied_sequence' => 0,
                'revoked_key_count' => 0,
                'consecutive_failures' => 0,
                ...$values,
            ], $types);

            return;
        }
        $this->database->update($table, $values, ['origin_digest' => $digest], $types);
    }

    /**
     * Fetch the stored row for one origin.
     *
     * @param   string  $origin  Configured feed origin.
     *
     * @return  ?array<string, mixed>  The row, or null when the origin or the table is absent.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    private function row(string $origin): ?array
    {
        $table = $this->tables->raw('extension_revocation_feed_state');
        if (!$this->database->createSchemaManager()->tablesExist([$table])) {
            return null;
        }
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE origin_digest = ?',
            $this->tables->quoted('extension_revocation_feed_state'),
        ), [hash('sha256', $origin)]);

        return $row === false ? null : $row;
    }

    /**
     * Narrow a stored column to a non-empty string.
     *
     * @param   mixed  $value  Column value as the driver returned it.
     *
     * @return  ?string  The string, or null when the column is absent or empty.
     *
     * @since   2.0.0
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Narrow a stored counter column to a non-negative integer.
     *
     * Drivers disagree about whether a `BIGINT` comes back as an integer or as a decimal string, so both
     * are accepted and anything else reads as zero — which for a sequence is the safe direction, since it
     * makes the next fetched list look newer rather than older.
     *
     * @param   mixed  $value  Column value as the driver returned it.
     *
     * @return  int  The counter, or zero when the column is absent or unreadable.
     *
     * @since   2.0.0
     */
    private function counter(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Turn a stored timestamp column into an instant.
     *
     * Parsing goes through the general constructor rather than a fixed format on purpose: the four
     * supported platforms disagree about whether a timestamp column comes back with a microsecond part
     * or a zone suffix, and a state row that failed to parse would read as "never fetched", which would
     * report a healthy feed as permanently stale.
     *
     * @param   mixed  $value  Column value as the driver returned it.
     *
     * @return  ?DateTimeImmutable  The instant, or null when the column is empty or unparseable.
     *
     * @since   2.0.0
     */
    private function instant(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
