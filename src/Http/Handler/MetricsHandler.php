<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricCollector;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\MetricsAccessPolicy;
use Kumwe\App\Infrastructure\Observability\PrometheusExposition;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the Prometheus exposition a scraper reads, once the access policy has allowed the scrape.
 *
 * The body is assembled from two sources that answer different questions. The recorder returns counters
 * and histograms accumulated across requests — how many responses, how slow — while the collector
 * recomputes gauges from the durable rows at scrape time — how deep the queue is right now, how old the
 * oldest undispatched event is. Neither is derived from the other, and neither needs a background
 * process to stay current.
 *
 * The route is registered unconditionally so the compiled route graph does not depend on a deployment
 * flag; whether it answers is the policy's decision, taken per request. A refused scrape gets a bare
 * status code and no body, because a refusal that explains itself is a refusal that helps an attacker
 * enumerate the surface.
 *
 * @since  2.0.0
 */
final readonly class MetricsHandler implements RequestHandlerInterface
{
    /**
     * Wire the exposition to its policy, its sources and its renderer.
     *
     * @param  MetricsAccessPolicy   $policy      Decides whether this scrape may read anything at all.
     * @param  MetricCatalog         $catalog     Declared families supplying help, type and ordering.
     * @param  MetricRecorder        $recorder    Counters and histograms accumulated across requests.
     * @param  MetricCollector       $collector   Gauges recomputed from the durable stores per scrape.
     * @param  PrometheusExposition  $exposition  Renderer turning samples into the response body.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MetricsAccessPolicy $policy,
        private MetricCatalog $catalog,
        private MetricRecorder $recorder,
        private MetricCollector $collector,
        private PrometheusExposition $exposition,
    ) {
    }

    /**
     * Answer one scrape.
     *
     * @param   ServerRequestInterface  $request  Scrape request; only its `Authorization` header is read.
     *
     * @return  ResponseInterface  The exposition with status 200, a bare 404 when no surface is exposed,
     *          or a bare 401 with a `WWW-Authenticate` challenge when the credential was wrong.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $decision = $this->policy->decide($request->getHeaderLine('Authorization'));
        if ($decision === MetricsAccessPolicy::ABSENT) {
            return new TextResponse('', 404, ['Cache-Control' => 'no-store']);
        }
        if ($decision !== MetricsAccessPolicy::ALLOWED) {
            return new TextResponse('', 401, [
                'Cache-Control' => 'no-store',
                'WWW-Authenticate' => 'Bearer realm="metrics"',
            ]);
        }
        $samples = array_merge($this->collector->collect(), $this->recorder->samples());

        return new TextResponse(
            $this->exposition->render($this->catalog, $samples),
            200,
            [
                'Content-Type' => PrometheusExposition::CONTENT_TYPE,
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
