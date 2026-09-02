<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Prints tool verdicts the way `tools/verify-package-boundaries.php` does and maps them to exit codes.
 *
 * Failures go to STDERR, one line each, prefixed with the tool's name (`Capability index: …`,
 * `Core growth: …`) so a CI log can be grepped for the gate that refused. Success goes to STDOUT as one
 * line. Both return the exit status rather than calling `exit()` so a test can drive the same code path.
 *
 * @since  2.0.0
 */
final readonly class ToolOutput
{
    /**
     * Print every failure under the tool prefix and return the failing exit status.
     *
     * @param   string        $prefix    Tool name printed before each message, without the colon.
     * @param   list<string>  $messages  Failure lines, each naming file, rule and fix.
     *
     * @return  int  Always 1.
     *
     * @since   2.0.0
     */
    public static function fail(string $prefix, array $messages): int
    {
        foreach ($messages as $message) {
            fwrite(STDERR, $prefix . ': ' . $message . PHP_EOL);
        }

        return 1;
    }

    /**
     * Print the success line and return the passing exit status.
     *
     * @param   string  $line  Complete success sentence, without a trailing newline.
     *
     * @return  int  Always 0.
     *
     * @since   2.0.0
     */
    public static function succeed(string $line): int
    {
        fwrite(STDOUT, $line . PHP_EOL);

        return 0;
    }
}
