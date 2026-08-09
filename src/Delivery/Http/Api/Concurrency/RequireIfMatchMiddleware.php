<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses a mutating API request that does not state which revision it expects to overwrite.
 *
 * The middleware guards the routes that mutate a versioned resource — update, trash, restore and
 * workflow transition alike. Because a missing header is answered 428 and an unusable one 400 before
 * the handler runs, a handler mounted behind it only ever decides whether the precondition holds,
 * never whether one was supplied. The parsed `IfMatch` is published on the request under
 * `ATTRIBUTE`, so the header grammar is read once per request however many collaborators consult it.
 *
 * @since  2.0.0
 */
final readonly class RequireIfMatchMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute the parsed `IfMatch` precondition is published under for downstream handlers.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ATTRIBUTE = 'kumwe.api.if_match';

    /**
     * Wire the middleware to the factory that renders its refusals.
     *
     * @param  ProblemDetailsResponseFactory  $problems  Builds the 428 and 400 problem documents.
     *
     * @since  2.0.0
     */
    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    /**
     * Require a usable `If-Match` header, then pass the parsed precondition to the next handler.
     *
     * An absent header is answered 428 rather than 400: the request is well formed and merely omits a
     * precondition this route insists on, which tells the client to retry with one. A header that is
     * present but unusable — malformed syntax, or a weak tag, which `If-Match` may not carry — is a
     * client error and becomes 400.
     *
     * @param   ServerRequestInterface   $request  Request whose `If-Match` header is required and parsed.
     * @param   RequestHandlerInterface  $handler  Next handler, reached only once the header parses.
     *
     * @return  ResponseInterface  The handler's response, or the 428 or 400 problem document.
     *
     * @since   2.0.0
     */
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
