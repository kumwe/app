<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

/**
 * Process-wide holder for the identifiers that stitch one unit of work together across log records.
 *
 * Monolog processors receive only the record, so the correlation identifier a request already owns has
 * to reach them through something the container shares. This is that something: the outermost HTTP
 * middleware opens a unit of work on it, every log record written while it is open is stamped with the
 * same identifiers, and the middleware closes it again so a long-lived worker process never leaks one
 * request's identity into the next one's lines.
 *
 * It is deliberately mutable — it is the one piece of request-scoped state the logging path needs —
 * and deliberately holds nothing but identifiers. Nothing user-supplied reaches it unvalidated: the
 * middleware that opens a unit of work has already constrained every value to a conservative pattern.
 *
 * @since  2.0.0
 */
final class CorrelationContext
{
    /**
     * Identifier of the single unit of work in flight, or null outside one.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $requestId = null;

    /**
     * Identifier shared by every unit of work in the same end-to-end operation, or null outside one.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $correlationId = null;

    /**
     * W3C trace identifier accepted from an upstream `traceparent`, or null when none was offered.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $traceId = null;

    /**
     * W3C parent span identifier accepted from an upstream `traceparent`, or null when none was offered.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $spanId = null;

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
        $this->requestId = $requestId;
        $this->correlationId = $correlationId ?? $requestId;
        $this->traceId = $traceId;
        $this->spanId = $spanId;
    }

    /**
     * Close the unit of work so nothing carries into the next one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function end(): void
    {
        $this->requestId = null;
        $this->correlationId = null;
        $this->traceId = null;
        $this->spanId = null;
    }

    /**
     * Read the identifiers as the context keys a log record carries.
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
        $fragment = [];
        foreach (
            [
                'request_id' => $this->requestId,
                'correlation_id' => $this->correlationId,
                'trace_id' => $this->traceId,
                'span_id' => $this->spanId,
            ] as $key => $value
        ) {
            if ($value !== null) {
                $fragment[$key] = $value;
            }
        }

        return $fragment;
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
        return $this->correlationId;
    }

    /**
     * Read the W3C trace identifier accepted from upstream.
     *
     * @return  ?string  The trace identifier, or null when no valid `traceparent` was offered.
     *
     * @since   2.0.0
     */
    public function traceId(): ?string
    {
        return $this->traceId;
    }
}
