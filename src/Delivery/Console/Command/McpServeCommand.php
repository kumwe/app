<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;

final readonly class McpServeCommand implements Command
{
    public function __construct(
        private KumweMcpServerFactory $servers,
        private KumweMcpHandlers $handlers,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'mcp:serve';
    }

    public function description(): string
    {
        return 'Serve the safe Kumwe MCP surface over standard input and output.';
    }

    public function execute(array $arguments, Output $output): int
    {
        if ($arguments !== []) {
            $output->error('mcp:serve accepts no arguments; MCP messages are read from standard input.');

            return 64;
        }

        return $this->servers->create($this->handlers)->run(new StdioTransport(logger: $this->logger));
    }
}
