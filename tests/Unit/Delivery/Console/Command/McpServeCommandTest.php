<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command\McpServeCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use Kumwe\CMS\Tests\Support\McpHandlersFixture;
use Kumwe\CMS\Tests\Support\TranslatesConsoleOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(McpServeCommand::class)]
final class McpServeCommandTest extends TestCase
{
    public function testItPublishesItsStableCommandMetadata(): void
    {
        $command = $this->command();

        self::assertSame('mcp:serve', $command->name());
        self::assertSame('core.console.mcp_serve.description', $command->description());
        self::assertStringContainsString(
            'standard input',
            InterfaceTranslation::translator()->translate($command->description()),
        );
    }

    public function testItRejectsCommandArgumentsBeforeOpeningTheTransport(): void
    {
        $output = new McpServeOutput();

        self::assertSame(64, $this->command()->execute(['unexpected'], $output));
        self::assertCount(1, $output->errors);
        self::assertStringContainsString('--site', $output->errors[0]);
        self::assertStringContainsString('--token-file', $output->errors[0]);
    }

    public function testItRejectsAnInvalidSiteBeforeReadingTheTokenFile(): void
    {
        $tokens = $this->createMock(AccessTokenVerifier::class);
        $tokens->expects(self::never())->method('verify');
        $output = new McpServeOutput();

        self::assertSame(64, $this->command($tokens)->execute([
            '--token-file=/does/not/exist',
            '--site=../another-site',
        ], $output));
        self::assertCount(1, $output->errors);
        self::assertStringContainsString('site context', $output->errors[0]);
    }

    public function testItVerifiesTheTokenForTheCanonicalSelectedSite(): void
    {
        $tokenFile = tempnam(sys_get_temp_dir(), 'kumwe-mcp-token-');
        if (!is_string($tokenFile)) {
            self::fail('A temporary MCP token file could not be created.');
        }
        file_put_contents($tokenFile, 'opaque-test-token');
        chmod($tokenFile, 0600);

        $tokens = $this->createMock(AccessTokenVerifier::class);
        $tokens->expects(self::once())
            ->method('verify')
            ->with('opaque-test-token', 'kumwe-mcp', 'mcp', 'corporate.main')
            ->willReturn(null);
        $output = new McpServeOutput();

        try {
            $result = $this->command($tokens)->execute([
                '--site=Corporate.Main',
                '--token-file=' . $tokenFile,
            ], $output);
        } finally {
            unlink($tokenFile);
        }

        self::assertSame(77, $result);
        self::assertCount(1, $output->errors);
        self::assertStringContainsString('invalid', $output->errors[0]);
    }

    private function command(?AccessTokenVerifier $tokens = null): McpServeCommand
    {
        $catalog = new McpCapabilityCatalog();

        return new McpServeCommand(
            new KumweMcpServerFactory($catalog),
            McpHandlersFixture::create($catalog),
            $tokens ?? $this->createStub(AccessTokenVerifier::class),
            new NullLogger(),
        );
    }
}

/** Recording sink for the MCP startup failure lines, resolving wording as production does. */
final class McpServeOutput implements Output
{
    use TranslatesConsoleOutput;

    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
