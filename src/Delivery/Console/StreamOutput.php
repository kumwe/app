<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

/**
 * `Output` implementation that writes each line to a pair of already-open stream resources.
 *
 * This is the implementation the container registers for real console runs: `standard()` binds it to
 * the process's `STDOUT` and `STDERR`, so command results stay on the stream a shell pipeline reads
 * and failure text stays on the stream a supervisor logs. Any other pair of writable resources works
 * too — a file handle, or `php://memory` in a test — which is why the streams are constructor input
 * rather than constants. Writing is unbuffered and unformatted: no colour, no prefix, no truncation,
 * and a failed `fwrite` is not reported, because losing a diagnostic line must not become the reason
 * a command fails.
 *
 * @since  2.0.0
 */
final readonly class StreamOutput implements Output
{
    /**
     * Bind the two streams this output writes to.
     *
     * @param  resource  $standardOutput  Writable stream ordinary result lines are sent to.
     * @param  resource  $standardError   Writable stream failure lines are sent to; may be the same stream.
     *
     * @since  2.0.0
     */
    public function __construct(private mixed $standardOutput, private mixed $standardError)
    {
    }

    /**
     * Build the output the console process itself uses, bound to the standard streams.
     *
     * @return  self  Output writing result lines to `STDOUT` and failure lines to `STDERR`.
     *
     * @since   2.0.0
     */
    public static function standard(): self
    {
        return new self(STDOUT, STDERR);
    }

    /**
     * Write one result line, terminated by the platform newline, to the output stream.
     *
     * @param   string  $message  Result text to emit verbatim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
        fwrite($this->standardOutput, $message . PHP_EOL);
    }

    /**
     * Write one failure line, terminated by the platform newline, to the error stream.
     *
     * @param   string  $message  Operator-facing explanation to emit verbatim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        fwrite($this->standardError, $message . PHP_EOL);
    }
}
