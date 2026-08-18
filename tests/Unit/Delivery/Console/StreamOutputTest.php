<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console;

use Kumwe\CMS\Delivery\Console\StreamOutput;
use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamOutput::class)]
final class StreamOutputTest extends TestCase
{
    public function testRawLinesReachTheirStreamsVerbatim(): void
    {
        [$output, $standard, $error] = $this->streams();

        $output->line('{"ok":true}');
        $output->error('machine diagnostics');

        self::assertSame('{"ok":true}' . PHP_EOL, $this->drain($standard));
        self::assertSame('machine diagnostics' . PHP_EOL, $this->drain($error));
    }

    public function testMessagesResolveThroughTheCatalogueOntoTheirStreams(): void
    {
        [$output, $standard, $error] = $this->streams();

        $output->message('core.console.app_health.kumwe_is_ready');
        $output->failure('core.console.app_health.kumwe_is_not_ready');

        self::assertSame('Kumwe is ready.' . PHP_EOL, $this->drain($standard));
        self::assertSame('Kumwe is not ready.' . PHP_EOL, $this->drain($error));
    }

    public function testTextSubstitutesNumbersVerbatimRatherThanGroupingThem(): void
    {
        [$output] = $this->streams();

        self::assertSame(
            'Pending 20260818001',
            $output->text('core.console.database_status.pending', ['id' => 20260818001]),
        );
        self::assertSame(
            'Unknown Kumwe command: nope',
            $output->text('core.console.application.unknown_command', ['name' => 'nope']),
        );
    }

    /**
     * Build an output over two in-memory streams and the repository's real compiled catalogue.
     *
     * @return array{StreamOutput, resource, resource}  The output and its two streams.
     */
    private function streams(): array
    {
        $standard = fopen('php://memory', 'r+');
        $error = fopen('php://memory', 'r+');
        self::assertIsResource($standard);
        self::assertIsResource($error);

        return [new StreamOutput($standard, $error, InterfaceTranslation::translator()), $standard, $error];
    }

    /**
     * Read everything written to one stream.
     *
     * @param  resource  $stream  Stream to rewind and read.
     */
    private function drain($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }
}
