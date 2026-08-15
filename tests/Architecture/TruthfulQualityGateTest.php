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
     * A check assigned to a provisioned workflow job must not also run in the generic lane process.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheManifestRunnerDelegatesChecksWithExplicitLaneBindings(): void
    {
        $contract = [
            'cadences' => ['nightly'],
            'checks' => [
                [
                    'id' => 'delegated',
                    'runner' => 'shell',
                    'command' => 'exit 99',
                    'cadence' => ['nightly'],
                    'workflows' => [
                        'nightly' => ['file' => 'workflow.yml', 'job' => 'browser'],
                    ],
                ],
                [
                    'id' => 'manifest-owned',
                    'runner' => 'shell',
                    'command' => 'printf manifest-executed',
                    'cadence' => ['nightly'],
                    'workflows' => [],
                    'invoked_by' => '',
                ],
            ],
        ];
        $path = $this->writeTemporary($contract);

        try {
            $result = $this->execute('tools/quality-contract.php', [
                '--run',
                '--cadence=nightly',
                '--contract=' . $path,
            ]);
        } finally {
            @unlink($path);
        }

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('delegated to workflow.yml job "browser"', $result['output']);
        self::assertStringContainsString('manifest-executed', $result['output']);
        self::assertStringContainsString('1 checks executed', $result['output']);
    }

    /**
     * An empty delegation marker cannot make a check disappear from the generic lane runner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyInvokedByDeclarationFailsTheContract(): void
    {
        $contract = $this->decode('docs/quality/contract.json');
        $checks = $contract['checks'] ?? null;
        self::assertIsArray($checks);
        self::assertIsArray($checks[0] ?? null);
        $checks[0]['invoked_by'] = '';
        $contract['checks'] = $checks;
        $path = $this->writeTemporary($contract);

        try {
            $result = $this->execute('tools/quality-contract.php', ['--check', '--contract=' . $path]);
        } finally {
            @unlink($path);
        }

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('must declare invoked_by as a non-empty check identifier', $result['output']);
    }

    /**
     * Generic nightly and release runners provision the database and Redis required by the full suite.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testManifestOwnedTestSuitesRunWithTheirRequiredServices(): void
    {
        $nightly = $this->contents('.github/workflows/nightly.yml');
        $nightlyStart = strpos($nightly, "\n  quality-contract:\n");
        $nightlyEnd = strpos($nightly, "\n  browser:\n");
        self::assertIsInt($nightlyStart);
        self::assertIsInt($nightlyEnd);
        $nightlyRunner = substr($nightly, $nightlyStart, $nightlyEnd - $nightlyStart);
        $services = [
            'image: mariadb:lts',
            'image: redis:8-alpine',
            'DB_DRIVER: mariadb',
            'REDIS_HOST: 127.0.0.1',
        ];
        foreach ($services as $proof) {
            self::assertStringContainsString($proof, $nightlyRunner);
        }

        $release = $this->contents('.github/workflows/release.yml');
        $releaseStart = strpos($release, "\n  release:\n");
        self::assertIsInt($releaseStart);
        $releaseRunner = substr($release, $releaseStart);
        foreach ($services as $proof) {
            self::assertStringContainsString($proof, $releaseRunner);
        }
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
     * A function or constant member in a grouped import must not hide the class members beside it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMixedGroupedImportsCannotHideAForbiddenDependency(): void
    {
        $temporary = sys_get_temp_dir() . '/kumwe-dependency-' . bin2hex(random_bytes(8));
        $source = $temporary . '/src/Application/Probe';
        self::assertTrue(mkdir($source, 0o700, true));
        $baseline = $temporary . '/baseline.json';
        self::assertNotFalse(file_put_contents($baseline, '{"violations": []}'));

        $before = <<<'PHP'
<?php

namespace Kumwe\CMS\Application\Probe;

use Kumwe\CMS\{Infrastructure\Persistence\DoctrineTransactionManager as Adapter, function Shared\helper};

final class ClassBeforeFunction
{
    public function __construct(private Adapter $adapter)
    {
    }
}
PHP;
        $after = <<<'PHP'
<?php

namespace Kumwe\CMS\Application\Probe;

use Kumwe\CMS\{const Shared\VALUE, Infrastructure\Persistence\DoctrineTransactionManager as Adapter};

final class ClassAfterConstant
{
    public function __construct(private Adapter $adapter)
    {
    }
}
PHP;
        $functionOnly = <<<'PHP'
<?php

namespace Kumwe\CMS\Application\Probe;

use function Kumwe\CMS\Infrastructure\Persistence\{transaction_probe};

final class GroupedFunctionOnly
{
}
PHP;
        self::assertNotFalse(file_put_contents($source . '/ClassBeforeFunction.php', $before));
        self::assertNotFalse(file_put_contents($source . '/ClassAfterConstant.php', $after));
        self::assertNotFalse(file_put_contents($source . '/GroupedFunctionOnly.php', $functionOnly));

        try {
            $result = $this->execute('tools/verify-dependency-graph.php', [
                '--source=' . $temporary . '/src',
                '--baseline=' . $baseline,
            ]);
        } finally {
            @unlink($source . '/ClassBeforeFunction.php');
            @unlink($source . '/ClassAfterConstant.php');
            @unlink($source . '/GroupedFunctionOnly.php');
            @unlink($baseline);
            @rmdir($source);
            @rmdir(dirname($source));
            @rmdir(dirname($source, 2));
            @rmdir($temporary . '/src');
            @rmdir($temporary);
        }

        self::assertSame(1, $result['status'], 'Mixed grouped class imports must be evaluated.');
        self::assertStringContainsString('ClassBeforeFunction', $result['output']);
        self::assertStringContainsString('ClassAfterConstant', $result['output']);
        self::assertStringContainsString('DoctrineTransactionManager', $result['output']);
        self::assertStringNotContainsString('GroupedFunctionOnly', $result['output']);
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

        $recorded = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=pgsql',
            '--expected-tests=7',
            '--junit=repeat:' . $this->root . '/' . $repeat,
            '--status=repeat:2',
        ]);
        self::assertSame(0, $recorded['status'], "The recorded result must pass:\n" . $recorded['output']);
        self::assertStringContainsString('nothing new', $recorded['output']);

        $unrecorded = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--expected-tests=7',
            '--junit=repeat:' . $this->root . '/tests/Fixtures/Idempotency/unrecorded-failure.junit.xml',
            '--status=repeat:2',
        ]);
        self::assertSame(1, $unrecorded['status'], 'A test outside the baseline must fail the check.');
        self::assertStringContainsString('are not in the baseline', $unrecorded['output']);

        $stale = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--expected-tests=7',
            '--junit=repeat:' . $this->root . '/tests/Fixtures/Idempotency/nothing-failing.junit.xml',
            '--status=repeat:0',
        ]);
        self::assertSame(1, $stale['status'], 'A baseline entry that no longer fails must be deleted.');
        self::assertStringContainsString('only ever shrinks', $stale['output']);

        $expired = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=pgsql',
            '--today=2099-01-01',
            '--expected-tests=7',
            '--junit=repeat:' . $this->root . '/' . $repeat,
            '--status=repeat:2',
        ]);
        self::assertSame(1, $expired['status'], 'An expired exemption must fail the check.');
        self::assertStringContainsString('does not outlive the work', $expired['output']);
    }

    /**
     * Missing passes, truncated reports and unexplained runner exits are broken evidence, never green runs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdempotencyEvidenceMustBeCompleteAndExplainTheRunnerExit(): void
    {
        $recorded = $this->root . '/tests/Fixtures/Idempotency/recorded-repeat.junit.xml';
        $missing = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--expected-tests=7',
            '--junit=other:' . $recorded,
            '--status=other:2',
        ]);
        self::assertSame(1, $missing['status']);
        self::assertStringContainsString('missing report(s) for: repeat', $missing['output']);
        self::assertStringContainsString('undeclared pass(es): other', $missing['output']);

        $missingStatus = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--expected-tests=7',
            '--junit=repeat:' . $recorded,
        ]);
        self::assertSame(1, $missingStatus['status']);
        self::assertStringContainsString('missing runner status(es) for: repeat', $missingStatus['output']);

        $truncated = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--expected-tests=7',
            '--junit=repeat:' . $this->root . '/tests/Fixtures/Idempotency/truncated-report.junit.xml',
            '--status=repeat:0',
        ]);
        self::assertSame(1, $truncated['status']);
        self::assertStringContainsString('independent collection found 7', $truncated['output']);

        $accountedSkip = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mariadb',
            '--expected-tests=7',
            '--junit=repeat:' . $this->root . '/tests/Fixtures/Idempotency/nothing-failing.junit.xml',
            '--status=repeat:0',
        ]);
        self::assertSame(0, $accountedSkip['status'], $accountedSkip['output']);

        $runnerFailure = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--expected-tests=7',
            '--junit=repeat:' . $this->root . '/tests/Fixtures/Idempotency/nothing-failing.junit.xml',
            '--status=repeat:2',
        ]);
        self::assertSame(1, $runnerFailure['status']);
        self::assertStringContainsString('outcomes and diagnostics require exit 0', $runnerFailure['output']);
    }

    /**
     * An exploratory pass neither borrows exemptions from nor declares staleness in another pass.
     *
     * The six existing entries record failures from `repeat`. A green `reverse` measurement must therefore
     * stay green without claiming those repeat failures disappeared, while the same test identifier failing
     * under `reverse` must be reported as new rather than excused by its repeat-era entry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExploratoryIdempotencyEvidenceIsPassAware(): void
    {
        $green = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--pass=reverse',
            '--expected-tests=7',
            '--junit=reverse:' . $this->root . '/tests/Fixtures/Idempotency/nothing-failing.junit.xml',
            '--status=reverse:0',
        ]);
        self::assertSame(0, $green['status'], $green['output']);
        self::assertStringContainsString('0 recorded non-idempotent test(s)', $green['output']);
        self::assertStringNotContainsString('only ever shrinks', $green['output']);

        $borrowed = $this->execute('tools/verify-suite-idempotency.php', [
            '--engine=mysql',
            '--pass=reverse',
            '--expected-tests=7',
            '--junit=reverse:' . $this->root
                . '/tests/Fixtures/Idempotency/repeat-entry-reverse-failure.junit.xml',
            '--status=reverse:1',
        ]);
        self::assertSame(1, $borrowed['status'], 'A repeat exemption must not excuse a reverse failure.');
        self::assertStringContainsString('failed in the reverse pass', $borrowed['output']);
        self::assertStringContainsString('are not in the baseline', $borrowed['output']);
        self::assertStringNotContainsString('only ever shrinks', $borrowed['output']);
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
            self::assertSame(['repeat'], $entry['passes'] ?? null);
            self::assertIsArray($entry['observed_on'] ?? null);
            self::assertIsArray($entry['applies_to'] ?? null);
            self::assertNotSame([], $entry['observed_on']);
        }

        $ci = $this->contents('.github/workflows/ci.yml');
        self::assertStringContainsString('composer test:idempotency', $ci);
        self::assertStringNotContainsString('continue-on-error', $ci);
    }

    /**
     * A pass the baseline does not enforce must say why, and must not be silently absent.
     *
     * The reverse-order pass is owed and is not enforced, because its first attempt measured the wrong
     * property — `--order-by=reverse` reorders the tests inside each class as well as the classes. A gate
     * may narrow what it claims; it may not narrow it quietly. The baseline therefore has to carry the
     * unenforced pass, its finding, its owner and the reason, and the generator that measures it correctly
     * has to produce a runnable configuration that leaves method order alone.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnenforcedPassCarriesItsReasonAndItsCorrectedMechanism(): void
    {
        $baseline = $this->decode('docs/quality/idempotency-baseline.json');
        $scope = $baseline['scope'];
        self::assertIsArray($scope);
        self::assertSame(['repeat'], $scope['enforced_passes'] ?? null);

        $pending = $scope['not_yet_enforced'];
        self::assertIsArray($pending);
        self::assertSame('reverse', $pending['pass'] ?? null);
        self::assertSame('V2-QA-004', $pending['finding'] ?? null);
        foreach (['statement', 'owner', 'why_not_enforced', 'how_to_run'] as $field) {
            self::assertIsString($pending[$field] ?? null);
            self::assertNotSame('', trim((string) $pending[$field]));
        }

        $emitted = $this->execute('tools/verify-suite-idempotency.php', ['--emit-reverse-configuration']);
        self::assertSame(0, $emitted['status'], $emitted['output']);
        $path = trim($emitted['output']);

        try {
            $configuration = file_get_contents($path);
            self::assertIsString($configuration);
            self::assertStringNotContainsString('--order-by=reverse', $configuration);

            $listed = [];
            if (preg_match_all('#<file>(.+?)</file>#', $configuration, $matched) > 0) {
                $listed = $matched[1];
            }
            self::assertGreaterThan(20, count($listed), 'The reversed configuration must list the suite.');

            $ascending = $listed;
            sort($ascending, SORT_STRING);
            self::assertSame(
                array_reverse($ascending),
                $listed,
                'The classes must be listed in reverse order, which is what varies class order.',
            );
        } finally {
            @unlink($path);
        }
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
     * Chromium executes each right-to-left locale project while breadth excludes that locale-only file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBrowserProjectSelectionIsDeterministicAcrossLocaleAndEngineAxes(): void
    {
        $package = $this->decode('package.json');
        $scripts = $package['scripts'] ?? null;
        self::assertIsArray($scripts);
        $merge = $scripts['test:browser'] ?? null;
        self::assertIsString($merge);
        foreach (
            [
                'desktop-chromium-he',
                'desktop-chromium-ar',
                'mobile-chromium-he',
                'mobile-chromium-ar',
            ] as $project
        ) {
            self::assertStringContainsString('--project=' . $project, $merge);
        }

        $configuration = $this->contents('playwright.config.ts');
        foreach (['desktop-firefox', 'desktop-webkit'] as $project) {
            self::assertMatchesRegularExpression(
                sprintf(
                    "/name: '%s',\\n\\s+testIgnore: rightToLeftSpec,\\n\\s+ignoreSnapshots: true/",
                    preg_quote($project, '/'),
                ),
                $configuration,
            );
        }
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
