<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
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
        try {
            $options = CommandInput::options($arguments);
            if (
                count($arguments) !== 2
                || count($options) !== 2
                || array_diff(array_keys($options), ['site', 'token-file']) !== []
            ) {
                throw new InvalidArgumentException('The MCP stdio options are invalid.');
            }
            $site = SiteContext::fromString(CommandInput::required($options, 'site'));
            $file = CommandInput::required($options, 'token-file');
        } catch (InvalidArgumentException $exception) {
            $output->error(sprintf(
                '%s Usage: mcp:serve --site=SITE --token-file=/run/secrets/kumwe-mcp-token',
                $exception->getMessage(),
            ));

            return 64;
        }

        try {
            $token = CommandInput::secretFile($file);
        } catch (InvalidArgumentException) {
            $output->error(
                'The MCP token file must be absolute, readable, non-symlinked, non-empty, '
                . 'and mode 0600 or stricter.',
            );

            return 65;
        }

        $siteIdentifier = $site->identifier();
        if ($this->tokens->verify($token, 'kumwe-mcp', 'mcp', $siteIdentifier) === null) {
            $output->error('The MCP access token is invalid, expired, or revoked.');

            return 77;
        }

        return $this->servers->create($this->handlers->forCredential(
            $this->tokens,
            $token,
            $siteIdentifier,
        ))
            ->run(new StdioTransport(logger: $this->logger));
    }
}
