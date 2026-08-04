<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command\McpServeCommand;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\KumweMcpServerFactory;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
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
        $output->expects(self::once())->method('error')->with(self::stringContains('accepts no arguments'));

        self::assertSame(64, $this->command()->execute(['unexpected'], $output));
    }

    private function command(): McpServeCommand
    {
        $catalog = new McpCapabilityCatalog();

        return new McpServeCommand(
            new KumweMcpServerFactory($catalog),
            new KumweMcpHandlers($catalog),
            new NullLogger(),
        );
    }
}
