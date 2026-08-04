<?php

declare(strict_types=1);

namespace Kumwe\CMS\Audit\Infrastructure\Persistence;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use RuntimeException;

final readonly class PostgreSqlAuditRecorder implements AuditRecorder
{
    public function __construct(private DatabaseInterface $database, private string $schema)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function record(AuditEvent $event): void
    {
        $id = $event->id();
        $occurredAt = $event->occurredAt()->format('Y-m-d H:i:s.uP');
        $actorId = $event->actorId();
        $action = $event->action();
        $subjectType = $event->subjectType();
        $subjectId = $event->subjectId();
        $outcome = $event->outcome();
        $metadata = $event->metadataAsJson();
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.audit_events'))
            ->columns($this->quoteNames([
                'id',
                'occurred_at',
                'actor_id',
                'action',
                'subject_type',
                'subject_id',
                'outcome',
                'metadata',
            ]))
            ->values(
                ':id, :occurred_at, :actor_id, :action, :subject_type, :subject_id, :outcome, CAST(:metadata AS jsonb)',
            )
            ->bind(':id', $id, ParameterType::STRING)
            ->bind(':occurred_at', $occurredAt, ParameterType::STRING)
            ->bind(
                ':actor_id',
                $actorId,
                $actorId === null ? ParameterType::NULL : ParameterType::STRING,
            )
            ->bind(':action', $action, ParameterType::STRING)
            ->bind(':subject_type', $subjectType, ParameterType::STRING)
            ->bind(
                ':subject_id',
                $subjectId,
                $subjectId === null ? ParameterType::NULL : ParameterType::STRING,
            )
            ->bind(':outcome', $outcome, ParameterType::STRING)
            ->bind(':metadata', $metadata, ParameterType::STRING);

        $this->database->setQuery($query)->execute();
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function quoteNames(array $names): array
    {
        return array_map(fn (string $name): string => $this->quoteName($name), $names);
    }

    private function quoteName(string $name): string
    {
        $quoted = $this->database->quoteName($name);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
