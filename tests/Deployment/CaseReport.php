<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Deployment;

/**
 * The single line every deployed-artifact regression case reports, and the driver reads.
 *
 * Each case runs as its own process so that a fatal error in one is a failure of that case rather than of
 * the run. That only works if the driver can tell "this case failed" from "this case never reported at
 * all", which is the difference between a defect and a leg that silently did not execute — the second being
 * one of the four defects this lane exists to reproduce. Every case therefore ends by writing one JSON
 * object naming itself, and the driver refuses to report success unless every declared case produced one.
 *
 * @since  2.0.0
 */
final readonly class CaseReport
{
    /**
     * Report a passing case and terminate with a zero status.
     *
     * @param   string                $case    Identifier the manifest declares this case under.
     * @param   array<string, mixed>  $detail  Everything the case observed, for the run's report.
     *
     * @return  never
     *
     * @since   2.0.0
     */
    public static function pass(string $case, array $detail = []): never
    {
        self::emit($case, 'passed', $detail);
    }

    /**
     * Report a failing case and terminate with a non-zero status.
     *
     * @param   string                $case    Identifier the manifest declares this case under.
     * @param   string                $error   What went wrong, in the words an operator needs.
     * @param   array<string, mixed>  $detail  Everything the case observed before it stopped.
     *
     * @return  never
     *
     * @since   2.0.0
     */
    public static function fail(string $case, string $error, array $detail = []): never
    {
        self::emit($case, 'failed', ['error' => $error] + $detail);
    }

    /**
     * Indent a captured process output so it reads as part of one failure entry.
     *
     * @param   string  $output  Captured standard output and error.
     *
     * @return  string  The output, indented, or a note when the process said nothing at all.
     *
     * @since   2.0.0
     */
    public static function indent(string $output): string
    {
        if (trim($output) === '') {
            return '     (the process produced no output at all)';
        }

        return '     ' . str_replace("\n", "\n     ", trim($output));
    }

    /**
     * Write the result line and terminate with the status that matches it.
     *
     * @param   string                $case    Identifier the manifest declares this case under.
     * @param   string                $status  Either `passed` or `failed`.
     * @param   array<string, mixed>  $detail  Everything the case observed.
     *
     * @return  never
     *
     * @since   2.0.0
     */
    private static function emit(string $case, string $status, array $detail): never
    {
        $encoded = json_encode(
            ['case' => $case, 'status' => $status] + $detail,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $fallback = sprintf('{"case":"%s","status":"failed"}', $case);
        fwrite(STDOUT, ($encoded === false ? $fallback : $encoded) . "\n");

        exit($status === 'passed' ? 0 : 1);
    }
}
