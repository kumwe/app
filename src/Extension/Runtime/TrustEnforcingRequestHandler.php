<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Request handler decorator that re-establishes an extension's trust before its route may answer.
 *
 * Extension and administrator routes are declared once while the application is composed and are never
 * withdrawn, so the router on its own cannot express that an extension was disabled or had its signing
 * key revoked afterwards. `MezzioExtensionRouteRegistrar` and `AdministratorRouteRegistry` therefore
 * wrap every contributed handler in this one, which takes the installation-wide lifecycle lock and
 * re-runs trust enforcement per request. The route stops answering from the next request onwards, with
 * no router rebuild and no redeployment, and the refusal is allowed to propagate so the request fails
 * closed rather than reaching code the installation no longer trusts.
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
     * @param  TrustStore               $trust      Trust boundary consulted per request, and the owner
     *         of the lifecycle lock the check runs inside.
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
     * Both the check and the delegation happen inside the lifecycle lock, so a request cannot be served
     * from an extension tree that an install or uninstall is midway through replacing. Enforcement is
     * not passive: an extension whose release no longer verifies is quarantined by this very request
     * before the refusal is raised, which is what makes the failure stick for every later request too.
     *
     * @param   ServerRequestInterface  $request  Request to serve once trust has been re-established.
     *
     * @return  ResponseInterface  Whatever the wrapped handler produced, passed back unchanged.
     *
     * @throws  \Kumwe\CMS\Extension\Application\Trust\UntrustedPackage  When the extension is no longer
     *          active, or its release record, signing key, package signature or deployed bytes fail
     *          verification.
     * @throws  \InvalidArgumentException  When the extension identifier, or the package digest or
     *          signature stored on its release, cannot be parsed.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->trust->synchronizedLifecycle(function () use ($request): ResponseInterface {
            $this->trust->enforceRuntimeTrust($this->extension);
            return $this->inner->handle($request);
        });
    }
}
