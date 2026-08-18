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
    /**
     * Proves raw lines reach standard output and standard error byte for byte.
     *
     * Machine-readable output is not translatable text: a JSON document and a diagnostic line must arrive
     *      * exactly as written, on the stream that names them, with no catalogue lookup and no rewriting.
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
     * Proves catalogue-bound messages resolve through the translator onto the right stream.
     *
     * This is the console half of the binding the Twig environments already have: a command names a message
     *      * identifier and the surface resolves it once, so wording lives in the catalogue rather than in
     *      * forty-eight command classes.
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
     * Proves numeric placeholders are substituted verbatim rather than locale-grouped.
     *
     * Console output is frequently piped into another program, so a count must not acquire thousands
     *      * separators on its way to the stream: the number a caller supplies is the number the reader and the
     *      * next process both see.
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
