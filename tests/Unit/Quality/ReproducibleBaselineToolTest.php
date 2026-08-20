<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Quality;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds the reproducible-baseline generator to the provenance it claims for its own document.
 *
 * The record says every figure in it is derived from the repository at the commit it names. That claim
 * failed the first time it mattered: the generator inherited the previous commit, date and run whenever
 * they were not supplied, so a record regenerated after a rebase kept naming a revision it had never
 * been generated from. The figures were re-derived correctly and the three fields that say where they
 * came from silently were not, which is how a stale record reached master and failed four lanes at once.
 * These cases pin the correction — provenance is demanded, never inherited — and pin the write path,
 * because the documented way to record it used to truncate the file it then read.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ReproducibleBaselineToolTest extends TestCase
{
    /**
     * Scratch record each case points the generator at, removed again when it finishes.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $record = '';

    /**
     * Write a record carrying provenance no current run would produce.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->record = (string) tempnam(sys_get_temp_dir(), 'kumwe-baseline-');
        self::assertIsInt(file_put_contents($this->record, json_encode([
            'baseline' => 'kumwe-reproducible-baseline',
            'commit' => str_repeat('a', 40),
            'recorded_at' => '2000-01-01',
            'recorded_from' => ['https://example.test/runs/1'],
        ], JSON_THROW_ON_ERROR)));
    }

    /**
     * Remove the scratch record and anything a failed write left beside it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ([$this->record, $this->record . '.tmp'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * Recording without naming a commit is refused rather than inheriting the recorded one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordingWithoutACommitIsRefusedRatherThanInheritingTheOldOne(): void
    {
        $result = $this->generate(['--emit', '--recorded-at=2026-08-20']);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('--commit=SHA', $result['stderr']);
        self::assertStringNotContainsString(str_repeat('a', 40), $result['stdout']);
    }

    /**
     * Recording without naming a date is refused for the same reason.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordingWithoutADateIsRefused(): void
    {
        $result = $this->generate(['--emit', '--commit=' . str_repeat('b', 40)]);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('--recorded-at=', $result['stderr']);
    }

    /**
     * Supplied provenance is what the document carries, not whatever the previous record said.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSuppliedProvenanceReplacesTheRecordedProvenanceEntirely(): void
    {
        $commit = str_repeat('c', 40);

        $result = $this->generate([
            '--emit',
            '--commit=' . $commit,
            '--recorded-at=2026-08-20',
            '--run=https://example.test/runs/2',
        ]);

        self::assertSame(0, $result['status']);
        /** @var array<string, mixed> $document */
        $document = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($commit, $document['commit'] ?? null);
        self::assertSame('2026-08-20', $document['recorded_at'] ?? null);
        self::assertSame(['https://example.test/runs/2'], $document['recorded_from'] ?? null);
    }

    /**
     * Writing replaces the record in place and leaves no temporary file behind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWritingReplacesTheRecordWithoutLeavingATemporaryFile(): void
    {
        $commit = str_repeat('d', 40);

        $result = $this->generate([
            '--emit',
            '--commit=' . $commit,
            '--recorded-at=2026-08-20',
            '--write',
        ]);

        self::assertSame(0, $result['status']);
        self::assertFileDoesNotExist($this->record . '.tmp');
        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents($this->record), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($commit, $document['commit'] ?? null);
    }

    /**
     * The documented recording command supplies every flag the generator demands of it.
     *
     * `baseline:check` tells a failing build to run `composer baseline:record`, so that command is the
     * remedy the project publishes. It stopped working the moment the generator began demanding its
     * provenance: the composer script still called `--emit --write`, the tool exited on the first
     * missing flag, and the only advice the gate offers failed immediately. The tool had tests; its
     * published entry point had none, which is exactly how that shipped.
     *
     * Rather than pin the command as a literal, this asks the generator what it requires -- so adding a
     * required flag makes it named here -- and then holds the published command to it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDocumentedRecordingCommandSuppliesEveryFlagTheGeneratorDemands(): void
    {
        $samples = ['--commit' => str_repeat('0', 40), '--recorded-at' => '2026-01-01'];
        $supplied = [];
        $required = [];

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $result = $this->generate(array_merge(['--emit'], $supplied));
            if ($result['status'] === 0) {
                break;
            }
            self::assertSame(
                1,
                preg_match('/--emit needs (--[a-z-]+)=/', $result['stderr'], $matches),
                sprintf('The generator refused --emit without naming a missing flag: %s', $result['stderr']),
            );
            $flag = $matches[1];
            self::assertArrayHasKey(
                $flag,
                $samples,
                sprintf('%s is newly required; give this test a valid sample value for it.', $flag),
            );
            $required[] = $flag;
            $supplied[] = $flag . '=' . $samples[$flag];
        }

        self::assertNotSame([], $required, 'The generator no longer demands any provenance.');

        /** @var array{scripts?: array<string, mixed>} $manifest */
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $record = $manifest['scripts']['baseline:record'] ?? null;
        self::assertIsString($record, 'composer.json declares no baseline:record script.');

        foreach ($required as $flag) {
            self::assertStringContainsString(
                $flag . '=',
                $record,
                sprintf('composer baseline:record does not supply %s, so the published remedy fails.', $flag),
            );
        }
    }

    /**
     * Run the generator against the scratch record.
     *
     * @param   list<string>  $arguments  Flags to pass, excluding the record path.
     *
     * @return  array{status: int, stdout: string, stderr: string}  What the process reported.
     *
     * @since   2.0.0
     */
    private function generate(array $arguments): array
    {
        $root = dirname(__DIR__, 3);
        $command = array_merge(
            [PHP_BINARY, $root . '/tools/record-baseline.php', '--baseline=' . $this->record],
            $arguments,
        );
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
