<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use InvalidArgumentException;

/**
 * Process-wide holder for the identifiers that stitch one unit of work together across log records.
 *
 * Monolog processors receive only the record, so the correlation identifier a request already owns has
 * to reach them through something the container shares. This is that something: the outermost HTTP
 * middleware opens a unit of work on it, every log record written while it is open is stamped with the
 * same identifiers, and the middleware closes it again so a long-lived worker process never leaks one
 * request's identity into the next one's lines.
 *
 * Asynchronous work is the other half. A job, an outbox dispatch, an inbox receipt or a scheduler pass
 * is claimed from a durable row that recorded the correlation, causation and upstream trace identifiers
 * of the unit of work that wrote it; the store that claims the row enters a named frame carrying those
 * identifiers and the settlement leaves it again. Frames nest: leaving one restores whatever was open
 * underneath, so a claim made inside a request cannot erase the request's own identity, and entering a
 * slot that is already open replaces it, so a claim that was never settled cannot pin its identifiers on
 * the next piece of work.
 *
 * It is deliberately mutable — it is the one piece of request-scoped state the logging path needs —
 * and deliberately holds nothing but identifiers. Nothing user-supplied reaches it unvalidated: the
 * middleware that opens a unit of work has already constrained every value to a conservative pattern,
 * and frame subjects are restricted to a closed set of identifier keys.
 *
 * @since  2.0.0
 */
final class CorrelationContext
{
    /**
     * Subject keys a frame may add to the records written inside it.
     *
     * The list is closed so a frame can only ever publish identifiers an operator greps for, never an
     * arbitrary value a caller happened to have at hand.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const SUBJECT_KEYS = [
        'operation',
        'job_id',
        'job_type',
        'queue',
        'attempt',
        'event_id',
        'event_type',
        'consumer_id',
        'schedule_id',
        'store',
    ];

    /**
     * Slot the HTTP middleware and the console entry point open their unit of work in.
     *
     * @var    string
     * @since  2.0.0
     */
    public const REQUEST_SLOT = 'request';

    /**
     * Open frames, innermost last, each keyed by the slot that opened it.
     *
     * @var    list<array{slot: string, fields: array<string, string>}>
     * @since  2.0.0
     */
    private array $frames = [];

    /**
     * Open a unit of work, replacing whatever the previous one left behind.
     *
     * @param   string   $requestId      Identifier of this single unit of work.
     * @param   ?string  $correlationId  End-to-end identifier; defaults to the request identifier.
     * @param   ?string  $traceId        W3C trace identifier accepted from upstream, or null.
     * @param   ?string  $spanId         W3C parent span identifier accepted from upstream, or null.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function begin(
        string $requestId,
        ?string $correlationId = null,
        ?string $traceId = null,
        ?string $spanId = null,
    ): void {
        $this->frames = [[
            'slot' => self::REQUEST_SLOT,
            'fields' => self::fields($requestId, $correlationId ?? $requestId, null, $traceId, $spanId, []),
        ]];
    }

    /**
     * Close the unit of work and every frame nested inside it, so nothing carries into the next one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function end(): void
    {
        $this->frames = [];
    }

    /**
     * Enter a named frame for asynchronous work claimed from a durable row.
     *
     * The identifiers are the ones the durable row recorded when it was written, so every log line the
     * work writes joins the request that caused it. Entering a slot that is already open replaces that
     * frame instead of nesting a second copy of it.
     *
     * @param   string                 $slot           Name of the frame, such as `job` or `outbox`.
     * @param   string                 $requestId      Identifier of this single unit of asynchronous work.
     * @param   string                 $correlationId  End-to-end identifier carried by the durable row.
     * @param   ?string                $causationId    Unit of work that wrote the row, or null when unknown.
     * @param   ?string                $traceId        Upstream W3C trace identifier the row carried, or null.
     * @param   array<string, string>  $subject        Identifiers of the work itself, keyed from `SUBJECT_KEYS`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a subject key is outside `SUBJECT_KEYS`.
     *
     * @since   2.0.0
     */
    public function enter(
        string $slot,
        string $requestId,
        string $correlationId,
        ?string $causationId = null,
        ?string $traceId = null,
        array $subject = [],
    ): void {
        foreach (array_keys($subject) as $key) {
            if (!in_array($key, self::SUBJECT_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('The log frame subject key "%s" is not declared.', $key));
            }
        }
        $this->leave($slot);
        $this->frames[] = [
            'slot' => $slot,
            'fields' => self::fields($requestId, $correlationId, $causationId, $traceId, null, $subject),
        ];
    }

