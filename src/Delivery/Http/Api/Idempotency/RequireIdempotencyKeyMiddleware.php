<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Idempotency;

use InvalidArgumentException;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses an API mutation that arrives without a usable `Idempotency-Key`, and parses the one that does.
 *
 * An idempotent route mounts this ahead of whichever middleware owns its ledger, so the header is judged
 * in exactly one place: a missing header and a malformed value are both answered here with a problem
 * document, and nothing downstream handles the raw header line. The parsed key travels on the request
 * under `ATTRIBUTE`, which is where `PersistentIdempotencyMiddleware` and
 * `SecretOnceIdempotencyMiddleware` read it from, so they may assume a validated `IdempotencyKey` and
 * treat its absence as a pipeline wiring fault rather than a client mistake. `/api/v1/plans` mounts it
 * alone: the preview stores nothing, but it still refuses to answer a request whose replay protection
 * was never established.
 *
 * @since  2.0.0
 */
final readonly class RequireIdempotencyKeyMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute the validated key is published under for the rest of the pipeline.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ATTRIBUTE = 'kumwe.api.idempotency_key';

    /**
     * Wire the middleware to the factory that renders its refusals.
     *
     * @param  ProblemDetailsResponseFactory  $problems  Renders the problem documents a missing or
     *         unusable header is answered with.
     *
     * @since  2.0.0
     */
    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    /**
     * Validate the `Idempotency-Key` header and hand the parsed key to the rest of the pipeline.
     *
     * The two refusals are kept distinct so a client can tell them apart: a request carrying no header
     * at all is answered with `idempotency-key-required`, one whose value breaks the transport-safe
     * format with `invalid-idempotency-key`. Both are 400, because neither is fixed by repeating the
     * same request. Parsing happens once, here, and it is the instance it yields — not the header text —
     * that travels on under `self::ATTRIBUTE`.
     *
     * @param   ServerRequestInterface   $request  Mutation whose `Idempotency-Key` header is inspected.
     * @param   RequestHandlerInterface  $handler  Rest of the pipeline, reached only once the key parses.
     *
     * @return  ResponseInterface  The handler's response when the key is usable, otherwise a problem
     *          document naming which of the two rules the request broke.
     *
     * @since   2.0.0
     */
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
