<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use Kumwe\CMS\Infrastructure\Observability\CorrelationContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request a correlation identifier, joins any upstream trace, and echoes both back.
 *
 * It is the outermost middleware in the pipeline, so the identifiers exist before anything can fail:
 * the error boundary logs them, `ExecutionContext` carries them into the domain, every log record
 * written during the request is stamped with them, and the client receives `X-Request-ID` to quote in a
 * support report. A caller may supply the identifier so a trace can be stitched across services, but
 * only when it matches a conservative pattern; anything else is replaced with fresh random hex, which
 * keeps an untrusted client from steering log content or header syntax through the value.
 *
 * The W3C `traceparent` header is accepted alongside it, and accepted only — Kumwe ships no tracer and
 * no exporter, so it does not start spans, sample, or mint a trace of its own. What it does is take a
 * well-formed upstream trace and span identifier, publish them onto every log record this request
 * writes, and echo the header back unchanged. That is enough to join Kumwe's log stream to a trace that
 * a proxy or an upstream service is already recording, which is the part of distributed tracing that
 * has value without a vendor SDK in the dependency tree. Minting identifiers no exporter will ever
 * report would fill the logs with numbers that join to nothing.
 *
 * @since  2.0.0
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute the identifier is published under for the rest of the pipeline to read.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ATTRIBUTE = 'kumwe.request_id';

    /**
     * Request attribute the accepted W3C trace identifier is published under, when one was offered.
     *
     * @var    string
     * @since  2.0.0
     */
    public const TRACE_ATTRIBUTE = 'kumwe.trace_id';

    /**
     * Bind the middleware to the holder every log record reads its identifiers from.
     *
     * @param  CorrelationContext  $correlation  Process-wide holder opened for the duration of the request.
     *
     * @since  2.0.0
     */
    public function __construct(private CorrelationContext $correlation)
    {
    }

    /**
     * Resolve the identifiers, publish them, and stamp them on the response.
     *
     * An inbound `X-Request-ID` is honoured only when it trims to 8 to 64 characters drawn from
     * `[A-Za-z0-9._-]`; otherwise a 32-character random value is generated. The response always carries
     * the identifier that was actually used, never a rejected candidate. The holder is closed in a
     * `finally`, so a long-lived process cannot carry one request's identity into the next one's logs
     * even when the request ends by throwing.
     *
     * @param   ServerRequestInterface   $request  Request whose `X-Request-ID` and `traceparent` are offered.
     * @param   RequestHandlerInterface  $handler  Next handler, called with the identifier attributes set.
     *
     * @return  ResponseInterface  The handler's response carrying `X-Request-ID`, and `traceparent` when
     *          a well-formed one was accepted.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $candidate = trim($request->getHeaderLine('X-Request-ID'));
        $requestId = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate) === 1
            ? $candidate
            : bin2hex(random_bytes(16));
        $trace = self::traceContext(trim($request->getHeaderLine('traceparent')));
        $this->correlation->begin($requestId, null, $trace['trace_id'] ?? null, $trace['span_id'] ?? null);

        try {
            $request = $request->withAttribute(self::ATTRIBUTE, $requestId);
            if (isset($trace['trace_id'])) {
                $request = $request->withAttribute(self::TRACE_ATTRIBUTE, $trace['trace_id']);
            }
            $response = $handler->handle($request);
        } finally {
            $this->correlation->end();
        }
        $response = $response->withHeader('X-Request-ID', $requestId);

        return isset($trace['traceparent'])
            ? $response->withHeader('traceparent', $trace['traceparent'])
            : $response;
    }

    /**
     * Validate an inbound `traceparent` and split out the identifiers worth propagating.
     *
     * Only version `00` is accepted, and the all-zero trace and span identifiers the specification
     * reserves as invalid are rejected, so a header written by a broken intermediary cannot put a
     * meaningless identifier on every log line. The header is re-serialised from the parsed parts
     * rather than echoed verbatim, which is what stops a crafted value from carrying anything but
     * hexadecimal back out.
     *
     * @param   string  $header  Raw `traceparent` header value.
     *
     * @return  array{trace_id?: string, span_id?: string, traceparent?: string}  Parsed parts, empty when
     *          the header is absent or malformed.
     *
     * @since   2.0.0
     */
    private static function traceContext(string $header): array
    {
        if (preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/D', $header, $matches) !== 1) {
            return [];
        }
        [, $traceId, $spanId, $flags] = $matches;
        if (trim($traceId, '0') === '' || trim($spanId, '0') === '') {
            return [];
        }

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'traceparent' => sprintf('00-%s-%s-%s', $traceId, $spanId, $flags),
        ];
    }
}