    /**
     * Leave a named frame, restoring the frame that was open underneath it.
     *
     * Leaving a slot that is not open is a no-op, so a settlement path may call it unconditionally.
     *
     * @param   string  $slot  Name the frame was entered under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function leave(string $slot): void
    {
        $this->frames = array_values(array_filter(
            $this->frames,
            static fn (array $frame): bool => $frame['slot'] !== $slot,
        ));
    }

    /**
     * Read the innermost frame's identifiers as the context keys a log record carries.
     *
     * Keys with no value are omitted rather than emitted as null, so a line from a process that has no
     * trace context is not padded with empty fields that a log query would have to filter out.
     *
     * @return  array<string, string>  Context fragment to merge into a record, possibly empty.
     *
     * @since   2.0.0
     */
    public function fragment(): array
    {
        return $this->frames === [] ? [] : $this->frames[count($this->frames) - 1]['fields'];
    }

    /**
     * Read the identifier of the innermost unit of work in flight.
     *
     * @return  ?string  The request identifier, or null outside a unit of work.
     *
     * @since   2.0.0
     */
    public function requestId(): ?string
    {
        return $this->fragment()['request_id'] ?? null;
    }

    /**
     * Read the end-to-end identifier of the unit of work in flight.
     *
     * @return  ?string  The correlation identifier, or null outside a unit of work.
     *
     * @since   2.0.0
     */
    public function correlationId(): ?string
    {
        return $this->fragment()['correlation_id'] ?? null;
    }

    /**
     * Read the identifier of the unit of work that caused the one in flight.
     *
     * @return  ?string  The causation identifier, or null for a unit of work nothing durable caused.
     *
     * @since   2.0.0
     */
    public function causationId(): ?string
    {
        return $this->fragment()['causation_id'] ?? null;
    }

    /**
     * Read the W3C trace identifier accepted from upstream, directly or through a durable row.
     *
     * @return  ?string  The trace identifier, or null when no valid `traceparent` reached this work.
     *
     * @since   2.0.0
     */
    public function traceId(): ?string
    {
        return $this->fragment()['trace_id'] ?? null;
    }

    /**
     * Report whether a named frame is currently open.
     *
     * @param   string  $slot  Name the frame was entered under.
     *
     * @return  bool  True while the frame is open.
     *
     * @since   2.0.0
     */
    public function inside(string $slot): bool
    {
        foreach ($this->frames as $frame) {
            if ($frame['slot'] === $slot) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assemble one frame's identifiers, dropping those with no value.
     *
     * @param   string                 $requestId      Identifier of the unit of work.
     * @param   string                 $correlationId  End-to-end identifier.
     * @param   ?string                $causationId    Unit of work that caused this one, or null.
     * @param   ?string                $traceId        Upstream W3C trace identifier, or null.
     * @param   ?string                $spanId         Upstream W3C parent span identifier, or null.
     * @param   array<string, string>  $subject        Identifiers of the work itself.
     *
     * @return  array<string, string>  The frame's context keys.
     *
     * @since   2.0.0
     */
    private static function fields(
        string $requestId,
        string $correlationId,
        ?string $causationId,
        ?string $traceId,
        ?string $spanId,
        array $subject,
    ): array {
        $fields = [];
        foreach (
            [
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'causation_id' => $causationId,
                'trace_id' => $traceId,
                'span_id' => $spanId,
            ] as $key => $value
        ) {
            if ($value !== null && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields + $subject;
    }
}
