<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\StudioPreviewHttpTransport;
use Kumwe\App\Studio\Application\Host\StudioHostDispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Normative HTTP binding for every Studio host port operation.
 *
 * @since  2.0.0
 */
final readonly class AdministratorStudioHostHandler implements RequestHandlerInterface
{
    /**
     * Bind normative HTTP delivery to the one application dispatcher.
     *
     * @param  StudioHostDispatcher  $dispatcher  Shared envelope and stale-generation fence.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioHostDispatcher $dispatcher)
    {
    }

    /**
     * Decode JSON and dispatch using the execution context attached by administrator middleware.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized and CSRF-checked request.
     *
     * @return  ResponseInterface  No-store canonical host result or host error response.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $port = $request->getAttribute('port');
        $operation = $request->getAttribute('operation');
        try {
            $body = json_decode((string) $request->getBody(), false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = null;
        }
        try {
            $previewTransport = $port === 'preview' ? StudioPreviewHttpTransport::fromPort($request) : null;
        } catch (InvalidArgumentException) {
            $previewTransport = null;
        }
        $outcome = is_string($port) && is_string($operation)
            ? $this->dispatcher->dispatch(
                AdministratorRequest::context($request),
                $port,
                $operation,
                $body,
                $previewTransport,
            )
            : StudioHostDispatcher::refusal('invalid-request', 'studio.host/operation-mismatch');

        return new JsonResponse($outcome->document, $outcome->status, ['Cache-Control' => 'no-store']);
    }
}
