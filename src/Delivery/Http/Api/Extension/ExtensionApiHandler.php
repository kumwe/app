<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Extension;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ExtensionApiHandler implements RequestHandlerInterface
{
    public function __construct(private ExtensionManager $extensions, private ProblemDetailsResponseFactory $problems)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (strtoupper($request->getMethod()) === 'GET') {
                return new JsonResponse(
                    ['items' => $this->extensions->installed(ApiExecutionContext::fromRequest($request))],
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            }
            $identifier = $this->identifier($request);
            $context = ApiExecutionContext::fromRequest($request);
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/activate')) {
                return new JsonResponse($this->extensions->activate($identifier, $context));
            }
            if (str_ends_with($path, '/disable')) {
                return new JsonResponse($this->extensions->disable($identifier, $context));
            }
            if (strtoupper($request->getMethod()) === 'DELETE') {
                $this->extensions->uninstall($identifier, $context);
                return new EmptyResponse(204);
            }
            throw new InvalidArgumentException('The extension operation is not supported.');
        } catch (InvalidArgumentException $exception) {
            return $this->problems->create(
                422,
                'Unprocessable Extension Operation',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            );
        }
    }

    private function identifier(ServerRequestInterface $request): string
    {
        $vendor = $request->getAttribute('vendor');
        $name = $request->getAttribute('name');
        if (!is_string($vendor) || !is_string($name)) {
            throw new InvalidArgumentException('An extension identifier is required.');
        }
        return $vendor . '/' . $name;
    }
}
