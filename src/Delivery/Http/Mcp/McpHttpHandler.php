<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Mcp;

use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves `/mcp`, the Model Context Protocol endpoint, over the official streamable HTTP transport.
 *
 * An MCP client reaches Kumwe through this one route, and what it gets is the same application services
 * the REST API and the administrator use — `KumweMcpHandlers` is rebound to the request's execution
 * context before any tool runs, so a tool call is authorised, validated and audited exactly as the
 * equivalent REST call would be. Sessions are opened with a bearer token scoped to the `kumwe-mcp`
 * audience, and this class re-establishes that identity on every request: it will not dispatch
 * without an authenticated principal, without an execution context, or when the two name different
 * subjects, because a mismatch means the attributes were assembled by different things and there is no
 * safe subject to act as. The transport is built per request, so its CORS, DNS-rebinding and
 * protocol-version middleware see this request's headers, and it is handed the configured body limit,
 * which bounds how much of a request it will read.
 *
 * @since  2.0.0
 */
final readonly class McpHttpHandler implements RequestHandlerInterface
{
    /**
     * Exact hostnames the transport will serve, which is what DNS-rebinding protection compares against.
     *
     * Not promoted alongside the dependencies because the constructor narrows it: the configured value
     * is proven to be a non-empty list of wildcard-free strings before it is assigned, so the transport
     * middleware is only ever handed hostnames it can compare exactly. Deployment supplies the host
     * parsed out of the configured base URL.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    private array $allowedHosts;

    /**
     * Wire the transport's collaborators and validate the two settings that bound what it will accept.
     *
     * The host list arrives untyped from configuration, so it is validated here rather than trusted. The
     * rebinding check matches hostnames exactly, which makes a wildcard entry meaningless and an empty
     * list unusable, so both are refused at construction instead of surfacing once per request.
     *
     * @param   KumweMcpServerFactory     $servers       Builds the MCP server, its capabilities and its tool catalogue.
     * @param   KumweMcpHandlers          $handlers      Tool implementations, rebound to the caller's context.
     * @param   ResponseFactoryInterface  $responses     PSR-17 factory the transport and its middleware answer through.
     * @param   StreamFactoryInterface    $streams       PSR-17 factory for the transport's response bodies.
     * @param   LoggerInterface           $logger        Where the transport records protocol-level problems.
     * @param   int                       $maxBodyBytes  Largest request body the transport will read; must be positive.
     * @param   array<mixed>              $allowedHosts  Exact hostnames the endpoint answers to, as a non-empty
     *          list of wildcard-free strings.
     *
     * @throws  \InvalidArgumentException  When the body limit is not positive, when the host list is empty
     *          or is not a list, or when an entry is not a string, is empty, or carries a wildcard.
     *
     * @since   2.0.0
     */
    public function __construct(
        private KumweMcpServerFactory $servers,
        private KumweMcpHandlers $handlers,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private LoggerInterface $logger,
        private int $maxBodyBytes,
        array $allowedHosts,
    ) {
        if ($this->maxBodyBytes < 1) {
            throw new \InvalidArgumentException('The MCP request-body limit must be positive.');
        }

        if ($allowedHosts === [] || !array_is_list($allowedHosts)) {
            throw new \InvalidArgumentException('At least one exact MCP transport host is required.');
        }

        foreach ($allowedHosts as $host) {
            if (!is_string($host) || $host === '' || str_contains($host, '*')) {
                throw new \InvalidArgumentException('MCP transport hosts must be exact hostnames or IP addresses.');
            }
        }

        /** @var non-empty-list<non-empty-string> $allowedHosts */
        $this->allowedHosts = $allowedHosts;
    }

    /**
     * Run one MCP request against a server bound to the caller's execution context.
     *
     * The identity checks are the point of this method. A request must arrive carrying both the
     * authenticated principal and the execution context, and the subject the context acts as must be the
     * subject the request authenticated as; each of those is a composition failure rather than a client
     * error, so it is refused outright instead of being answered. Only once they agree is the context
     * handed to `KumweMcpHandlers`, which is what makes every tool the session can reach run under this
     * caller's authority and no one else's.
     *
     * @param   ServerRequestInterface  $request  MCP request already past bearer authentication and the
     *          middleware that attaches the execution context.
     *
     * @return  ResponseInterface  Whatever the transport produces — a JSON-RPC result, an event stream, a
     *          preflight answer, or a refusal from the transport middleware.
     *
     * @throws  \LogicException  When the request carries no authenticated principal, carries no execution
     *          context, or carries a context whose subject differs from the authenticated one.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new \LogicException('MCP HTTP requests must be authenticated before dispatch.');
        }
        $transport = new StreamableHttpTransport(
            request: $request,
            responseFactory: $this->responses,
            streamFactory: $this->streams,
            logger: $this->logger,
            middleware: [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware(
                    $this->allowedHosts,
                    $this->responses,
                    $this->streams,
                ),
                new ProtocolVersionMiddleware(null, $this->responses, $this->streams),
            ],
            maxBodyBytes: $this->maxBodyBytes,
        );
        $context = $request->getAttribute(ExecutionContext::REQUEST_ATTRIBUTE);
        if (!$context instanceof ExecutionContext) {
            throw new \LogicException('MCP HTTP requests require an execution context.');
        }
        if ($context->principal()?->subject() !== $principal->subject()) {
            throw new \LogicException('MCP principal and execution context identities must match.');
        }

        return $this->servers->create($this->handlers->forContext($context))->run($transport);
    }
}
