<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Kumwe\App\Infrastructure\Persistence\ReadinessStatus;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers the readiness probe a load balancer uses to decide whether this worker may take traffic.
 *
 * Where liveness only asks whether the process is alive, readiness asks whether it would serve correct
 * responses right now. A worker that has not yet compiled its extension runtime map, or whose compiled
 * map has gone stale against the shared registry, would render pages from the wrong extension set — so
 * it reports 503 and is drained until the map is rebuilt, rather than quietly serving stale output. The
 * decision itself is delegated to a `ReadinessStatus` probe, so what counts as ready stays a runtime
 * concern and the delivery layer only maps the verdict onto a status code.
 *
 * @since  2.0.0
 */
final readonly class ReadinessHandler implements RequestHandlerInterface
{
    /**
     * Bind the route to the probe that decides whether this worker may take traffic.
     *
     * @param  ReadinessStatus  $probe  Readiness check consulted on every request, never cached, so that
     *         a worker recovers as soon as the underlying condition clears.
     *
     * @since  2.0.0
     */
    public function __construct(private ReadinessStatus $probe)
    {
    }

    /**
     * Reports whether this worker is currently fit to receive traffic.
     *
     * @param   ServerRequestInterface  $request  Incoming probe request; its contents are ignored.
     *
     * @return  ResponseInterface  A 200 JSON body with `status` of `ready`, or 503 with `not_ready`; the
     *          status code is the part a load balancer acts on.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ready = $this->probe->ready();

        return new JsonResponse(
            ['status' => $ready ? 'ready' : 'not_ready'],
            $ready ? 200 : 503,
        );
    }
}
