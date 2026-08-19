<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Middleware;

use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Counts responses and times them, which is where the runbook's 5xx-rate signal comes from.
 *
 * It sits directly inside the request-identity and error boundaries, so a request that ends in an
 * unhandled exception is still counted — with the status the error boundary actually returned, not with
 * whatever the handler intended. That placement is the point: a 5xx rate that misses the requests which
 * blew up is the one number an operator must never be given.
 *
 * Two labels only, `method` and `status`, both drawn from closed enumerations in `MetricCatalog`. There
 * is deliberately no path or route label. A path label on this application would carry record, media and
 * approval identifiers straight into the metric namespace, minting a permanent time series per business
 * record and publishing the identifiers to anyone who can read a dashboard. Which route was slow is a
 * question for the log stream, which already carries the path and the correlation identifier and which
 * expires; a metric label does not expire.
 *
 * @since  2.0.0
 */
final readonly class MetricsMiddleware implements MiddlewareInterface
{
    /**
     * Bind the middleware to the recorder that will absorb its emissions.
     *
     * @param  MetricRecorder  $recorder  Sink for the counter and the histogram; a no-op when metrics
     *         are switched off, which is the shipped default.
     *
     * @since  2.0.0
     */
    public function __construct(private MetricRecorder $recorder)
    {
    }

    /**
     * Time the rest of the pipeline and record one response.
     *
     * @param   ServerRequestInterface   $request  Incoming request; only its method is read.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline.
     *
     * @return  ResponseInterface  Whatever the pipeline produced, unmodified.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $started = microtime(true);
        $method = strtoupper($request->getMethod());
        try {
            $response = $handler->handle($request);
        } catch (Throwable $failure) {
            $this->record($method, 500, microtime(true) - $started);

            throw $failure;
        }
        $this->record($method, $response->getStatusCode(), microtime(true) - $started);

        return $response;
    }

    /**
     * Emit the counter and the histogram observation for one response.
     *
     * @param   string  $method    Request method, upper-cased.
     * @param   int     $status    Response status code.
     * @param   float   $duration  Seconds the pipeline took.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function record(string $method, int $status, float $duration): void
    {
        $labels = ['method' => $method];
        $this->recorder->increment(
            MetricCatalog::HTTP_REQUESTS,
            $labels + ['status' => MetricCatalog::statusClass($status)],
        );
        $this->recorder->observe(MetricCatalog::HTTP_DURATION, $labels, $duration);
    }
}
