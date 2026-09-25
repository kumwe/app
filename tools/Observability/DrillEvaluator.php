<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use RuntimeException;

/**
 * Evaluates the shipped alert rules over a drill's observed timeline with the real `promtool`.
 *
 * The fixture it writes is an ordinary promtool unit test: the committed `alerts.yaml`, the drill's replayed
 * series, and one `alert_rule_test` per checkpoint. A firing checkpoint expects every instance the drill names
 * with its exact labels and rendered annotations, so promtool itself proves the alert carries its runbook link;
 * a quiet checkpoint expects no instance of the alert at all. promtool's verdict is the drill's verdict.
 *
 * @since  2.0.0
 */
final readonly class DrillEvaluator
{
    /**
     * Bind the evaluator to the rule file, the expectation builder and the promtool binary.
     *
     * @param  string         $ruleFile  Absolute path of `deploy/observability/alerts.yaml`.
     * @param  PromtoolTests  $tests     Builds exact label and annotation expectations.
     * @param  string         $promtool  Path of the promtool binary.
     * @param  string         $fixtures  Directory the per-drill test files are written to.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $ruleFile,
        private PromtoolTests $tests,
        private string $promtool,
        private string $fixtures,
    ) {
    }

    /**
     * Build the promtool test document for one drill run.
     *
     * @param   AlertDrill                   $drill     The drill.
     * @param   DrillTimeline                $timeline  What it observed and declared.
     * @param   list<array<string, string>>  $firing    Label sets of every instance that must fire.
     * @param   string                       $ruleFile  Rule file path as the document should reference it.
     *
     * @return  array<string, mixed>  The document.
     *
     * @throws  RuleViolation  When the checkpoints are not healthy, firing and cleared in that order, or a label
     *          set does not match what the alert carries.
     *
     * @since   2.0.0
     */
    public function document(AlertDrill $drill, DrillTimeline $timeline, array $firing, string $ruleFile): array
    {
        $checkpoints = $timeline->checkpoints();
        $shape = array_map(static fn (array $checkpoint): string => $checkpoint['name'] . '=' . ($checkpoint['fires'] ? 'fires' : 'quiet'), $checkpoints);
        if ($shape !== ['healthy=quiet', 'firing=fires', 'cleared=quiet']) {
            throw RuleViolation::at($drill->id, sprintf(
                'a drill must declare healthy (quiet), firing (fires) and cleared (quiet) in order, not [%s]',
                implode(', ', $shape),
            ));
        }
        if ($firing === []) {
            throw RuleViolation::at($drill->id, 'a drill must name at least one firing instance');
        }
        $expected = array_map(fn (array $labels): array => $this->tests->expectation($drill->alert, $labels), $firing);
        $cases = [];
        foreach ($checkpoints as $checkpoint) {
            $cases[] = [
                'eval_time' => Duration::format($checkpoint['at']),
                'alertname' => $drill->alert,
                'exp_alerts' => $checkpoint['fires'] ? $expected : [],
            ];
        }

        return [
            'rule_files' => [$ruleFile],
            'evaluation_interval' => '1m',
            'tests' => [[
                'interval' => $timeline->interval(),
                'input_series' => $timeline->series(),
                'alert_rule_test' => $cases,
            ]],
        ];
    }

    /**
     * Write the drill's fixture and run promtool over it.
     *
     * @param   AlertDrill                   $drill     The drill.
     * @param   DrillTimeline                $timeline  What it observed and declared.
     * @param   list<array<string, string>>  $firing    Label sets of every instance that must fire.
     *
     * @return  array{fixture: string, exit: int, output: string}  Fixture path, promtool status and output.
     *
     * @throws  RuntimeException  When the fixture cannot be written or promtool cannot start.
     *
     * @since   2.0.0
     */
    public function evaluate(AlertDrill $drill, DrillTimeline $timeline, array $firing): array
    {
        if (!is_dir($this->fixtures) && !mkdir($this->fixtures, 0775, true) && !is_dir($this->fixtures)) {
            throw new RuntimeException(sprintf('The fixture directory %s cannot be created.', $this->fixtures));
        }
        $fixture = sprintf('%s/%s.test.yaml', $this->fixtures, $drill->id);
        $header = sprintf(
            "# Alert drill %s: series scraped from the real application; see docs/operations/monitoring.md#alert-drills.\n",
            $drill->id,
        );
        $document = $this->document($drill, $timeline, $firing, $this->ruleFile);
        if (file_put_contents($fixture, $header . PromtoolTests::yaml($document)) === false) {
            throw new RuntimeException(sprintf('The fixture %s cannot be written.', $fixture));
        }
        $log = $fixture . '.out';
        $process = proc_open(
            [$this->promtool, 'test', 'rules', $fixture],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('promtool could not be started.');
        }
        $exit = proc_close($process);
        $output = (string) @file_get_contents($log);
        @unlink($log);

        return ['fixture' => $fixture, 'exit' => $exit, 'output' => trim($output)];
    }
}
