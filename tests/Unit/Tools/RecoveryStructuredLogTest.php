<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds the backup and restore tools to the same structured, redacted log contract as the PHP runtime.
 *
 * The subject is `tools/recovery-common.sh`, executed for real: every line it writes must be one JSON object
 * carrying the correlation, request, causation, release, runtime and outcome fields, credential-shaped keys and
 * values must be redacted, a nested tool must share its parent's correlation and name the parent as its cause,
 * and each run's outcome must be recorded atomically where the metrics endpoint reads it.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class RecoveryStructuredLogTest extends TestCase
{
    /**
     * A failed run writes redacted JSON lines, records its failure, and a later success records success too.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAFailedRunIsLoggedRedactedAndRecordedThenASuccessIsRecordedBesideIt(): void
    {
        $status = self::directory();
        [$exit, , $error] = self::execute(<<<'BASH'
            recovery_begin backup backup
            recovery_log warning 'connect mysql://svc:patterned-recovery-secret@db failed password=patterned' \
                db_password patterned-key-value path /srv/backups
            fail() { recovery_fail "Kumwe backup failed: $*"; }
            fail 'probe refusal'
            BASH, ['KUMWE_OPERATIONS_STATUS_DIR' => $status, 'KUMWE_RELEASE' => '2.0.0']);

        self::assertSame(1, $exit);
        $lines = self::lines($error);
        self::assertCount(3, $lines, $error);
        self::assertStringNotContainsString('patterned-recovery-secret', $error);
        self::assertStringNotContainsString('patterned-key-value', $error);
        self::assertStringNotContainsString('password=patterned', $error);
        $correlation = $lines[0]['context']['correlation_id'];
        self::assertIsString($correlation);
        self::assertMatchesRegularExpression('/^backup-[0-9a-f]{32}$/', $correlation);
        foreach ($lines as $line) {
            self::assertSame('kumwe', $line['channel']);
            self::assertSame($correlation, $line['context']['correlation_id']);
            self::assertSame('backup', $line['context']['runtime']);
            self::assertSame('2.0.0', $line['context']['release']);
            self::assertSame('backup', $line['context']['operation']);
            self::assertArrayHasKey('request_id', $line['context']);
        }
        self::assertSame('started', $lines[0]['context']['outcome']);
        self::assertSame('[redacted]', $lines[1]['context']['db_password']);
        self::assertSame('/srv/backups', $lines[1]['context']['path']);
        self::assertSame('failure', $lines[1]['context']['outcome']);
        self::assertSame(300, $lines[1]['level']);
        self::assertSame('Kumwe backup failed: probe refusal', $lines[2]['message']);
        self::assertSame(400, $lines[2]['level']);

        $failed = self::recorded($status, 'backup');
        self::assertSame('kumwe-operation-status/v1', $failed['schema']);
        self::assertSame('failure', $failed['last_outcome']);
        self::assertNull($failed['last_success_at']);
        self::assertIsInt($failed['last_failure_at']);

        [$exit] = self::execute('recovery_begin backup backup', ['KUMWE_OPERATIONS_STATUS_DIR' => $status]);

        self::assertSame(0, $exit);
        $recovered = self::recorded($status, 'backup');
        self::assertSame('success', $recovered['last_outcome']);
        self::assertIsInt($recovered['last_success_at']);
        self::assertSame($failed['last_failure_at'], $recovered['last_failure_at']);
        self::assertSame([], glob($status . '/.*.json.*') ?: [], 'Staging files must never be left behind.');
    }

    /**
     * A nested tool shares the cycle's correlation, names the parent run as its cause, and aborts are recorded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANestedToolJoinsTheParentCorrelationAndAnUnexpectedAbortIsRecorded(): void
    {
        $status = self::directory();
        $common = dirname(__DIR__, 3) . '/tools/recovery-common.sh';
        [$exit, , $error] = self::execute(sprintf(<<<'BASH'
            recovery_begin backup backup 'backup cycle'
            bash -c 'set -Eeuo pipefail; source %s; recovery_begin restore restore_verify "restore verification"; false'
            BASH, escapeshellarg($common)), [
            'KUMWE_OPERATIONS_STATUS_DIR' => $status,
            'KUMWE_CORRELATION_ID' => 'probe-cycle-correlation',
        ]);

        self::assertSame(1, $exit);
        $lines = self::lines($error);
        $parent = $lines[0]['context'];
        self::assertSame('Kumwe backup cycle started.', $lines[0]['message']);
        self::assertSame('probe-cycle-correlation', $parent['correlation_id']);
        $child = array_values(array_filter(
            $lines,
            static fn (array $line): bool => $line['context']['operation'] === 'restore_verify',
        ));
        self::assertCount(2, $child, $error);
        self::assertSame('probe-cycle-correlation', $child[0]['context']['correlation_id']);
        self::assertSame($parent['request_id'], $child[0]['context']['causation_id']);
        self::assertSame('Kumwe restore verification stopped before completing.', $child[1]['message']);
        self::assertSame('1', $child[1]['context']['exit_status']);
        self::assertSame('failure', self::recorded($status, 'restore_verify')['last_outcome']);
        self::assertSame('failure', self::recorded($status, 'backup')['last_outcome']);
    }

    /**
     * An unusable status directory is reported and never turns a successful run into a failed one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnusableStatusDirectoryIsReportedWithoutFailingTheRun(): void
    {
        [$exit, , $error] = self::execute('recovery_begin restore restore', [
            'KUMWE_OPERATIONS_STATUS_DIR' => '/nonexistent/kumwe-status-probe',
        ]);

        self::assertSame(0, $exit);
        $messages = array_column(self::lines($error), 'message');
        self::assertContains('Kumwe operation status directory is unusable; outcome not recorded.', $messages);
        self::assertContains('Kumwe restore completed.', $messages);
    }

    /**
     * Run a script with the recovery helpers sourced under the tools' own shell options.
     *
     * @param   string                 $script       Bash statements to run after sourcing.
     * @param   array<string, string>  $environment  Extra environment variables.
     *
     * @return  array{int, string, string}  Exit status, stdout and stderr.
     *
     * @since   2.0.0
     */
    private static function execute(string $script, array $environment = []): array
    {
        $root = dirname(__DIR__, 3);
        $process = proc_open(
            ['bash', '-c', 'set -Eeuo pipefail; source tools/recovery-common.sh; ' . $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            ['PATH' => (string) getenv('PATH')] + $environment,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }

    /**
     * Decode every stderr line, failing when any line is not a JSON object.
     *
     * @param   string  $error  Captured stderr.
     *
     * @return  list<array{message: string, level: int, channel: string, context: array<string, mixed>}>
     *          Decoded lines in order.
     *
     * @since   2.0.0
     */
    private static function lines(string $error): array
    {
        $lines = [];
        foreach (array_filter(explode("\n", $error), static fn (string $line): bool => $line !== '') as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, sprintf('Not a structured line: %s', $line));
            self::assertIsString($decoded['message'] ?? null);
            self::assertIsInt($decoded['level'] ?? null);
            self::assertIsString($decoded['channel'] ?? null);
            self::assertIsArray($decoded['context'] ?? null);
            /** @var array{message: string, level: int, channel: string, context: array<string, mixed>} $decoded */
            $lines[] = $decoded;
        }

        return $lines;
    }

    /**
     * Read one recorded operation outcome.
     *
     * @param   string  $directory  Status directory.
     * @param   string  $operation  Operation name.
     *
     * @return  array<string, mixed>  Decoded status document.
     *
     * @since   2.0.0
     */
    private static function recorded(string $directory, string $operation): array
    {
        $contents = file_get_contents($directory . '/' . $operation . '.json');
        self::assertIsString($contents);
        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Create an empty temporary status directory removed after the test.
     *
     * @return  string  Absolute directory path.
     *
     * @since   2.0.0
     */
    private static function directory(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-status-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        register_shutdown_function(static function () use ($directory): void {
            foreach (glob($directory . '/{,.}*.json*', GLOB_BRACE) ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        });

        return $directory;
    }
}
