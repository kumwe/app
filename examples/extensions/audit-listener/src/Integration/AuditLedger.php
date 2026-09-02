<?php

declare(strict_types=1);

namespace KumweExample\AuditListener\Integration;

/**
 * Bounded, process-local record of the domain events the listener observed.
 *
 * The ledger is evidence for the example and for its tests, not durable state: it keeps the most
 * recent observations only, so a long-lived worker that dispatches many mutations cannot grow it
 * without bound, and it is discarded with the process.
 *
 * @since  2.0.0
 */
final class AuditLedger
{
    /** @var int @since 2.0.0 */
    private const CAPACITY = 100;

    /**
     * Observations in arrival order, oldest first.
     *
     * @var    list<array{event_id: string, event_type: string, schema_version: int}>
     * @since  2.0.0
     */
    private array $entries = [];

    /**
     * Append one observation, discarding the oldest once the capacity is reached.
     *
     * @param   string  $eventId        Durable identifier of the observed event.
     * @param   string  $eventType      Event contract the observation belongs to.
     * @param   int     $schemaVersion  Schema version the event was published under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(string $eventId, string $eventType, int $schemaVersion): void
    {
        $this->entries[] = [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'schema_version' => $schemaVersion,
        ];
        if (count($this->entries) > self::CAPACITY) {
            array_shift($this->entries);
        }
    }

    /**
     * Return the retained observations, oldest first.
     *
     * @return  list<array{event_id: string, event_type: string, schema_version: int}>  Retained entries.
     *
     * @since   2.0.0
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
