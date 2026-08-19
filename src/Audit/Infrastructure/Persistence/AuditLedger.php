<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Shared read and validation helpers for the `audit_anchors` ledger.
 *
 * The anchor writer, the retention service and the verifier all read the same ledger; concentrating
 * the row hydration and the storage-shape validation here keeps their views of a ledger row —
 * position, digest and count value shapes included — byte-identical, which matters when the values
 * feed digest recomputation.
 *
 * @since  2.0.0
 */
final class AuditLedger
{
    /**
     * Fetch the newest ledger entry, the one a new entry chains to.
     *
     * @param   Connection  $database  Connection the ledger lives on.
     * @param   TableNames  $tables    Resolver for prefixed physical table names.
     *
     * @return  ?AuditLedgerEntry  The highest-sequence entry, or null for an empty ledger.
     *
     * @throws  RuntimeException  When the stored row is malformed.
     *
     * @since   2.0.0
     */
    public static function tail(Connection $database, TableNames $tables): ?AuditLedgerEntry
    {
        $row = $database->fetchAssociative(sprintf(
            'SELECT id, sequence, kind, from_position, to_position, row_count, rolling_digest, '
            . 'previous_digest, digest, archive_sha256, created_at FROM %s ORDER BY sequence DESC LIMIT 1',
            $tables->quoted('audit_anchors'),
        ));

        return $row === false ? null : self::entry($row);
    }

    /**
     * Report how far one kind of ledger entry has covered the trail.
     *
     * Anchors and prune marks advance independently: anchors seal contiguous ranges from the trail's
     * start, and a prune mark archives a prefix of what anchors have already sealed. Each therefore has
     * its own boundary, and neither may be derived from the ledger tail, which is simply the newest row
     * of either kind.
     *
     * @param   Connection  $database  Connection the ledger lives on.
     * @param   TableNames  $tables    Resolver for prefixed physical table names.
     * @param   string      $kind      Entry kind to measure: `anchor` or `prune`.
     *
     * @return  int  Highest audit position that kind covers, or zero when it covers nothing.
     *
     * @throws  RuntimeException  When the stored boundary is malformed.
     *
     * @since   2.0.0
     */
    public static function boundary(Connection $database, TableNames $tables, string $kind): int
    {
        return self::optionalPosition($database->fetchOne(sprintf(
            'SELECT MAX(to_position) FROM %s WHERE kind = ?',
            $tables->quoted('audit_anchors'),
        ), [$kind]), 'ledger coverage boundary') ?? 0;
    }

    /**
     * Fetch the whole ledger in chain order for a verification walk.
     *
     * @param   Connection  $database  Connection the ledger lives on.
     * @param   TableNames  $tables    Resolver for prefixed physical table names.
     *
     * @return  list<AuditLedgerEntry>  Every ledger entry, ascending by sequence.
     *
     * @throws  RuntimeException  When a stored row is malformed.
     *
     * @since   2.0.0
     */
    public static function all(Connection $database, TableNames $tables): array
    {
        $entries = [];
        foreach (
            $database->fetchAllAssociative(sprintf(
                'SELECT id, sequence, kind, from_position, to_position, row_count, rolling_digest, '
                . 'previous_digest, digest, archive_sha256, created_at FROM %s ORDER BY sequence ASC',
                $tables->quoted('audit_anchors'),
            )) as $row
        ) {
            $entries[] = self::entry($row);
        }

        return $entries;
    }

