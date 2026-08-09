<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Idempotency;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Signal that unwinds an idempotent mutation's transaction while carrying its 5xx response out.
 *
 * `PersistentIdempotencyMiddleware` runs the wrapped handler and the write that marks the idempotency
 * record `completed` inside one transaction, so a server failure has to undo both rather than be stored
 * as a replayable result — a 5xx is never a settled outcome. Returning the response from the closure
 * would commit it, so the middleware throws this instead: the transaction rolls back, the reservation
 * is deleted, leaving the `Idempotency-Key` free for another attempt, and the caught instance hands the
 * original response back to the client. It is caught ahead of the arm that re-throws genuine faults,
 * and its own message is never rendered.
 *
 * @since  2.0.0
 */
final class ServerFailureResponse extends RuntimeException
{
    /**
     * Capture the unsuccessful response that must still be returned once the rollback has happened.
     *
     * @param  ResponseInterface  $response  The 5xx response the wrapped handler produced, sent to the
     *         client verbatim after the transaction is undone.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly ResponseInterface $response)
    {
        parent::__construct('The idempotent operation returned an unsuccessful server response.');
    }
}
