<?php

/**
 * Run the operator drills: prove every page-severity alert fires on a real induced condition and clears again.
 *
 * Against the database and Redis the environment configures, the command materializes the extension runtime,
 * starts the runtime watcher and a web server on the loopback interface with the metrics endpoint enabled
 * behind a one-run scrape token, and then runs each drill in `tools/Observability/AlertDrills.php`: observe the
 * healthy replica, induce the condition, observe, recover as the runbook says, observe. Each drill's scrapes
 * are replayed to `promtool test rules` against the committed `deploy/observability/alerts.yaml`, expecting the
 * alert quiet, then firing with its exact labels and runbook annotation, then quiet again.
 *
 * Evidence is written to `<output>/drills.json` and one promtool fixture per drill under `<output>/drills/`.
 * The drills mutate the installation they run against (heartbeats, status files, sign-in counters, backups
 * under `<output>/work`) and restore what they change, but they are meant for a disposable database: CI and
 * `tools/alert-drill.sh` run them there.
 *
 * Usage:
 *   php tools/alert-drill.php --promtool=PATH [--only=ID,ID] [--port=18480] [--output=build/observability]
 *       [--require-all] [--list]
 *
 * Exit status: 0 when every selected drill passed (or was skipped without --require-all), 1 otherwise, 64 for a
 * usage error.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Tools\Observability\AlertDrills;
use Kumwe\App\Tools\Observability\DrillEvaluator;
use Kumwe\App\Tools\Observability\DrillHost;
use Kumwe\App\Tools\Observability\DrillTimeline;
use Kumwe\App\Tools\Observability\MetricInventory;
use Kumwe\App\Tools\Observability\PromtoolTests;
use Kumwe\App\Tools\Observability\RuleGate;
use Kumwe\App\Tools\Observability\SyntheticProbe;

require_once __DIR__ . '/Observability/bootstrap.php';

$root = dirname(__DIR__);
$usage = "Usage: php tools/alert-drill.php --promtool=PATH [--only=ID,ID] [--port=N] [--output=DIR] [--require-all] [--list]\n";
$options = ['promtool' => getenv('PROMTOOL') ?: 'promtool', 'port' => '18480', 'output' => $root . '/build/observability'];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--require-all' || $argument === '--list') {
        $options[substr($argument, 2)] = '1';
        continue;
    }
    if (preg_match('/^--(promtool|only|port|output)=(.+)$/D', $argument, $match) !== 1) {
        fwrite(STDERR, $usage);
        exit(64);
    }
    $options[$match[1]] = $match[2];
}
$drills = AlertDrills::catalogue();
if (isset($options['list'])) {
    foreach ($drills as $drill) {
        printf("%-30s %s\n", $drill->id, $drill->alert);
    }
    exit(0);
}
$port = (int) $options['port'];
if ($port < 1024 || $port > 65000) {
    fwrite(STDERR, "--port must be between 1024 and 65000.\n");
    exit(64);
}
$selected = isset($options['only']) ? explode(',', $options['only']) : array_keys($drills);
foreach ($selected as $id) {
    if (!isset($drills[$id])) {
        fwrite(STDERR, sprintf("Unknown drill %s; --list shows them.\n", $id));
        exit(64);
    }
}

$contract = ObservabilityContract::load($root);
$inventory = MetricInventory::create(MetricCatalog::create($contract, 'drill', 'http'), SyntheticProbe::CHECKS);
$gate = new RuleGate($root, $inventory, $contract->forbiddenLabels);
$alerts = RuleGate::load($root . '/deploy/observability/alerts.yaml');
$problems = AlertDrills::problems($root, $alerts);
if ($problems !== []) {
    fwrite(STDERR, 'The drill catalogue and the runbook disagree: ' . implode('; ', $problems) . ".\n");
    exit(1);
}

$output = rtrim($options['output'], '/');
if (!str_starts_with($output, '/')) {
    $output = $root . '/' . $output;
}
$promtool = $options['promtool'];
$version = [];
exec(escapeshellarg($promtool) . ' --version 2>&1', $version, $status);
if ($status !== 0) {
    fwrite(STDERR, sprintf("promtool is not runnable at %s.\n", $promtool));
    exit(1);
}
$environment = getenv();
$host = new DrillHost($root, $output . '/work', $port, $environment);
$evaluator = new DrillEvaluator(
    $root . '/deploy/observability/alerts.yaml',
    new PromtoolTests($alerts, $gate->carried($alerts)),
    $promtool,
    $output . '/drills',
);
$results = [];
$failed = false;
try {
    AlertDrills::baseline($host);
    foreach ($selected as $id) {
        $drill = $drills[$id];
        $rule = $alerts[$drill->alert] ?? null;
        $started = microtime(true);
        $result = [
            'id' => $drill->id,
            'alert' => $drill->alert,
            'severity' => $rule?->severity(),
            'induced' => $drill->induce,
            'recovered' => $drill->recover,
            'runbook' => null,
            'status' => 'failed',
            'reason' => null,
            'checkpoints' => [],
            'phases' => [],
            'firing_instances' => [],
            'fixture' => null,
            'promtool' => null,
        ];
        fwrite(STDOUT, sprintf("drill %s (%s) ... ", $drill->id, $drill->alert));
        try {
            if ($rule === null) {
                throw new RuntimeException('the alert is not declared in alerts.yaml');
            }
            $section = AlertDrills::runbook($root, $rule);
            if ($section === null) {
                throw new RuntimeException('the runbook annotation does not resolve to a section');
            }
            $result['runbook'] = [
                'link' => $section['anchor'],
                'heading' => $section['heading'],
                'action' => $drill->action,
                'names_drill' => str_contains($section['text'], '`' . $drill->id . '`'),
                'prescribes_action' => str_contains(AlertDrills::act($section['text']), $drill->action),
            ];
            if (!$result['runbook']['names_drill'] || !$result['runbook']['prescribes_action']) {
                throw new RuntimeException(sprintf(
                    'the runbook section %s must name the drill `%s` and prescribe "%s" in its Act step',
                    $section['heading'],
                    $drill->id,
                    $drill->action,
                ));
            }
            $skip = $drill->skip === null ? null : ($drill->skip)($host);
            if ($skip !== null) {
                $result['status'] = 'skipped';
                $result['reason'] = $skip;
            } else {
                $timeline = new DrillTimeline(AlertDrills::metrics($rule, $drill), 60, $drill->scope);
                ($drill->steps)($host, $timeline);
                $firing = ($drill->firing)($host);
                $evaluation = $evaluator->evaluate($drill, $timeline, $firing);
                $result['checkpoints'] = $timeline->checkpoints();
                $result['phases'] = $timeline->phases();
                $result['firing_instances'] = $firing;
                $result['fixture'] = substr($evaluation['fixture'], strlen($root) + 1);
                $result['promtool'] = ['exit' => $evaluation['exit'], 'output' => $evaluation['output']];
                if ($evaluation['exit'] === 0) {
                    $result['status'] = 'passed';
                } else {
                    $result['reason'] = 'promtool did not confirm healthy, firing and cleared';
                }
            }
        } catch (Throwable $exception) {
            $result['reason'] = $exception->getMessage();
        } finally {
            try {
                if ($drill->restore !== null) {
                    ($drill->restore)($host);
                }
                AlertDrills::baseline($host);
            } catch (Throwable $exception) {
                $result['status'] = 'failed';
                $result['reason'] = trim(($result['reason'] ?? '') . ' Restoring the baseline failed: ' . $exception->getMessage());
            }
        }
        $result['seconds'] = round(microtime(true) - $started, 1);
        $failed = $failed || $result['status'] === 'failed' || ($result['status'] === 'skipped' && isset($options['require-all']));
        fwrite(STDOUT, sprintf("%s (%.1fs)%s\n", $result['status'], $result['seconds'], $result['reason'] === null ? '' : ': ' . $result['reason']));
        $results[] = $result;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'The drill host could not be prepared: ' . $exception->getMessage() . "\n");
    fwrite(STDERR, $host->logTail('web') . $host->logTail('watcher'));
    $failed = true;
} finally {
    $host->shutdown();
}

$counts = array_count_values(array_column($results, 'status'));
$evidence = [
    'schema' => 'kumwe-alert-drills/v1',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'engine' => (string) ($environment['DB_DRIVER'] ?? 'mariadb'),
    'database_server_version' => (string) ($environment['DB_SERVER_VERSION'] ?? ''),
    'php' => PHP_VERSION,
    'promtool' => trim((string) ($version[0] ?? '')),
    'rule_file' => 'deploy/observability/alerts.yaml',
    'summary' => [
        'passed' => $counts['passed'] ?? 0,
        'failed' => $counts['failed'] ?? 0,
        'skipped' => $counts['skipped'] ?? 0,
    ],
    'drills' => $results,
];
if (!is_dir($output)) {
    mkdir($output, 0775, true);
}
file_put_contents(
    $output . '/drills.json',
    json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
printf(
    "Kumwe alert drills on %s: %d passed, %d failed, %d skipped; evidence in %s/drills.json.\n",
    $evidence['engine'],
    $evidence['summary']['passed'],
    $evidence['summary']['failed'],
    $evidence['summary']['skipped'],
    $output,
);
exit($failed || $results === [] ? 1 : 0);
