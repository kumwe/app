<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command\McpServeCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Tests\Support\McpHandlersFixture;
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
        self::assertStringContainsString('standard input', $command->description());
    }

    public function testItRejectsCommandArgumentsBeforeOpeningTheTransport(): void
    {
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::logicalAnd(
            self::stringContains('--site'),
            self::stringContains('--token-file'),
        ));

        self::assertSame(64, $this->command()->execute(['unexpected'], $output));
    }

    public function testItRejectsAnInvalidSiteBeforeReadingTheTokenFile(): void
    {
        $tokens = $this->createMock(AccessTokenVerifier::class);
        $tokens->expects(self::never())->method('verify');
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::stringContains('site context'));

        self::assertSame(64, $this->command($tokens)->execute([
            '--token-file=/does/not/exist',
            '--site=../another-site',
        ], $output));
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
        $output = $this->createMock(Output::class);
        $output->expects(self::once())->method('error')->with(self::stringContains('invalid'));

        try {
            $result = $this->command($tokens)->execute([
                '--site=Corporate.Main',
                '--token-file=' . $tokenFile,
            ], $output);
        } finally {
            unlink($tokenFile);
        }

        self::assertSame(77, $result);
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
