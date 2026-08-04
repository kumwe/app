<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequireIdempotencyKeyMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'kumwe.api.idempotency_key';

    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->hasHeader('Idempotency-Key')) {
            return $this->problems->create(
                400,
                'Idempotency Key Required',
                'This operation requires an Idempotency-Key header.',
                'urn:kumwe:problem:idempotency-key-required',
                (string) $request->getUri(),
            );
        }

        try {
            $key = IdempotencyKey::fromHeader($request->getHeaderLine('Idempotency-Key'));
        } catch (InvalidArgumentException) {
            return $this->problems->create(
                400,
                'Invalid Idempotency Key',
                'Idempotency-Key must contain 8 to 128 transport-safe ASCII characters.',
                'urn:kumwe:problem:invalid-idempotency-key',
                (string) $request->getUri(),
            );
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $key));
    }
}
