<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\Audit\Application\AuditRecorder;
use Kumwe\Audit\Domain\AuditEvent;

/**
 * Audit recorder that keeps every event so a test can assert which accountable act a surface caused.
 *
 * @since  2.0.0
 */
final class RecordingAuditRecorder implements AuditRecorder
{
    /**
     * Events in the order they were recorded.
     *
     * @var    list<AuditEvent>
     * @since  2.0.0
     */
    public array $events = [];

    /**
     * Keep one recorded event.
     *
     * @param   AuditEvent  $event  Event the service recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * List the recorded event actions in order.
     *
     * @return  list<string>  Recorded actions.
     *
     * @since   2.0.0
     */
    public function actions(): array
    {
        return array_map(static fn (AuditEvent $event): string => $event->action(), $this->events);
    }
}
