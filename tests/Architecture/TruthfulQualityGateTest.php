<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Holds the gates to what they claim, at the one moment a claim is cheap to check.
 *
 * Each gate in this slice already fails on its own when the thing it guards breaks. What this class adds is
 * the layer above them: that the manifest defining the gates is the one `composer qa` executes, that the
 * dependency baseline can only shrink, that a declared drill leg is invoked somewhere rather than merely
 * written down, and that the three-engine matrix Gate A exit criterion 12 is assessed on is the matrix the
 * merge workflow runs. A gate that claims more than it checks is the defect this slice exists to remove, so
 * the claims themselves get a test.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class TruthfulQualityGateTest extends TestCase
{
    /**
     * Repository root, resolved once per test.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Resolve the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * The quality contract must verify clean against the repository it describes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheQualityContractMatchesWhatIsExecuted(): void
    {
        $result = $this->execute('tools/quality-contract.php', ['--check']);

        self::assertSame(0, $result['status'], "composer quality:contract must pass:\n" . $result['output']);
        self::assertStringContainsString('Kumwe quality contract verified', $result['output']);
    }

    /**
     * A gate that runs in `composer qa` and is absent from the manifest must fail the contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAGateOutsideTheManifestFailsTheContract(): void
    {
        $contract = $this->decode('docs/quality/contract.json');
        $checks = $contract['checks'];
        self::assertIsArray($checks);

        $remaining = [];
        foreach ($checks as $check) {
            self::assertIsArray($check);
            if (($check['id'] ?? null) !== 'coding-standard') {
                $remaining[] = $check;
            }
        }
        $contract['checks'] = $remaining;

        $path = $this->writeTemporary($contract);

        try {
            $result = $this->execute('tools/quality-contract.php', ['--check', '--contract=' . $path]);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status'], 'An undeclared qa gate must fail the contract.');
        self::assertStringContainsString('composer qa runs "@cs"', $result['output']);
    }

    /**
     * The semantic dependency check must pass, and its baseline must be complete and unexpired.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDependencyDirectionIsCheckedSemanticallyAgainstAShrinkingBaseline(): void
    {
        $result = $this->execute('tools/verify-dependency-graph.php', []);

        self::assertSame(0, $result['status'], "composer architecture:dependencies must pass:\n" . $result['output']);
        self::assertStringContainsString('Kumwe dependency direction verified', $result['output']);

        $baseline = $this->decode('docs/architecture/dependency-baseline.json');
        $violations = $baseline['violations'];
        self::assertIsArray($violations);
        self::assertNotSame([], $violations);
        foreach ($violations as $violation) {
            self::assertIsArray($violation);
            foreach (['from', 'to', 'owner', 'finding', 'expires', 'justification'] as $field) {
                self::assertIsString($violation[$field] ?? null);
                self::assertNotSame('', trim((string) $violation[$field]));
            }
            self::assertNotSame('UNASSIGNED', $violation['owner']);
        }
    }

    /**
     * The architecture policy gate must run the semantic check, not only the textual predicates.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheArchitecturePolicyGateRunsTheSemanticCheck(): void
    {
        $policy = $this->contents('tools/verify-policy.sh');

        self::assertStringContainsString('tools/verify-dependency-graph.php', $policy);
        self::assertStringNotContainsString("echo 'Kumwe architecture policy verified.'", $policy);
    }

    /**
     * Every deployed-artifact case must exist, and every declared drill leg must be invoked somewhere.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeployedArtifactCaseExistsAndEveryDeclaredLegIsInvoked(): void
    {
        $manifest = $this->decode('docs/quality/deployed-artifact-cases.json');
        $cases = $manifest['cases'];
        self::assertIsArray($cases);
        self::assertGreaterThanOrEqual(4, count($cases));

        $reproduced = [];
        foreach ($cases as $case) {
            self::assertIsArray($case);
            $script = $case['script'] ?? null;
            self::assertIsString($script);
            self::assertFileExists($this->root . '/' . $script);
            if (is_string($case['commit'] ?? null)) {
                $reproduced[] = $case['commit'];
            }
        }

        foreach (['cfaf840', '26a7b39', '3fdb4e9', '687707c'] as $defect) {
            self::assertContains(
                $defect,
                $reproduced,
                sprintf('The lane must reproduce the defect %s as a regression case.', $defect),
            );
        }

        $invocations = $this->contents('.github/workflows/deployment-acceptance.yml')
            . $this->contents('.github/workflows/ci.yml')
            . $this->contents('tools/asset-inspection-deployment-acceptance.sh');

        $entryPoints = $manifest['drill_entry_points'];
        self::assertIsArray($entryPoints);
        foreach ($entryPoints as $entryPoint) {
            self::assertIsArray($entryPoint);
            $path = $entryPoint['path'] ?? null;
            self::assertIsString($path);
            self::assertFileExists($this->root . '/' . $path);
            $legs = $entryPoint['legs'] ?? [];
            self::assertIsArray($legs);
            foreach ($legs as $leg) {
                self::assertIsString($leg);
                self::assertStringContainsString(
                    $leg,
                    $invocations,
                    sprintf('Leg "%s" of %s is declared and never invoked anywhere.', $leg, $path),
                );
            }
        }
    }

    /**
     * The merge workflow must run the deployed-artifact lane before deployment acceptance is stood up.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheMergeWorkflowRunsTheDeployedArtifactLaneBeforeDeploymentAcceptance(): void
    {
        $ci = $this->contents('.github/workflows/ci.yml');

        self::assertStringContainsString('composer test:artifact', $ci);
        self::assertMatchesRegularExpression('/deployment:.*\n(?:.*\n)*?\s+- artifact\n/', $ci);
    }

    /**
     * Coverage must be attributed truthfully and measured on the primary engine.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoverageIsAttributedTruthfullyAndMeasuredOnThePrimaryEngine(): void
    {
        $result = $this->execute('tools/coverage-contract.php', ['--attribution']);

        self::assertSame(0, $result['status'], "composer coverage:attribution must pass:\n" . $result['output']);

        $contract = $this->decode('docs/quality/coverage-contract.json');
        self::assertSame('mariadb', $contract['canonical_engine'] ?? null);

        $ci = $this->contents('.github/workflows/ci.yml');
        self::assertMatchesRegularExpression(
            "/- name: MariaDB LTS(?:.*\n)*?\\s+coverage: 'true'/",
            $ci,
            'The canonical coverage measurement must be collected on the primary engine.',
        );
    }

    /**
     * The idempotency baseline must excuse exactly what it records, and nothing else.
     *
     * The suite is not idempotent against a reused database, which is `V2-QA-004`. A gate that simply fails
     * on that would block every pull request and report the same six tests forever, and an advisory one
     * would not be a gate. The baseline is the reproduction: it fails on a test outside the record, fails
     * on an entry whose test now passes, and fails on an entry past its expiry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIdempotencyBaselineExcusesOnlyWhatItRecords(): void
    {
        $repeat = 'tests/Fixtures/Idempotency/recorded-repeat.junit.xml';
        $reverse = 'tests/Fixtures/Idempotency/recorded-reverse.junit.xml';

        $recorded = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=pgsql',
            '--junit=repeat:' . $this->root . '/' . $repeat,
            '--junit=reverse:' . $this->root . '/' . $reverse,
        ]);
        self::assertSame(0, $recorded['status'], "The recorded result must pass:\n" . $recorded['output']);
        self::assertStringContainsString('nothing new', $recorded['output']);

        $unrecorded = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--junit=repeat:' . $this->root . '/tests/Fixtures/Idempotency/unrecorded-failure.junit.xml',
        ]);
        self::assertSame(1, $unrecorded['status'], 'A test outside the baseline must fail the check.');
        self::assertStringContainsString('are not in the baseline', $unrecorded['output']);

        $stale = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--junit=repeat:' . $this->root . '/tests/Fixtures/Idempotency/nothing-failing.junit.xml',
        ]);
        self::assertSame(1, $stale['status'], 'A baseline entry that no longer fails must be deleted.');
        self::assertStringContainsString('only ever shrinks', $stale['output']);

        $expired = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=pgsql',
            '--today=2099-01-01',
            '--junit=repeat:' . $this->root . '/' . $repeat,
        ]);
        self::assertSame(1, $expired['status'], 'An expired exemption must fail the check.');
        self::assertStringContainsString('does not outlive the work', $expired['output']);
    }

    /**
     * Every idempotency exemption must name an owner, a finding, an expiry and its own removal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryIdempotencyExemptionIsOwnedAndExpires(): void
    {
        $baseline = $this->decode('docs/quality/idempotency-baseline.json');
        $entries = $baseline['entries'];
        self::assertIsArray($entries);
        self::assertNotSame([], $entries);

        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            foreach (['test', 'owner', 'finding', 'expires', 'removal'] as $field) {
                self::assertIsString($entry[$field] ?? null);
                self::assertNotSame('', trim((string) $entry[$field]));
                self::assertNotSame('UNASSIGNED', $entry[$field]);
            }
            self::assertSame('V2-QA-004', $entry['finding']);
            self::assertIsArray($entry['observed_on'] ?? null);
            self::assertIsArray($entry['applies_to'] ?? null);
            self::assertNotSame([], $entry['observed_on']);
        }

        $ci = $this->contents('.github/workflows/ci.yml');
        self::assertStringContainsString('composer test:idempotency', $ci);
        self::assertStringNotContainsString('continue-on-error', $ci);
    }

    /**
     * Criterion 12's three-engine matrix must be declared and executed rather than assumed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheThreeEngineRegressionMatrixIsDeclaredAndExecuted(): void
    {
        $contract = $this->decode('docs/quality/contract.json');
        $matrix = $contract['regression_matrix'];
        self::assertIsArray($matrix);
        self::assertSame('Gate A exit criterion 12', $matrix['criterion'] ?? null);
        self::assertSame(['mariadb', 'mysql', 'postgresql'], $matrix['engines'] ?? null);
        self::assertSame(['unit', 'integration', 'functional', 'architecture'], $matrix['suites'] ?? null);

        $ci = $this->contents('.github/workflows/ci.yml');
        foreach (['mariadb:lts', 'mysql:8.4', 'postgres:17-alpine'] as $image) {
            self::assertStringContainsString($image, $ci);
        }
        self::assertStringContainsString('composer test:idempotency', $ci);
    }

    /**
     * The browser journeys must run on all three engines at merge, not on one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBrowserJourneysRunOnEveryPrimaryEngineAtMerge(): void
    {
        $ci = $this->contents('.github/workflows/ci.yml');
        self::assertMatchesRegularExpression('/browser:\n(?:.*\n)*?\s+strategy:/', $ci);

        $browser = substr($ci, (int) strpos($ci, "\n  browser:\n"), (int) strpos($ci, "\n  quality:\n"));
        foreach (['mariadb:lts', 'mysql:8.4', 'postgres:17-alpine'] as $image) {
            self::assertStringContainsString($image, $browser);
        }
        self::assertStringContainsString('summarize-browser-attempts.mjs', $browser);
    }

    /**
     * Run one of this repository's dependency-free verifiers and capture its result.
     *
     * @param   string        $tool       Repository-relative path to the tool.
     * @param   list<string>  $arguments  Arguments to pass to it.
     *
     * @return  array{status: int, output: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function execute(string $tool, array $arguments): array
    {
        $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($this->root . '/' . $tool));
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $lines = [];
        $status = 0;
        exec($command . ' 2>&1', $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    /**
     * Write a modified contract to a temporary file so a gate can be proven to fail in the right direction.
     *
     * @param   array<string, mixed>  $contract  The modified contract.
     *
     * @return  string  Absolute path to the temporary file.
     *
     * @since   2.0.0
     */
    private function writeTemporary(array $contract): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-quality-');
        self::assertIsString($path);
        $encoded = json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($encoded);
        file_put_contents($path, $encoded);

        return $path;
    }

    /**
     * Decode one of the repository's JSON companions.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  array<string, mixed>  The decoded document.
     *
     * @since   2.0.0
     */
    private function decode(string $path): array
    {
        $decoded = json_decode($this->contents($path), true);
        self::assertIsArray($decoded, sprintf('%s is not well-formed JSON.', $path));

        return $decoded;
    }

    /**
     * Read one of the repository's files.
     *
     * @param   string  $path  Repository-relative path.
     *
     * @return  string  The file's contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
