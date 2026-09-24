<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Observability;

use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Tools\Observability\DashboardGate;
use Kumwe\App\Tools\Observability\InhibitionRules;
use Kumwe\App\Tools\Observability\MetricInventory;
use Kumwe\App\Tools\Observability\PromtoolTests;
use Kumwe\App\Tools\Observability\RuleGate;
use Kumwe\App\Tools\Observability\RuleViolation;
use Kumwe\App\Tools\Observability\RuleYaml;
use Kumwe\App\Tools\Observability\SyntheticProbe;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the offline gate over the shipped alert rules, inhibition rules, runbooks, dashboards and scenarios.
 *
 * The shipped files must pass; each class of mistake the gate exists for — a missing runbook section, a
 * template naming a label the alert drops, an open label vocabulary, an inhibition `equal` label absent on one
 * side, a business panel that counts retries — must be refused on a copy of the files with that one mistake.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class RuleGateTest extends TestCase
{
    /**
     * Load the tooling classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Observability/bootstrap.php';
    }

    /**
     * The shipped rules, inhibitions, runbooks and dashboards pass, and the committed promtool file is fresh.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheShippedObservabilityFilesPass(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertSame([], self::gate($root)->problems());
        self::assertSame([], self::dashboards($root)->problems());
        $alerts = RuleGate::load($root . '/deploy/observability/alerts.yaml');
        $pages = array_filter($alerts, static fn ($alert): bool => $alert->severity() === 'page');
        self::assertGreaterThanOrEqual(10, count($pages), 'Every critical condition pages.');
        foreach (
            [
                'KumweNoLiveWorker', 'KumweBackupStale', 'KumweRestoreFailed', 'KumweStorageNearlyFull',
                'KumweExtensionRuntimeUntrusted', 'KumweAuthenticationFailureBurst', 'KumweDatabaseReplicaLagHigh',
                'KumweDeadlocksFrequent', 'KumweRetentionBacklogStale', 'KumweStorageWillFillSoon',
                'KumweInboxBacklogAging', 'KumweJobRetryRateHigh', 'KumweDatabaseConnectionsSaturated',
            ] as $required
        ) {
            self::assertArrayHasKey($required, $alerts, sprintf('%s covers a listed P7-D condition.', $required));
        }
        $process = proc_open(
            [PHP_BINARY, $root . '/tools/verify-alert-rules.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertStringContainsString('45 alerts (10 page, each with a drill)', $output);
    }

    /**
     * Each class of rule mistake is refused on an otherwise valid copy of the files.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachClassOfRuleMistakeIsRefused(): void
    {
        $cases = [
            'runbook section' => [
                'runbook: docs/operations/runbooks.md#kumwebackupstale',
                'runbook: docs/operations/runbooks.md#kumwebackupfailing',
                'KumweBackupStale: the runbook anchor must be',
            ],
            'template label' => [
                'summary: "Kumwe {{ $labels.volume }} storage on {{ $labels.instance }} has less than 5% free."',
                'summary: "Kumwe {{ $labels.store }} storage on {{ $labels.instance }} has less than 5% free."',
                'interpolates store, which the alert does not carry',
            ],
            'severity vocabulary' => [
                "severity: ticket\n          component: security\n        annotations:\n"
                    . '          summary: "Kumwe is throttling sign-in attempts."',
                "severity: urgent\n          component: security\n        annotations:\n"
                    . '          summary: "Kumwe is throttling sign-in attempts."',
                'KumweAuthenticationThrottling: severity must be one of page, ticket',
            ],
            'unknown series' => [
                'expr: max(kumwe_inbox_poison) > 0',
                'expr: max(kumwe_inbox_quarantined) > 0',
                'selects kumwe_inbox_quarantined, which no catalogue or probe declares',
            ],
            'short for' => [
                "expr: max(kumwe_outbox_dead) > 0\n        for: 15m",
                "expr: max(kumwe_outbox_dead) > 0\n        for: 30s",
                'KumweOutboxDeadLetters: `for` must be at least one minute',
            ],
            'too many alert label sets' => [
                'expr: max(kumwe_jobs_dead_lettered) > 0',
                'expr: max by (method, status) (kumwe_http_requests_total) > 0',
                'KumweJobsDeadLettered: can produce 48 alert label sets, more than 32',
            ],
            'unbounded result' => [
                'expr: max(kumwe_jobs_dead_lettered) > 0',
                'expr: max by (store) (kumwe_jobs_dead_lettered) > 0',
                'the result carries store, which no queried series bounds',
            ],
        ];
        foreach ($cases as $case => [$search, $replace, $expected]) {
            $root = self::copy(['deploy/observability/alerts.yaml' => [$search, $replace]]);
            self::assertStringContainsString(
                $expected,
                implode("\n", self::gate($root)->problems()),
                sprintf('The %s mistake must be refused.', $case),
            );
        }
    }

    /**
     * An inhibition whose `equal` label one side lacks, or that names an unknown alert, is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsoundInhibitionsAreRefused(): void
    {
        $root = self::copy(['deploy/observability/alertmanager.yaml' => [
            "      - alertname=\"KumweBackupFailing\"\n",
            "      - alertname=\"KumweBackupFailing\"\n    equal: [instance]\n"
                . "  - source_matchers:\n      - alertname=\"KumweNoSuchAlert\"\n"
                . "    target_matchers:\n      - alertname=\"KumweBackupFailing\"\n",
        ]]);
        $problems = implode("\n", self::gate($root)->problems());

        self::assertStringContainsString('equal label instance is not carried by KumweBackupStale', $problems);
        self::assertStringContainsString('names the alert KumweNoSuchAlert', $problems);
        self::assertStringContainsString('its source matchers match no declared alert', $problems);
    }

    /**
     * Inhibition evaluation follows Alertmanager: equal labels, absence as equality, and no self-inhibition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInhibitionEvaluationFollowsAlertmanager(): void
    {
        $rules = InhibitionRules::fromConfiguration(RuleYaml::parse(
            (string) file_get_contents(dirname(__DIR__, 3) . '/deploy/observability/alertmanager.yaml'),
            'alertmanager.yaml',
        ), 'alertmanager.yaml');

        self::assertSame(['KumweReadinessFailing'], $rules->inhibited([
            ['alertname' => 'KumweExtensionRuntimeUntrusted', 'instance' => 'app-1'],
            ['alertname' => 'KumweReadinessFailing', 'instance' => 'app-1'],
        ]));
        self::assertSame([], $rules->inhibited([
            ['alertname' => 'KumweExtensionRuntimeUntrusted', 'instance' => 'app-1'],
            ['alertname' => 'KumweReadinessFailing', 'instance' => 'app-2'],
        ]), 'A different replica is not muted.');
        self::assertSame(['KumweBackupFailing'], $rules->inhibited([
            ['alertname' => 'KumweBackupStale', 'component' => 'recovery'],
            ['alertname' => 'KumweBackupFailing', 'component' => 'recovery'],
        ]));
        self::assertSame(['KumweJobQueueStalled', 'KumweOutboxBacklogAging'], $rules->inhibited([
            ['alertname' => 'KumweMetricsCollectionFailing', 'component' => 'observability'],
            ['alertname' => 'KumweJobQueueStalled', 'component' => 'queue'],
            ['alertname' => 'KumweOutboxBacklogAging', 'component' => 'integration'],
            ['alertname' => 'KumweAuthenticationFailureBurst', 'component' => 'security'],
        ]));
        self::assertSame(['KumweSyntheticProbeFailing'], $rules->inhibited([
            ['alertname' => 'KumweSyntheticProbeFailing', 'check' => 'liveness'],
            ['alertname' => 'KumweSyntheticProbeFailing', 'check' => 'readiness'],
        ]));
        self::assertSame([], $rules->inhibited([
            ['alertname' => 'KumweSyntheticProbeFailing', 'check' => 'liveness'],
        ]), 'An alert never inhibits itself.');
    }

    /**
     * Malformed inhibition files are refused before evaluation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedInhibitionFilesAreRefused(): void
    {
        foreach (
            [
                ['route: {}', 'no inhibit_rules are declared'],
                ["inhibit_rules:\n  - source_matchers:\n      - alertname=KumweX\n"
                    . "    target_matchers:\n      - a=\"b\"",
                    'is not a `label="value"` matcher'],
                ["inhibit_rules:\n  - source_matchers:\n      - a=\"b\"\n"
                    . "    target_matchers:\n      - a=\"b\"\n    matchers: []",
                    'unsupported keys matchers'],
                ["inhibit_rules:\n  - source_matchers:\n      - a=~\"(\"\n    target_matchers:\n      - a=\"b\"",
                    'not a valid regular expression'],
                ["inhibit_rules:\n  - target_matchers:\n      - a=\"b\"", 'needs at least one matcher'],
            ] as [$yaml, $expected]
        ) {
            try {
                InhibitionRules::fromConfiguration(RuleYaml::parse($yaml, 'am.yaml'), 'am.yaml');
                self::fail(sprintf('"%s" must be refused.', $expected));
            } catch (RuleViolation $violation) {
                self::assertStringContainsString($expected, $violation->getMessage());
            }
        }
    }

    /**
     * Promtool expectations come from the rule; a scenario with the wrong labels or a missing alert is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPromtoolExpectationsAreDerivedFromTheRule(): void
    {
        $root = dirname(__DIR__, 3);
        $alerts = RuleGate::load($root . '/deploy/observability/alerts.yaml');
        $builder = new PromtoolTests($alerts, self::gate($root)->carried($alerts));
        $group = $builder->group(
            'KumweStorageNearlyFull',
            [['series' => 'kumwe_storage_free_bytes{instance="a",job="kumwe",volume="media"}', 'values' => '1x5']],
            [['at' => '1m', 'fires' => false], ['at' => '5m', 'fires' => true]],
            ['instance' => 'a', 'job' => 'kumwe', 'volume' => 'media'],
        );
        $expected = $group['alert_rule_test'][1]['exp_alerts'][0];

        self::assertSame([], $group['alert_rule_test'][0]['exp_alerts']);
        self::assertSame('page', $expected['exp_labels']['severity']);
        self::assertSame('Kumwe media storage on a has less than 5% free.', $expected['exp_annotations']['summary']);
        self::assertSame('docs/operations/runbooks.md#kumwestoragenearlyfull', $expected['exp_annotations']['runbook']);
        self::assertStringContainsString("exp_labels:\n", PromtoolTests::yaml(['tests' => [$group]]));

        try {
            $builder->group('KumweStorageNearlyFull', [], [], ['instance' => 'a']);
            self::fail('Wrong labels must be refused.');
        } catch (RuleViolation $violation) {
            self::assertStringContainsString('but the alert carries', $violation->getMessage());
        }
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('has no promtool scenario');
        $builder->document(['scenarios' => []], '../alerts.yaml');
    }

    /**
     * A business panel counting retries and a transport panel plotting completed work are both refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheBusinessAndTransportSplitIsEnforced(): void
    {
        $root = self::copy([
            'deploy/observability/dashboards/kumwe-business-operations.json' => [
                'kumwe_queue_settlements_total{outcome=\"completed\"}',
                'kumwe_queue_settlements_total',
            ],
            'deploy/observability/dashboards/kumwe-transport-and-retries.json' => [
                'max(kumwe_jobs_lease_expired)',
                'sum(rate(kumwe_dispatch_settlements_total{outcome=\"completed\"}[5m]))',
            ],
        ]);
        $problems = implode("\n", self::dashboards($root)->problems());

        self::assertStringContainsString(
            'without selecting outcome="completed", so retries would inflate it',
            $problems,
        );
        self::assertStringContainsString('plots completed kumwe_dispatch_settlements_total', $problems);
        $root = self::copy(['deploy/observability/dashboards/kumwe-business-operations.json' => [
            'sum(rate(kumwe_document_lines_total[5m]))',
            'sum(rate(kumwe_transaction_failures_total[5m]))',
        ]]);
        self::assertStringContainsString(
            'reads the transport series kumwe_transaction_failures_total on the business dashboard',
            implode("\n", self::dashboards($root)->problems()),
        );
    }

    /**
     * Build the rule gate over a root.
     *
     * @param   string  $root  Repository or fixture root.
     *
     * @return  RuleGate  The gate.
     *
     * @since   2.0.0
     */
    private static function gate(string $root): RuleGate
    {
        $contract = ObservabilityContract::load(dirname(__DIR__, 3));

        return new RuleGate($root, self::inventory(), $contract->forbiddenLabels);
    }

    /**
     * Build the dashboard gate over a root.
     *
     * @param   string  $root  Repository or fixture root.
     *
     * @return  DashboardGate  The gate.
     *
     * @since   2.0.0
     */
    private static function dashboards(string $root): DashboardGate
    {
        $contract = ObservabilityContract::load(dirname(__DIR__, 3));

        return new DashboardGate($root, self::inventory(), $contract->forbiddenLabels);
    }

    /**
     * Build the series inventory over the shipped catalogue.
     *
     * @return  MetricInventory  The inventory.
     *
     * @since   2.0.0
     */
    private static function inventory(): MetricInventory
    {
        $contract = ObservabilityContract::load(dirname(__DIR__, 3));

        return MetricInventory::create(MetricCatalog::create($contract, '2.0.0', 'http'), SyntheticProbe::CHECKS);
    }

    /**
     * Copy the observability files and runbooks into a temporary root, applying one replacement per file.
     *
     * @param   array<string, array{string, string}>  $edits  Search and replacement per relative path.
     *
     * @return  string  Temporary root, removed after the test.
     *
     * @since   2.0.0
     */
    private static function copy(array $edits): string
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir() . '/kumwe-rules-' . bin2hex(random_bytes(6));
        $files = [
            'deploy/observability/alerts.yaml',
            'deploy/observability/alertmanager.yaml',
            'deploy/observability/dashboards/kumwe-business-operations.json',
            'deploy/observability/dashboards/kumwe-transport-and-retries.json',
            'deploy/observability/dashboards/kumwe-platform-health.json',
            'docs/operations/runbooks.md',
        ];
        foreach ($files as $file) {
            $contents = (string) file_get_contents($source . '/' . $file);
            if (isset($edits[$file])) {
                [$search, $replace] = $edits[$file];
                self::assertStringContainsString($search, $contents, sprintf('Fixture edit for %s is stale.', $file));
                $contents = str_replace($search, $replace, $contents);
            }
            @mkdir(dirname($root . '/' . $file), 0700, true);
            file_put_contents($root . '/' . $file, $contents);
        }
        register_shutdown_function(static function () use ($root, $files): void {
            foreach ($files as $file) {
                @unlink($root . '/' . $file);
            }
            $directories = [
                'deploy/observability/dashboards',
                'deploy/observability',
                'deploy',
                'docs/operations',
                'docs',
            ];
            foreach ($directories as $dir) {
                @rmdir($root . '/' . $dir);
            }
            @rmdir($root);
        });

        return $root;
    }
}
