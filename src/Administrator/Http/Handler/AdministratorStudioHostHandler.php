<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\StudioPreviewHttpTransport;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\Producer\Wire\Dispatcher;
use Kumwe\Producer\Wire\RequestEnvelope;
use Laminas\Diactoros\Response\TextResponse;
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
     * Bind normative HTTP delivery to direct request-scoped Producer hosts.
     *
     * @param  StudioProducerHostFactory  $hosts             Request-scoped App host factory.
     * @param  int                        $maximumBodyBytes  Maximum accepted canonical request bytes.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioProducerHostFactory $hosts,
        private int $maximumBodyBytes = RequestEnvelope::DEFAULT_MAXIMUM_BODY_BYTES,
    ) {
        if ($maximumBodyBytes < 1) {
            throw new InvalidArgumentException('The Studio host body bound must be positive.');
        }
    }

    /**
     * Pass raw bytes through Producer and emit its canonical body and complete header set verbatim.
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
            $previewTransport = $port === 'preview' ? StudioPreviewHttpTransport::fromPort($request) : null;
        } catch (InvalidArgumentException) {
            $previewTransport = null;
        }
        $route = is_string($port) && is_string($operation)
            ? $port . '/' . $operation
            : 'invalid/invalid';
        $outcome = (new Dispatcher(
            $this->hosts->create(AdministratorRequest::context($request), $previewTransport),
            maximumBodyBytes: $this->maximumBodyBytes,
        ))->dispatch($route, (string) $request->getBody());
        $status = $outcome->refusalCategory === null
            ? 200
            : StudioProducerError::status($outcome->refusalCategory);
        $headers = [];
        foreach ($outcome->headers as $name => $value) {
            if ($name !== '') {
                $headers[$name] = $value;
            }
        }

        return new TextResponse($outcome->body, $status, $headers);
    }
}
