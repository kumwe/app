<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console;

use Kumwe\App\Delivery\Console\StreamOutput;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the console sink's two halves: verbatim machine output, and catalogue-resolved wording.
 *
 * StreamOutput is where the translator enters the console — once, into the surface every command
 * already receives — so these tests hold the boundary between text that is looked up and output
 * that must reach a pipe unchanged.
 *
 * @since  2.0.0
 */
#[CoversClass(StreamOutput::class)]
final class StreamOutputTest extends TestCase
{
    /**
     * Machine output stays byte-for-byte on its own stream.
     *
     * line() and error() are what a command uses for a JSON envelope, an identifier or a secret
     * printed once. None of that is wording, so none of it passes through the catalogue and none of
     * it may gain a prefix, a colour or a rewrite on the way out.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRawLinesReachTheirStreamsVerbatim(): void
    {
        [$output, $standard, $error] = $this->streams();

        $output->line('{"ok":true}');
        $output->error('machine diagnostics');

        self::assertSame('{"ok":true}' . PHP_EOL, $this->drain($standard));
        self::assertSame('machine diagnostics' . PHP_EOL, $this->drain($error));
    }

    /**
     * Wording resolves through the catalogue and keeps result and failure on separate streams.
     *
     * This is the console's half of the translation contract: a command names a message and the sink
     * resolves it, so an operator piping results still sees the failure text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMessagesResolveThroughTheCatalogueOntoTheirStreams(): void
    {
        [$output, $standard, $error] = $this->streams();

        $output->message('core.console.app_health.kumwe_is_ready');
        $output->failure('core.console.app_health.kumwe_is_not_ready');

        self::assertSame('Kumwe is ready.' . PHP_EOL, $this->drain($standard));
        self::assertSame('Kumwe is not ready.' . PHP_EOL, $this->drain($error));
    }

    /**
     * A number reaches the terminal exactly as the console has always printed it.
     *
     * ICU groups digits by locale, which would turn a greppable identifier or count into something a
     * script cannot match on. Numeric parameters are therefore substituted as their own digits.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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
