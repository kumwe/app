<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Records audit events as rows in the prefixed `audit_events` table over a Doctrine DBAL connection.
 *
 * This is the recorder the container wires for production. It writes on the same connection the calling
 * use case opened its transaction on, so an event commits with the change it describes and disappears
 * with it on rollback — the property that lets application code treat recording as part of the mutation
 * rather than as a follow-up. Doctrine converts the occurrence time and the metadata array through its
 * `datetime_immutable` and `json` types, which is why the row is built from `AuditEvent::metadata()`
 * rather than the pre-encoded string. Nothing here buffers, batches, or retries: a rejected insert
 * propagates and takes the surrounding transaction down with it, which is the intended failure mode
 * for an audit trail that must not silently lose entries.
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
     * Inserts one audit event as a row in the prefixed `audit_events` table.
     *
     * @param   AuditEvent  $event  Validated event to store; its id becomes the row's primary key.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the insert, including on a duplicate id.
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
        $this->database->insert($this->tables->raw('audit_events'), [
            'id' => $event->id(),
            'occurred_at' => $event->occurredAt(),
            'actor_id' => $event->actorId(),
            'action' => $event->action(),
            'subject_type' => $event->subjectType(),
            'subject_id' => $event->subjectId(),
            'outcome' => $event->outcome(),
            'metadata' => $event->metadata(),
        ], [
            'occurred_at' => Types::DATETIME_IMMUTABLE,
            'metadata' => Types::JSON,
        ]);
    }
}