    /**
     * Hydrate and validate one fetched ledger row.
     *
     * @param   array<string, mixed>  $row  Associative row as the driver returned it.
     *
     * @return  AuditLedgerEntry  Validated entry ready for digest recomputation.
     *
     * @throws  RuntimeException  When any field breaks its storage shape.
     *
     * @since   2.0.0
     */
    public static function entry(array $row): AuditLedgerEntry
    {
        $id = $row['id'] ?? null;
        $kind = $row['kind'] ?? null;
        if (!is_string($id) || $id === '' || !in_array($kind, ['anchor', 'prune'], true)) {
            throw new RuntimeException('An audit ledger row carries an invalid id or kind.');
        }

        return new AuditLedgerEntry(
            $id,
            self::position($row['sequence'] ?? null, 'ledger sequence'),
            $kind,
            self::position($row['from_position'] ?? null, 'ledger range start'),
            self::position($row['to_position'] ?? null, 'ledger range end'),
            self::count($row['row_count'] ?? null, 'ledger row count'),
            self::digest($row['rolling_digest'] ?? null, 'ledger rolling digest'),
            self::optionalDigest($row['previous_digest'] ?? null, 'ledger previous digest'),
            self::digest($row['digest'] ?? null, 'ledger digest'),
            self::optionalDigest($row['archive_sha256'] ?? null, 'ledger archive checksum'),
            self::instant($row['created_at'] ?? null, 'ledger creation instant'),
        );
    }

    /**
     * Validate a strictly positive stored position or sequence value.
     *
     * @param   mixed   $value  Raw driver value.
     * @param   string  $label  Field name used in the failure message.
     *
     * @return  int  The value as a positive integer.
     *
     * @throws  RuntimeException  When the value is not a positive integer.
     *
     * @since   2.0.0
     */
    public static function position(mixed $value, string $label): int
    {
        $position = self::count($value, $label);
        if ($position < 1) {
            throw new RuntimeException(sprintf('The audit %s is invalid.', $label));
        }

        return $position;
    }

    /**
     * Validate a stored position that may legitimately be absent.
     *
     * @param   mixed   $value  Raw driver value, false or null when no row matched.
     * @param   string  $label  Field name used in the failure message.
     *
     * @return  ?int  The position, or null when the value marks absence.
     *
     * @throws  RuntimeException  When a present value is not a positive integer.
     *
     * @since   2.0.0
     */
    public static function optionalPosition(mixed $value, string $label): ?int
    {
        if ($value === null || $value === false) {
            return null;
        }

        return self::position($value, $label);
    }

    /**
     * Validate a non-negative stored count value.
     *
     * @param   mixed   $value  Raw driver value.
     * @param   string  $label  Field name used in the failure message.
     *
     * @return  int  The value as a non-negative integer.
     *
     * @throws  RuntimeException  When the value is not a non-negative integer.
     *
     * @since   2.0.0
     */
    public static function count(mixed $value, string $label): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('The audit %s is invalid.', $label));
        }

        return (int) $value;
    }

    /**
     * Validate a stored SHA-256 digest value.
     *
     * @param   mixed   $value  Raw driver value.
     * @param   string  $label  Field name used in the failure message.
     *
     * @return  string  The 64-character lowercase hexadecimal digest.
     *
     * @throws  RuntimeException  When the value is not a well-formed digest.
     *
     * @since   2.0.0
     */
    public static function digest(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException(sprintf('The audit %s is invalid.', $label));
        }

        return $value;
    }

    /**
     * Validate a stored digest that may legitimately be null.
     *
     * @param   mixed   $value  Raw driver value.
     * @param   string  $label  Field name used in the failure message.
     *
     * @return  ?string  The digest, or null when none is stored.
     *
     * @throws  RuntimeException  When a present value is not a well-formed digest.
     *
     * @since   2.0.0
     */
    public static function optionalDigest(mixed $value, string $label): ?string
    {
        return $value === null ? null : self::digest($value, $label);
    }

    /**
     * Normalize a stored datetime value to the canonical `Y-m-d H:i:s` digest form.
     *
     * @param   mixed   $value  Raw driver value, a datetime string in platform storage format.
     * @param   string  $label  Field name used in the failure message.
     *
     * @return  string  The first nineteen characters, validated as a datetime literal.
     *
     * @throws  RuntimeException  When the value does not start with a datetime literal.
     *
     * @since   2.0.0
     */
    public static function instant(mixed $value, string $label): string
    {
        if (
            !is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value) !== 1
        ) {
            throw new RuntimeException(sprintf('The audit %s is invalid.', $label));
        }

        return substr($value, 0, 19);
    }
}
