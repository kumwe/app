<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Signal that unwinds a token mutation's transaction while carrying its unsuccessful response out.
 *
 * `SecretOnceIdempotencyMiddleware` runs the token handler inside one transaction so that the minted
 * credential and the idempotency record marking the key completed commit together. When the handler
 * answers 5xx neither may survive, yet the response still has to reach the client — and simply
 * returning it from the closure would commit both. Throwing this is what rolls the transaction back;
 * the middleware catches it ahead of the arm that re-throws genuine faults, deletes the reservation so
 * the same `Idempotency-Key` is free for another attempt, and returns the response carried here. It is
 * control flow rather than a fault, so its message is never rendered: the caller sees the handler's own
 * response, unchanged.
 *
 * @since  2.0.0
 */
final class SecretOnceResponseRollback extends RuntimeException
{
    /**
     * Capture the unsuccessful response that must still be returned once the rollback has happened.
     *
     * @param  ResponseInterface  $response  The 5xx response the token handler produced, sent to the
     *         client verbatim after the transaction is undone.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct('The secret-returning request failed and was rolled back.');
    }
}
