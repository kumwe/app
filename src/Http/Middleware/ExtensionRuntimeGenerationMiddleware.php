<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Middleware;

use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Drains a request process before it can dispatch code from a superseded extension generation.
 *
 * The full HTTP and MCP graph is assembled once from the trusted publication loaded at boot. A later
 * lifecycle mutation cannot surgically rebuild the router, Twig loaders, catalogues and typed handler
 * registries in another process, so this boundary refuses the whole resident graph until the runtime
 * watcher materializes the new generation and restarts it. Recovery composition loads no extension
 * publication and therefore continues serving the operator paths needed to repair the installation.
 *
 * @since  2.0.0
 */
final readonly class ExtensionRuntimeGenerationMiddleware implements MiddlewareInterface
{
    /**
     * Bind the request boundary to the resident publication and live generation authority.
     *
     * @param  ExtensionExecutionGate          $runtime   Live authority for resident extension code.
     * @param  RuntimeMaterializationState     $loaded    Boot-time publication served by this process.
     * @param  ProblemDetailsResponseFactory  $problems  Canonical RFC 9457 response builder.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionExecutionGate $runtime,
        private RuntimeMaterializationState $loaded,
        private ProblemDetailsResponseFactory $problems = new ProblemDetailsResponseFactory(),
    ) {
    }

    /**
     * Continue only while a loaded extension publication remains the exact authoritative generation.
     *
     * A core-only recovery or first-install process has no trusted publication and passes through; it
     * holds no extension code that could be stale. A process that did load one returns a retryable 503
     * before routing, authentication, template resolution or MCP dispatch can reach resident extension
     * objects after authority changed.
     *
     * @param   ServerRequestInterface   $request  Request passed unchanged when the runtime is safe.
     * @param   RequestHandlerInterface  $handler  Remaining pipeline, never called for a stale generation.
     *
     * @return  ResponseInterface  Downstream response, or a no-store retryable service-unavailable problem.
     *
     * @since   2.0.0
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->loaded->trusted || $this->runtime->isCurrent()) {
            return $handler->handle($request);
        }

        return $this->problems->create(
            503,
            'Extension runtime reload required',
            'The extension runtime changed and this process is draining before it can serve another request.',
        )->withHeader('Cache-Control', 'no-store')->withHeader('Retry-After', '1');
    }
}
