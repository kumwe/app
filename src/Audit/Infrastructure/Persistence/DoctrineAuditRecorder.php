<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

final readonly class DoctrineAuditRecorder implements AuditRecorder
{
    public function __construct(private Connection $database, private TableNames $tables)
    {
    }

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
