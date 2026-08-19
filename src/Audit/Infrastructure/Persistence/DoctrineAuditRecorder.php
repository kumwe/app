<?php

declare(strict_types=1);

namespace Kumwe\App\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Audit\Domain\AuditEventDigest;
use Kumwe\App\Infrastructure\Persistence\TableNames;

/**
 * Records audit events as digest-chained rows in the prefixed `audit_events` table.
 *
 * This is the recorder the container wires for production. It writes on the same connection the calling
 * use case opened its transaction on, so an event commits with the change it describes and disappears
 * with it on rollback. Nothing here buffers, batches, or retries: a rejected insert — including a
 * failure while computing the digest or reading the chain head — propagates and takes the surrounding
 * transaction down with it, which is the fail-closed contract of a trail that must not lose entries.
 *
 * Tamper evidence is layered on without serializing writers. Every row stores a canonical digest of its
 * own fields, a monotonic `position` allocated by the database (auto-increment on MySQL/MariaDB, an
 * identity sequence on PostgreSQL; only the single-writer SQLite test platform computes `MAX + 1`
 * here), and a `previous_digest` witness link. The link is read with a plain snapshot `SELECT` of the
 * highest-positioned row visible to the caller's transaction — its own uncommitted events first, the
 * committed head otherwise — never with a lock on a head row, so concurrent transactions may link the
 * same predecessor or skip rows that commit around them. That is deliberate: the link is a witness
 * reference the verifier resolves to a strictly earlier row (making the deletion of a referenced row
 * evident), while strict ordering, insertion and deletion evidence for whole ranges comes from the
 * chained `audit_anchors` ledger sealed by the anchor job. A locked head row would have serialized
 * every mutation in the platform on one row for the length of each caller's transaction; the witness
 * link keeps recording contention-free while staying fail-closed.
 *
 * @since  2.0.0
 */
final readonly class DoctrineAuditRecorder implements AuditRecorder
{
    /**
     * Bind the recorder to the connection and table map it writes through.
     *
     * @param  Connection  $database  DBAL connection carrying the caller's transaction.
     * @param  TableNames  $tables    Resolver that applies the configured prefix to the audit table name.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

    /**
     * Inserts one audit event as a digest-chained row in the prefixed `audit_events` table.
     *
     * The canonical digest is computed from the event's own fields exactly as they will be stored, and
     * the witness link is resolved from the highest-positioned row this transaction can see. Both
     * happen inside the caller's transaction, so a failure in either aborts the mutation the event
     * describes.
     *
     * @param   AuditEvent  $event  Validated event to store; its id becomes the row's primary key.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the chain lookup or the insert.
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
        $digest = AuditEventDigest::compute(
            $event->id(),
            $event->occurredAt()->format(AuditEventDigest::INSTANT_FORMAT),
            $event->actorId(),
            $event->action(),
            $event->subjectType(),
            $event->subjectId(),
            $event->outcome(),
            $event->metadata(),
        );
        $head = $this->database->fetchAssociative(sprintf(
            'SELECT position, digest FROM %s ORDER BY position DESC LIMIT 1',
            $this->tables->quoted('audit_events'),
        ));
        $row = [
            'id' => $event->id(),
            'occurred_at' => $event->occurredAt(),
            'actor_id' => $event->actorId(),
            'action' => $event->action(),
            'subject_type' => $event->subjectType(),
            'subject_id' => $event->subjectId(),
            'outcome' => $event->outcome(),
            'metadata' => $event->metadata(),
            'digest' => $digest,
            'previous_digest' => $head === false ? null : $this->headDigest($head),
        ];
        if ($this->database->getDatabasePlatform() instanceof SQLitePlatform) {
            $row['position'] = $head === false ? 1 : $this->headPosition($head) + 1;
        }
        $this->database->insert($this->tables->raw('audit_events'), $row, [
            'occurred_at' => Types::DATETIME_IMMUTABLE,
            'metadata' => Types::JSON,
        ]);
    }

    /**
     * Extract the chain head's digest from the fetched head row.
     *
     * @param   array<string, mixed>  $head  Associative head row holding `position` and `digest`.
     *
     * @return  string  The 64-character digest the new row links as its witness reference.
     *
     * @throws  \RuntimeException  When the stored head digest is missing or malformed.
     *
     * @since   2.0.0
     */
    private function headDigest(array $head): string
    {
        $digest = $head['digest'] ?? null;
        if (!is_string($digest) || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException('The audit chain head digest is missing or malformed.');
        }

        return $digest;
    }

    /**
     * Extract the chain head's position from the fetched head row.
     *
     * @param   array<string, mixed>  $head  Associative head row holding `position` and `digest`.
     *
     * @return  int  Highest allocated position visible to the calling transaction.
     *
     * @throws  \RuntimeException  When the stored position is missing or not a non-negative integer.
     *
     * @since   2.0.0
     */
    private function headPosition(array $head): int
    {
        $position = $head['position'] ?? null;
        if (!is_int($position) && (!is_string($position) || preg_match('/^[0-9]+$/D', $position) !== 1)) {
            throw new \RuntimeException('The audit chain head position is missing or malformed.');
        }

        return (int) $position;
    }
}
