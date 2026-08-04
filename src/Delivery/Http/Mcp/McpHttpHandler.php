<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Mcp;

use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
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

final readonly class McpHttpHandler implements RequestHandlerInterface
{
    /** @var non-empty-list<string> */
    private array $allowedHosts;

    /** @param array<mixed> $allowedHosts */
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

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
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
        return $this->servers->create($this->handlers)->run($transport);
    }
}
