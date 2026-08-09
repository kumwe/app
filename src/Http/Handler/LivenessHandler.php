<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers the process-liveness probe that an orchestrator uses to decide whether to restart the worker.
 *
 * Liveness asks exactly one question: is this PHP process still able to accept a request and produce a
 * response? So the handler deliberately touches nothing — no database, no cache, no filesystem — because
 * a dependency outage must never be reported as a dead process and get a healthy worker killed while it
 * is waiting for that dependency to come back. The complementary question, whether the worker is fit to
 * receive real traffic, belongs to `ReadinessHandler`.
 *
 * @since  2.0.0
 */
final class LivenessHandler implements RequestHandlerInterface
{
    /**
     * Reports that the process is running and able to answer.
     *
     * @param   ServerRequestInterface  $request  Incoming probe request; its contents are ignored.
     *
     * @return  ResponseInterface  A 200 JSON body whose `status` is always `alive`; the status code is
     *          the part a probe acts on, and the only failure signal is no response at all.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse(['status' => 'alive', 'product' => 'Kumwe CMS']);
    }
}
