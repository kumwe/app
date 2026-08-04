<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequireIfMatchMiddleware implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'kumwe.api.if_match';

    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->hasHeader('If-Match')) {
            return $this->problems->create(
                428,
                'Precondition Required',
                'This operation requires an If-Match header containing the current strong ETag.',
                'urn:kumwe:problem:precondition-required',
                (string) $request->getUri(),
            );
        }

        try {
            $condition = IfMatch::fromHeader($request->getHeaderLine('If-Match'));
        } catch (InvalidArgumentException) {
            return $this->problems->create(
                400,
                'Invalid If-Match Header',
                'If-Match must be a wildcard or a comma-separated list of strong entity tags.',
                'urn:kumwe:problem:invalid-if-match',
                (string) $request->getUri(),
            );
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $condition));
    }
}
