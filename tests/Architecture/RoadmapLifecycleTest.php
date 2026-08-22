<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversNothing]
final class RoadmapLifecycleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTheRoadmapLedgerHoldsForwardWorkOnly(): void
    {
        $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json');

        self::assertSame(
            0,
            $result['status'],
            "composer roadmap:check must pass on the committed ledger:\n" . $result['output'],
        );
        self::assertStringContainsString('Kumwe roadmap verified', $result['output']);
    }

    /**
     * A complete criteria table remains valid when no Gate A criterion is outstanding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierAcceptsAllThirteenGateACriteriaAsMet(): void
    {
        $status = preg_replace(
            '/^\| 5 \| Quality gates are truthful \|.*$/m',
            '| 5 | Quality gates are truthful | Yes — executable evidence is complete | — |',
            $this->contents('docs/roadmap/STATUS.md'),
        );
        self::assertIsString($status);
        $status = preg_replace(
            '/^\| \*\*Gate A\*\* \| [^|\r\n]* \|$/m',
            '| **Gate A** | Ready for formal assessment. All 13 executable criteria are met. |',
            $status,
        );
        self::assertIsString($status);
        $path = $this->writeTemporaryStatus($status);

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', status: $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Kumwe roadmap verified', $result['output']);
    }

    /**
     * An affirmative Gate A readiness claim is refused while any executable criterion remains unmet.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierRefusesAReadyGateWithAnUnmetCriterion(): void
    {
        $status = preg_replace(
            '/^\| 5 \| Quality gates are truthful \|.*$/m',
            '| 5 | Quality gates are truthful | Partly — executable evidence is incomplete | GM-TEST-01 |',
            $this->contents('docs/roadmap/STATUS.md'),
        );
        self::assertIsString($status);
        $status = preg_replace(
            '/^\| \*\*Gate A\*\* \| [^|\r\n]* \|$/m',
            '| **Gate A** | Ready for formal assessment. |',
            $status,
        );
        self::assertIsString($status);
        $path = $this->writeTemporaryStatus($status);

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', status: $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Gate A is ready while criteria 5 are not met', $result['output']);
    }

    /**
     * A negative readiness statement is not treated as an affirmative Gate A readiness claim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierAcceptsNotReadyWhileACriterionIsUnmet(): void
    {
        $status = preg_replace(
            '/^\| 5 \| Quality gates are truthful \|.*$/m',
            '| 5 | Quality gates are truthful | Partly — executable evidence is incomplete | GM-TEST-01 |',
            $this->contents('docs/roadmap/STATUS.md'),
        );
        self::assertIsString($status);
        $status = preg_replace(
            '/^\| \*\*Gate A\*\* \| [^|\r\n]* \|$/m',
            '| **Gate A** | Not ready — executable evidence remains incomplete. |',
            $status,
        );
        self::assertIsString($status);
        $path = $this->writeTemporaryStatus($status);

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', status: $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Kumwe roadmap verified', $result['output']);
    }

    /**
     * Readiness text outside a Gate A row's first non-empty status cell does not become the gate state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierIgnoresReadinessOutsideTheGateStatusCell(): void
    {
        $status = preg_replace(
            '/^\| 5 \| Quality gates are truthful \|.*$/m',
            '| 5 | Quality gates are truthful | Partly — executable evidence is incomplete | GM-TEST-01 |',
            $this->contents('docs/roadmap/STATUS.md'),
        );
        self::assertIsString($status);
        $status = preg_replace(
            '/^\| \*\*Gate A\*\* \| \| .*$/m',
            '| **Gate A** | | **Not assessed** | Ready after recorded owner approval |',
            $status,
        );
        self::assertIsString($status);
        $path = $this->writeTemporaryStatus($status);

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', status: $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('Kumwe roadmap verified', $result['output']);
    }

    /**
     * Criterion state must carry the standalone Yes token rather than merely start with those letters.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierDoesNotTreatYesterdayAsYes(): void
    {
        $status = preg_replace(
            '/^\| 5 \| Quality gates are truthful \|.*$/m',
            '| 5 | Quality gates are truthful | Yesterday evidence was incomplete | GM-TEST-01 |',
            $this->contents('docs/roadmap/STATUS.md'),
        );
        self::assertIsString($status);
        $status = preg_replace(
            '/^\| \*\*Gate A\*\* \| [^|\r\n]* \|$/m',
            '| **Gate A** | Ready for formal assessment. |',
            $status,
        );
        self::assertIsString($status);
        $path = $this->writeTemporaryStatus($status);

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', status: $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('Gate A is ready while criteria 5 are not met', $result['output']);
    }

    /**
     * The live package index must not retain a completed identifier beside open work.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierRefusesACompletionMarkerInTheOpenWorkTable(): void
    {
        $status = str_replace(
            '| 3 | `P3-D` |',
            '| 3 | `P3-D` (`P3-A` complete) |',
            $this->contents('docs/roadmap/STATUS.md'),
        );
        self::assertNotSame($this->contents('docs/roadmap/STATUS.md'), $status);
        $path = $this->writeTemporaryStatus($status);

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', status: $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString(
            'open-work row for phase 3 carries a completion marker',
            $result['output'],
        );
    }

    /**
     * Thirteen rows are insufficient when their criterion identifiers are duplicated or incomplete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierRequiresExactlyGateACriteriaOneThroughThirteen(): void
    {
        $status = preg_replace(
            '/^\| 13 \|/m',
            '| 12 |',
            $this->contents('docs/roadmap/STATUS.md'),
        );
        self::assertIsString($status);
        $path = $this->writeTemporaryStatus($status);

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', status: $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('exactly Gate A criteria 1 through 13 in order', $result['output']);
    }

    public function testTheVerifierRefusesAClosedFindingAndSaysWhereItBelongs(): void
    {
        $ledger = $this->decodeJson('docs/roadmap/findings.json');
        $findings = $ledger['findings'];
        self::assertIsArray($findings);
        self::assertNotEmpty($findings);

        $reintroduced = $findings[0];
        self::assertIsArray($reintroduced);
        $reintroduced['id'] = 'GM-TEST-01';
        $reintroduced['state'] = 'closed';
        $ledger['findings'] = [...$findings, $reintroduced];

        $path = $this->writeTemporaryLedger($ledger);

        try {
            $result = $this->runVerifier($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status'], 'A reintroduced closed finding must fail the check.');
        self::assertStringContainsString('GM-TEST-01', $result['output']);
        self::assertStringContainsString('CHANGELOG.md', $result['output']);
    }

    public function testTheVerifierRefusesAMalformedLedger(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-roadmap-');
        self::assertIsString($path);
        file_put_contents($path, '{"findings": [');

        try {
            $result = $this->runVerifier($path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('well-formed JSON', $result['output']);
    }

    /**
     * A changelog citation left behind by a rebase must fail while the surviving history stays authoritative.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierRefusesAnUnreachableChangelogCitation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-changelog-');
        self::assertIsString($path);
        file_put_contents($path, "# Changelog\n\nCompleted work. (`0000000`)\n");

        try {
            $result = $this->runVerifier($this->root . '/docs/roadmap/findings.json', $path);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('not reachable from HEAD', $result['output']);
        self::assertStringContainsString('0000000', $result['output']);
    }

    /**
     * An object left dangling by a rebase is not historical evidence merely because it still exists locally.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheVerifierRefusesADanglingCommitObject(): void
    {
        $repository = sys_get_temp_dir() . '/kumwe-history-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($repository, 0o700));
        $changelog = tempnam(sys_get_temp_dir(), 'kumwe-changelog-');
        self::assertIsString($changelog);
        $dangling = '';
        $result = ['status' => 1, 'output' => 'The reachability verifier did not run.'];

        try {
            $this->git($repository, ['init', '--quiet']);
            $this->git($repository, ['config', 'user.email', 'quality@example.test']);
            $this->git($repository, ['config', 'user.name', 'Kumwe quality test']);
            file_put_contents($repository . '/evidence.txt', "historical\n");
            $this->git($repository, ['add', 'evidence.txt']);
            $this->git($repository, ['commit', '--quiet', '--message=historical']);
            $dangling = trim($this->git($repository, ['rev-parse', 'HEAD']));

            $this->git($repository, ['switch', '--quiet', '--orphan', 'surviving']);
            file_put_contents($repository . '/evidence.txt', "surviving\n");
            $this->git($repository, ['add', '--all']);
            $this->git($repository, ['commit', '--quiet', '--message=surviving']);

            file_put_contents(
                $changelog,
                sprintf("# Changelog\n\nCompleted work. (`%s`)\n", substr($dangling, 0, 12)),
            );
            $result = $this->runVerifier(
                $this->root . '/docs/roadmap/findings.json',
                $changelog,
                $repository,
            );
        } finally {
            @unlink($changelog);
            $this->removeDirectory($repository);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('not reachable from HEAD', $result['output']);
        self::assertStringContainsString(substr($dangling, 0, 12), $result['output']);
    }

    public function testTheMachineReadableCompanionsParse(): void
    {
        $findings = $this->decodeJson('docs/roadmap/findings.json');
        $capacity = $this->decodeJson('docs/roadmap/capacity-contract.json');

        self::assertNotSame([], $findings);
        self::assertNotSame([], $capacity);

        $states = $findings['states'];
        self::assertIsArray($states);
        self::assertNotContains(
            'closed',
            $states,
            'The ledger holds forward work only, so "closed" must not be an allowed state.',
        );
    }

    public function testTheQualityGateRunsTheLifecycleCheck(): void
    {
        $composer = $this->decodeJson('composer.json');
        $scripts = $composer['scripts'];
        self::assertIsArray($scripts);
        self::assertSame('php tools/verify-roadmap.php', $scripts['roadmap:check'] ?? null);
        self::assertIsArray($scripts['qa'] ?? null);
        self::assertContains('@roadmap:check', $scripts['qa']);
    }

    public function testTheTwoDocumentsPointAtEachOther(): void
    {
        $changelog = $this->contents('CHANGELOG.md');
        $roadmap = $this->contents('docs/roadmap/README.md');
        $status = $this->contents('docs/roadmap/STATUS.md');
        $agents = $this->contents('AGENTS.md');

        self::assertStringContainsString('docs/roadmap/README.md', $changelog);
        self::assertStringContainsString('docs/roadmap/STATUS.md', $changelog);
        self::assertStringContainsString('## [Unreleased]', $changelog);

        self::assertStringContainsString('## How this document moves', $roadmap);
        self::assertStringContainsString('CHANGELOG.md', $roadmap);
        self::assertStringContainsString('CHANGELOG.md', $status);
        self::assertStringContainsString('CHANGELOG.md', $agents);
    }

    /**
     * Run the lifecycle verifier against a selected ledger and optional changelog fixture.
     *
     * @param   string       $ledger      Findings ledger to verify.
     * @param   string|null  $changelog   Alternate changelog fixture, or null for the repository document.
     * @param   string|null  $repository  Alternate repository history, or null for the project checkout.
     * @param   string|null  $status      Alternate programme-status fixture, or null for the repository document.
     *
     * @return  array{status: int, output: string}  Process status and combined output.
     *
     * @since   2.0.0
     */
    private function runVerifier(
        string $ledger,
        ?string $changelog = null,
        ?string $repository = null,
        ?string $status = null,
    ): array {
        $command = sprintf(
            '%s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->root . '/tools/verify-roadmap.php'),
            escapeshellarg('--findings=' . $ledger),
        );
        if ($changelog !== null) {
            $command .= ' ' . escapeshellarg('--changelog=' . $changelog);
        }
        if ($repository !== null) {
            $command .= ' ' . escapeshellarg('--repository=' . $repository);
        }
        if ($status !== null) {
            $command .= ' ' . escapeshellarg('--status=' . $status);
        }
        $command .= ' 2>&1';

        $lines = [];
        $status = 0;
        exec($command, $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * Run one Git command inside a temporary repository and require it to succeed.
     *
     * @param   string        $repository  Temporary repository root.
     * @param   list<string>  $arguments   Git arguments, without the executable or working directory.
     *
     * @return  string  Combined command output.
     *
     * @since   2.0.0
     */
    private function git(string $repository, array $arguments): string
    {
        $command = 'git -C ' . escapeshellarg($repository);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);
        self::assertSame(0, $status, implode("\n", $lines));

        return implode("\n", $lines);
    }

    /**
     * Remove a temporary repository after its reachability assertion completes.
     *
     * @param   string  $directory  Temporary directory created by this test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        /** @var iterable<string, SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }

    /**
     * @param  array<string, mixed>  $ledger
     */
    private function writeTemporaryLedger(array $ledger): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-roadmap-');
        self::assertIsString($path);
        $encoded = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        file_put_contents($path, $encoded);

        return $path;
    }

    /**
     * Write one alternate programme-status document for a verifier test.
     *
     * @param   string  $status  Complete Markdown status document.
     *
     * @return  string  Temporary document path.
     *
     * @since   2.0.0
     */
    private function writeTemporaryStatus(string $status): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-roadmap-status-');
        self::assertIsString($path);
        file_put_contents($path, $status);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $path): array
    {
        $decoded = json_decode($this->contents($path), true);
        self::assertIsArray($decoded, sprintf('%s is not well-formed JSON.', $path));

        return $decoded;
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
