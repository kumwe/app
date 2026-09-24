<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\App\Extension\Application\Trust\TrustStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Request handler decorator that re-establishes an extension's trust before its route may answer.
 *
 * Extension and administrator routes are declared once while the application is composed and are never
 * withdrawn, so the router on its own cannot express that an extension was disabled or had its signing
 * key revoked afterwards. The manifest-backed route registries therefore
 * wrap every contributed handler in this one, which re-runs trust enforcement per request. The route
 * stops answering from the next request onwards, with no router rebuild and no redeployment, and the
 * refusal is allowed to propagate so the request fails closed rather than reaching code the installation
 * no longer trusts. The check reads committed trust authority and deliberately takes no lifecycle lock:
 * that lock serializes mutators and is taken without waiting, so holding it here made two concurrent
 * requests to any extension route refuse each other and let request traffic refuse a revocation. The
 * extension tree a request executes is immutable per release version and is retired only after no live
 * process lease still names it, so a concurrent install cannot rewrite it underneath the request.
 *
 * @since  2.0.0
 */
final readonly class TrustEnforcingRequestHandler implements RequestHandlerInterface
{
    /**
     * Wrap the handler a contributed route would otherwise reach directly.
     *
     * @param  RequestHandlerInterface  $inner      Handler invoked once the extension's trust has been
     *         confirmed for this request.
     * @param  TrustStore               $trust      Trust boundary consulted per request.
     * @param  string                   $extension  `vendor/name` of the extension that contributed the
     *         route.
     *
     * @since  2.0.0
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private TrustStore $trust,
        private string $extension,
    ) {
    }

    /**
     * Enforce the owning extension's trust, then delegate the request to the wrapped handler.
     *
     * Enforcement is not passive: an extension whose release no longer verifies is quarantined by this
     * very request before the refusal is raised, which is what makes the failure stick for every later
     * request too. A trust authority that cannot be read is refused as such and logged by the store.
     *
     * @param   ServerRequestInterface  $request  Request to serve once trust has been re-established.
     *
     * @return  ResponseInterface  Whatever the wrapped handler produced, passed back unchanged.
     *
     * @throws  \Kumwe\App\Extension\Application\Trust\UntrustedPackage  When the extension is no longer
     *          active, or its release record, signing key, package signature or deployed bytes fail
     *          verification.
     * @throws  \InvalidArgumentException  When the extension identifier, or the package digest or
     *          signature stored on its release, cannot be parsed.
     * @throws  \RuntimeException  When the trust authority cannot be read.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->trust->enforceRuntimeTrust($this->extension);

        return $this->inner->handle($request);
    }
}
