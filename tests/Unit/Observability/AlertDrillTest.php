<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Observability;

use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Tools\Observability\AlertDrill;
use Kumwe\App\Tools\Observability\AlertRule;
use Kumwe\App\Tools\Observability\AlertDrills;
use Kumwe\App\Tools\Observability\DrillEvaluator;
use Kumwe\App\Tools\Observability\DrillHost;
use Kumwe\App\Tools\Observability\DrillTimeline;
use Kumwe\App\Tools\Observability\Exposition;
use Kumwe\App\Tools\Observability\MetricInventory;
use Kumwe\App\Tools\Observability\PromtoolTests;
use Kumwe\App\Tools\Observability\RuleGate;
use Kumwe\App\Tools\Observability\RuleViolation;
use Kumwe\App\Tools\Observability\SyntheticProbe;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the alert-drill machinery that turns real scrapes into promtool evidence.
 *
 * The subjects are `tools/Observability/Exposition.php`, `DrillTimeline.php`, `DrillEvaluator.php`,
 * `DrillHost.php` and the catalogue in `AlertDrills.php`. The drills themselves run against a real application
 * in the observability workflow; these tests pin what their evidence means: a scrape is replayed exactly, a
 * vanished series gets a staleness marker, recorded instants keep their age and order on the synthetic clock,
 * a drill must declare healthy, firing and cleared with the exact labels and runbook annotation, every page
 * alert has exactly one drill whose runbook section prescribes the recovery it performs, and the host only
 * stops the processes it started.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class AlertDrillTest extends TestCase
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
     * The parser reads what `/metrics` serves and the renderer writes promtool's notation back.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExpositionRoundTripsTheScrapeFormat(): void
    {
        $samples = Exposition::parse(implode("\n", [
            '# HELP kumwe_ready Whether this process is ready.',
            '# TYPE kumwe_ready gauge',
            'kumwe_ready 1',
            'kumwe_http_requests_total{method="GET",status="5xx"} 17 1790000000000',
            'kumwe_label_escapes{path="a\\"b\\\\c\\nd"} +Inf',
            'kumwe_negative{volume="media"} -2.5e0',
            '',
        ]), 'fixture');

        self::assertSame(['name' => 'kumwe_ready', 'labels' => [], 'value' => 1.0], $samples[0]);
        self::assertSame(['method' => 'GET', 'status' => '5xx'], $samples[1]['labels']);
        self::assertSame(17.0, $samples[1]['value']);
        self::assertSame("a\"b\\c\nd", $samples[2]['labels']['path']);
        self::assertSame(INF, $samples[2]['value']);
        self::assertSame(-2.5, $samples[3]['value']);
        self::assertSame(
            'up{instance="127.0.0.1:1",job="kumwe",path="a\\"b"}',
            Exposition::series('up', ['path' => 'a"b', 'job' => 'kumwe', 'instance' => '127.0.0.1:1']),
        );
        self::assertSame('kumwe_ready', Exposition::series('kumwe_ready', []));
        self::assertSame(
            ['1790000000', '0.25', '-3', 'Inf', '-Inf', 'NaN', '0'],
            array_map(
                [Exposition::class, 'value'],
                [1_790_000_000.0, 0.25, -3.0, INF, -INF, NAN, -0.0000001],
            ),
        );

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('line 1 is not a sample');
        Exposition::parse('not a sample line at all', 'fixture');
    }

    /**
     * A malformed label set or value is refused rather than guessed at.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExpositionRefusesMalformedLabelsAndValues(): void
    {
        $cases = ['kumwe_ready{volume=media} 1' => 'malformed label set', 'kumwe_ready one' => 'non-numeric value one'];
        foreach ($cases as $line => $message) {
            try {
                Exposition::parse($line, 'fixture');
                self::fail(sprintf('%s was accepted.', $line));
            } catch (RuleViolation $violation) {
                self::assertStringContainsString($message, $violation->getMessage());
            }
        }
    }

    /**
     * The timeline replays scrapes exactly, marks a vanished series stale once, and holds the last scrape.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheTimelineReplaysScrapesWithStalenessAndHolds(): void
    {
        $target = ['instance' => '127.0.0.1:1', 'job' => 'kumwe'];
        $timeline = new DrillTimeline(['up', 'kumwe_ready']);
        $timeline->observe('healthy', 100.0, [
            ['name' => 'up', 'labels' => $target, 'value' => 1.0],
            ['name' => 'kumwe_ready', 'labels' => $target, 'value' => 1.0],
            ['name' => 'kumwe_ignored', 'labels' => $target, 'value' => 9.0],
        ]);
        $timeline->expect('healthy', false);
        $timeline->observe('induced', 101.0, [['name' => 'up', 'labels' => $target, 'value' => 0.0]]);
        $timeline->observe('induced', 102.0, [['name' => 'up', 'labels' => $target, 'value' => 0.0]]);
        $timeline->hold('induced', 2, 103.0);
        $timeline->expect('firing', true);
        $timeline->observe('recovered', 104.0, [
            ['name' => 'up', 'labels' => $target, 'value' => 1.0],
            ['name' => 'kumwe_ready', 'labels' => $target, 'value' => 1.0],
        ]);
        $timeline->expect('cleared', false);

        self::assertSame([
            ['series' => 'kumwe_ready{instance="127.0.0.1:1",job="kumwe"}', 'values' => '1 stale _ _ _ 1'],
            ['series' => 'up{instance="127.0.0.1:1",job="kumwe"}', 'values' => '1 0 0 0 0 1'],
        ], $timeline->series());
        self::assertSame([
            ['name' => 'healthy', 'at' => 0, 'fires' => false, 'phase' => 'healthy'],
            ['name' => 'firing', 'at' => 240, 'fires' => true, 'phase' => 'induced'],
            ['name' => 'cleared', 'at' => 300, 'fires' => false, 'phase' => 'recovered'],
        ], $timeline->checkpoints());
        self::assertSame([
            'healthy' => ['from' => 0, 'to' => 0, 'observed' => 1, 'held' => 0],
            'induced' => ['from' => 60, 'to' => 240, 'observed' => 2, 'held' => 2],
            'recovered' => ['from' => 300, 'to' => 300, 'observed' => 1, 'held' => 0],
        ], $timeline->phases());
        self::assertSame(1.0, $timeline->latest('up', $target));
        self::assertNull($timeline->latest('kumwe_ignored', $target), 'Series the alert does not read are dropped.');
        self::assertSame('1m', $timeline->interval());
    }

    /**
     * A drill replays only the series it owns, so a real condition elsewhere on the host cannot decide it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATimelineReplaysOnlyTheSeriesItsDrillOwns(): void
    {
        $timeline = new DrillTimeline(['kumwe_storage_free_bytes', 'up'], 60, ['volume' => 'media']);
        $timeline->observe('healthy', 1.0, [
            ['name' => 'kumwe_storage_free_bytes', 'labels' => ['volume' => 'media'], 'value' => 50.0],
            ['name' => 'kumwe_storage_free_bytes', 'labels' => ['volume' => 'private'], 'value' => 1.0],
            ['name' => 'up', 'labels' => ['job' => 'kumwe'], 'value' => 1.0],
        ]);

        self::assertSame(
            ['kumwe_storage_free_bytes{volume="media"}', 'up{job="kumwe"}'],
            array_column($timeline->series(), 'series'),
        );
        self::assertSame(['volume' => 'media'], AlertDrills::catalogue()['storage-nearly-full']->scope);
    }

    /**
     * Recorded instants keep their age and order on the synthetic clock, and work after a hold lands after it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecordedInstantsKeepTheirAgeAndOrderOnTheSyntheticClock(): void
    {
        $labels = ['operation' => 'backup'];
        $name = 'kumwe_recovery_last_success_timestamp_seconds';
        $timeline = new DrillTimeline([$name]);
        $timeline->observe('healthy', 1_000.0, [['name' => $name, 'labels' => $labels, 'value' => 998.0]]);
        $timeline->observe('healthy', 1_001.0, [['name' => $name, 'labels' => $labels, 'value' => 998.0]]);
        $timeline->hold('induced', 3, 1_002.0);
        $timeline->observe('recovered', 1_010.0, [['name' => $name, 'labels' => $labels, 'value' => 1_005.0]]);

        self::assertSame(-2.0, $timeline->synthetic(998.0), 'Two seconds before the first observation.');
        self::assertSame(60.5, $timeline->synthetic(1_001.5), 'Between two observations, after the earlier one.');
        self::assertSame(243.0, $timeline->synthetic(1_005.0), 'Work done after a hold is placed after the hold.');
        $sameSecond = new DrillTimeline([$name]);
        $sameSecond->observe('healthy', 2_000.2, []);
        $sameSecond->hold('induced', 5, 2_000.9);
        self::assertSame(300.0, $sameSecond->synthetic(2_000.0), 'A whole second recorded after a hold follows it.');
        $gap = new DrillTimeline([$name]);
        $gap->observe('a', 1_000.0, []);
        $gap->observe('b', 1_200.0, []);
        self::assertSame(59.0, $gap->synthetic(1_150.0), 'Never at or past the next anchored tick.');
        self::assertSame(80.0, $gap->synthetic(1_220.0), 'After the last anchor, the real offset is kept.');
        self::assertSame(-1_000.0, $timeline->synthetic(0.0), '"Never recorded" is as old as the epoch really is.');
        self::assertSame(
            [['series' => $name . '{operation="backup"}', 'values' => '-2 -2 -2 -2 -2 243']],
            $timeline->series(),
        );
        self::assertSame(
            ['induced' => ['from' => 120, 'to' => 240, 'observed' => 0, 'held' => 3]],
            array_intersect_key($timeline->phases(), ['induced' => true]),
        );

        foreach (
            [
                static fn () => (new DrillTimeline([$name]))->hold('induced', 1, 1.0),
                static fn () => (new DrillTimeline([$name]))->expect('healthy', false),
                static function () use ($timeline): void {
                    $timeline->observe('late', 500.0, []);
                },
                static function () use ($timeline): void {
                    $timeline->hold('late', 1, 500.0);
                },
                static fn () => new DrillTimeline([$name], 0),
            ] as $refused
        ) {
            try {
                $refused();
                self::fail('An out-of-order or empty timeline operation was accepted.');
            } catch (RuleViolation $violation) {
                self::assertStringStartsWith('timeline:', $violation->getMessage());
            }
        }
    }

    /**
     * The evaluator demands healthy, firing and cleared, with exact labels and the rendered runbook link.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheEvaluatorExpectsExactLabelsAndTheRunbookLink(): void
    {
        $root = dirname(__DIR__, 3);
        $drill = AlertDrills::catalogue()['restore-failed'];
        $timeline = self::shaped(['healthy' => false, 'firing' => true, 'cleared' => false]);
        $work = sys_get_temp_dir() . '/kumwe-drill-' . bin2hex(random_bytes(6));
        mkdir($work, 0700);
        $promtool = $work . '/promtool';
        file_put_contents($promtool, "#!/bin/sh\necho \"promtool $*\"\ntest \"\$PROMTOOL_VERDICT\" != fail\n");
        chmod($promtool, 0700);
        $evaluator = new DrillEvaluator(
            $root . '/deploy/observability/alerts.yaml',
            self::tests(),
            $promtool,
            $work . '/fixtures',
        );

        $document = $evaluator->document($drill, $timeline, [['operation' => 'restore_verify']], 'alerts.yaml');
        $cases = $document['tests'][0]['alert_rule_test'];
        self::assertSame(['0m', '1m', '2m'], array_column($cases, 'eval_time'));
        self::assertSame([], $cases[0]['exp_alerts']);
        self::assertSame([], $cases[2]['exp_alerts']);
        self::assertSame(
            ['component' => 'recovery', 'operation' => 'restore_verify', 'severity' => 'page'],
            $cases[1]['exp_alerts'][0]['exp_labels'],
        );
        self::assertSame(
            'docs/operations/runbooks.md#kumwerestorefailed',
            $cases[1]['exp_alerts'][0]['exp_annotations']['runbook'],
        );
        self::assertSame(
            'The latest Kumwe restore_verify failed.',
            $cases[1]['exp_alerts'][0]['exp_annotations']['summary'],
        );

        $result = $evaluator->evaluate($drill, $timeline, [['operation' => 'restore_verify']]);
        self::assertSame(0, $result['exit']);
        self::assertSame('promtool test rules ' . $work . '/fixtures/restore-failed.test.yaml', $result['output']);
        self::assertStringStartsWith('# Alert drill restore-failed', (string) file_get_contents($result['fixture']));

        foreach (
            [
                'order' => [
                    self::shaped(['firing' => true, 'healthy' => false, 'cleared' => false]),
                    [['operation' => 'restore_verify']],
                    'must declare healthy',
                ],
                'labels' => [$timeline, [['instance' => 'x']], 'the scenario states labels'],
                'instances' => [$timeline, [], 'at least one firing instance'],
            ] as $case => [$shape, $firing, $message]
        ) {
            try {
                $evaluator->document($drill, $shape, $firing, 'alerts.yaml');
                self::fail(sprintf('The %s mistake was accepted.', $case));
            } catch (RuleViolation $violation) {
                self::assertStringContainsString($message, $violation->getMessage());
            }
        }
        array_map('unlink', [$result['fixture'], $promtool]);
        rmdir($work . '/fixtures');
        rmdir($work);
    }

    /**
     * Every page alert has exactly one drill, and its runbook section names it and prescribes its recovery.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryPageAlertHasOneDrillWhoseRunbookPrescribesTheRecovery(): void
    {
        $root = dirname(__DIR__, 3);
        $alerts = RuleGate::load($root . '/deploy/observability/alerts.yaml');
        $drills = AlertDrills::catalogue();

        self::assertSame([], AlertDrills::problems($root, $alerts));
        $pages = array_keys(array_filter($alerts, static fn ($alert): bool => $alert->severity() === 'page'));
        $drilled = array_map(static fn (AlertDrill $drill): string => $drill->alert, array_values($drills));
        sort($pages);
        sort($drilled);
        self::assertSame($pages, $drilled);
        foreach ($drills as $id => $drill) {
            self::assertSame($id, $drill->id);
            $rule = $alerts[$drill->alert];
            $section = AlertDrills::runbook($root, $rule);
            self::assertNotNull($section, sprintf('%s links a real runbook section.', $drill->alert));
            self::assertSame($drill->alert, $section['heading']);
            self::assertStringContainsString($drill->action, AlertDrills::act($section['text']));
            $metrics = AlertDrills::metrics($rule, $drill);
            self::assertSame(AlertDrills::WITNESSES, array_slice($metrics, 0, 3));
            self::assertSame(count($metrics), count(array_unique($metrics)));
        }
        self::assertContains(
            'kumwe_recovery_last_failure_timestamp_seconds',
            AlertDrills::metrics($alerts['KumweBackupStale'], $drills['backup-stale']),
        );
        $stale = $alerts['KumweBackupStale'];
        self::assertNull(AlertDrills::runbook($root, new AlertRule(
            $stale->group,
            $stale->name,
            $stale->expression,
            $stale->tree,
            $stale->for,
            $stale->labels,
            ['runbook' => 'https://runbooks.example/backup'] + $stale->annotations,
        )));
        self::assertSame('', AlertDrills::act("## Heading\n\n- **Check:** something.\n"));
    }

    /**
     * The catalogue gate refuses a runbook that stops prescribing a drill's recovery or promises a missing drill.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCatalogueGateRefusesARunbookThatDriftsFromTheDrills(): void
    {
        $source = dirname(__DIR__, 3);
        $root = sys_get_temp_dir() . '/kumwe-drill-root-' . bin2hex(random_bytes(6));
        mkdir($root . '/docs/operations', 0700, true);
        $runbook = (string) file_get_contents($source . '/docs/operations/runbooks.md');
        self::assertStringContainsString('start the worker (`bin/kumwe queue:work`)', $runbook);
        file_put_contents($root . '/docs/operations/runbooks.md', str_replace(
            ['start the worker (`bin/kumwe queue:work`)', '- **Clears when:** no expired lease remains.'],
            [
                'start the worker',
                "- **Clears when:** no expired lease remains.\n- **Drill:** `lease-expired` expires a lease.",
            ],
            $runbook,
        ));
        $alerts = RuleGate::load($source . '/deploy/observability/alerts.yaml');

        $problems = AlertDrills::problems($root, $alerts);
        unlink($root . '/docs/operations/runbooks.md');
        rmdir($root . '/docs/operations');
        rmdir($root . '/docs');
        rmdir($root);

        self::assertContains(
            'KumweNoLiveWorker: its runbook section must name the drill `no-live-worker` and prescribe '
            . '"bin/kumwe queue:work" in its Act step',
            $problems,
        );
        self::assertContains(
            'docs/operations/runbooks.md: names the drill `lease-expired`, which does not exist',
            $problems,
        );
        unset($alerts['KumweNoLiveWorker']);
        self::assertContains(
            'drill no-live-worker names KumweNoLiveWorker, which is not a page-severity alert',
            AlertDrills::problems($source, $alerts),
        );
    }

    /**
     * The host hands children the drill environment and stops only the processes it started.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheHostRunsAndStopsOnlyItsOwnProcesses(): void
    {
        $root = dirname(__DIR__, 3);
        $work = sys_get_temp_dir() . '/kumwe-drill-host-' . bin2hex(random_bytes(6));
        $host = new DrillHost($root, $work, 18999, [
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => 'db.internal',
            'DB_NAME' => 'kumwe',
            'DB_PASSWORD' => 'database-secret',
            'KUMWE_METRICS_TOKEN' => 'inherited-token',
            'PATH' => (string) getenv('PATH'),
            'REDIS_NAMESPACE' => 'kumwe.ci',
        ]);

        self::assertSame(['instance' => '127.0.0.1:18999', 'job' => 'kumwe'], $host->target());
        self::assertSame('kumwe.ci.drills', $host->env('REDIS_NAMESPACE'));
        self::assertSame('', $host->env('KUMWE_METRICS_TOKEN'), 'An inline token would override the drill token.');
        self::assertSame('0600', substr(sprintf('%o', fileperms($work . '/secrets/metrics-token')), -4));
        $recovery = $host->recoveryEnvironment(true);
        self::assertSame('pgsql', $recovery['KUMWE_DB_DRIVER']);
        self::assertSame('kumwe_', $recovery['KUMWE_DB_TABLE_PREFIX']);
        self::assertSame($work . '/secrets/wrong-database-password', $recovery['KUMWE_DB_PASSWORD_FILE']);
        self::assertSame('database-secret', file_get_contents($host->recoveryEnvironment()['KUMWE_DB_PASSWORD_FILE']));
        self::assertNotSame('database-secret', file_get_contents($recovery['KUMWE_DB_PASSWORD_FILE']));

        $result = $host->run([
            PHP_BINARY,
            '-r',
            'echo getenv("REDIS_NAMESPACE"), " ", getenv("APP_TRUSTED_HOSTS"); exit(3);',
        ]);
        self::assertSame(['exit' => 3, 'output' => 'kumwe.ci.drills 127.0.0.1,localhost'], $result);
        $override = $host->run([PHP_BINARY, '-r', 'echo getenv("DB_HOST");'], ['DB_HOST' => 'override']);
        self::assertSame('override', $override['output']);

        $host->spawn('sleeper', [PHP_BINARY, '-r', 'fwrite(STDERR, "sleeping\n"); sleep(30);']);
        self::assertTrue($host->running('sleeper'));
        usleep(300_000);
        self::assertStringContainsString('sleeping', $host->logTail('sleeper'));
        self::assertNotNull($host->stop('sleeper', 5.0));
        self::assertFalse($host->running('sleeper'));
        self::assertNull($host->stop('sleeper'), 'A process the host no longer holds is never signalled.');
        self::assertNull($host->stop('never-started'));
        self::assertSame(0, $host->request('GET', 'http://127.0.0.1:9/')['status']);
        self::assertNull($host->scrape(), 'Nothing listens on the drill port, so the scrape fails.');
        $host->shutdown();

        foreach (glob($work . '/{logs,secrets,backups,empty/*,empty}/*', GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        foreach (['/logs', '/secrets', '/backups', '/empty', ''] as $directory) {
            @rmdir($work . $directory);
        }
    }

    /**
     * Build a timeline with one tick per checkpoint, in the given order.
     *
     * @param   array<string, bool>  $checkpoints  Checkpoint names and whether the alert fires there.
     *
     * @return  DrillTimeline  The timeline.
     *
     * @since   2.0.0
     */
    private static function shaped(array $checkpoints): DrillTimeline
    {
        $timeline = new DrillTimeline(['kumwe_recovery_last_failure_timestamp_seconds']);
        $second = 1_000.0;
        foreach ($checkpoints as $name => $fires) {
            $timeline->observe($name, $second++, [[
                'name' => 'kumwe_recovery_last_failure_timestamp_seconds',
                'labels' => ['operation' => 'restore_verify'],
                'value' => $fires ? $second : 0.0,
            ]]);
            $timeline->expect($name, $fires);
        }

        return $timeline;
    }

    /**
     * Build the expectation builder over the shipped rules.
     *
     * @return  PromtoolTests  The builder.
     *
     * @since   2.0.0
     */
    private static function tests(): PromtoolTests
    {
        $root = dirname(__DIR__, 3);
        $contract = ObservabilityContract::load($root);
        $inventory = MetricInventory::create(MetricCatalog::create($contract, '2.0.0', 'http'), SyntheticProbe::CHECKS);
        $alerts = RuleGate::load($root . '/deploy/observability/alerts.yaml');

        $gate = new RuleGate($root, $inventory, $contract->forbiddenLabels);

        return new PromtoolTests($alerts, $gate->carried($alerts));
    }
}
