<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Extension;

use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Holds the cross-process lifecycle lock through downstream idempotency commit or rollback.
 *
 * The extension and trust-key routes mutate the registry and then record the outcome against the
 * request's idempotency key. A lock taken inside the handler would be released before that record was
 * written, leaving a window in which a concurrent retry could run the mutation a second time. Mounting
 * this ahead of `RequireIdempotencyKeyMiddleware` and `PersistentIdempotencyMiddleware` makes the lock
 * span the handler and the commit or rollback that follows it, so lifecycle mutations are serialised
 * across processes end to end. The store takes the lock without waiting, so a request arriving while
 * another lifecycle operation is in flight is failed rather than queued behind it.
 *
 * @since  2.0.0
 */
final readonly class TrustLifecycleMiddleware implements MiddlewareInterface
{
    /**
     * Wire the middleware to the trust store whose lifecycle lock it holds.
     *
     * @param  TrustStore  $trust  Trust store owning the lock that serialises registry lifecycle work.
     *
     * @since  2.0.0
     */
    public function __construct(private TrustStore $trust)
    {
    }

    /**
     * Run the rest of the pipeline inside the trust store's lifecycle lock.
     *
     * The request travels on untouched; only its timing changes. The lock is released as the call
     * unwinds, whether the pipeline returned a response or threw, so a failed mutation cannot strand it.
     *
     * @param   ServerRequestInterface   $request  Request passed to the next handler unchanged.
     * @param   RequestHandlerInterface  $handler  Remainder of the pipeline, invoked while the lock is held.
     *
     * @return  ResponseInterface  Whatever the downstream handler produced.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->trust->synchronizedLifecycle(
            static fn (): ResponseInterface => $handler->handle($request),
        );
    }
}
