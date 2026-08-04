<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\LoggerInterface;

final readonly class McpServeCommand implements Command
{
    public function __construct(
        private KumweMcpServerFactory $servers,
        private KumweMcpHandlers $handlers,
        private AccessTokenVerifier $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'mcp:serve';
    }

    public function description(): string
    {
        return 'Serve capability-protected Kumwe MCP over standard input and output.';
    }

    public function execute(array $arguments, Output $output): int
    {
        if (count($arguments) !== 1 || !str_starts_with($arguments[0], '--token-file=')) {
            $output->error('Usage: mcp:serve --token-file=/run/secrets/kumwe-mcp-token');

            return 64;
        }

        $file = substr($arguments[0], strlen('--token-file='));
        $permissions = $file === '' || !is_file($file) ? false : fileperms($file);
        if (
            $file === ''
            || !str_starts_with($file, DIRECTORY_SEPARATOR)
            || !is_file($file)
            || is_link($file)
            || !is_readable($file)
            || !is_int($permissions)
            || ($permissions & 0o077) !== 0
        ) {
            $output->error('The MCP token file must be absolute, readable, non-symlinked, and mode 0600 or stricter.');

            return 65;
        }
        $token = trim((string) file_get_contents($file));
        $principal = $this->tokens->verify($token);
        if ($principal === null) {
            $output->error('The MCP access token is invalid, expired, or revoked.');

            return 77;
        }

        return $this->servers->create($this->handlers->forPrincipal($principal))
            ->run(new StdioTransport(logger: $this->logger));
    }
}
