<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Builds `promtool test rules` documents whose expectations are derived from the rule file itself.
 *
 * A scenario states only the input series and three instants — before the condition, while it holds past its
 * `for` duration, and after recovery. The expected labels and fully rendered annotations come from the rule, so
 * promtool proves that the alert stays quiet, fires with exactly its declared severity, component, labels and
 * runbook link, and clears again; nothing about the expectation can drift from the rule it tests. The alert
 * drills use the same builder with series taken from real scrapes instead of written by hand.
 *
 * @since  2.0.0
 */
final readonly class PromtoolTests
{
    /**
     * Bind the builder to the rules and the labels each alert carries.
     *
     * @param  array<string, AlertRule>     $alerts   Alerts by name.
     * @param  array<string, list<string>>  $carried  Labels each alert is guaranteed to carry, rule labels included.
     *
     * @since  2.0.0
     */
    public function __construct(private array $alerts, private array $carried)
    {
    }

    /**
     * Build one promtool test group for a scenario.
     *
     * @param   string                                     $alert     Alert under test.
     * @param   list<array{series: string, values: string}>  $series   Input series in promtool notation.
     * @param   list<array{at: string, fires: bool}>       $checks    Evaluation instants and whether it fires there.
     * @param   array<string, string>                      $labels    Series labels the firing alert carries.
     * @param   string                                     $interval  Series sample interval.
     *
     * @return  array<string, mixed>  The test group.
     *
     * @throws  RuleViolation  When the alert is unknown or the labels differ from what the rule carries.
     *
     * @since   2.0.0
     */
    public function group(string $alert, array $series, array $checks, array $labels, string $interval = '1m'): array
    {
        $expected = $this->expectation($alert, $labels);
        $tests = [];
        foreach ($checks as $check) {
            $tests[] = [
                'eval_time' => $check['at'],
                'alertname' => $alert,
                'exp_alerts' => $check['fires'] ? [$expected] : [],
            ];
        }

        return ['interval' => $interval, 'input_series' => $series, 'alert_rule_test' => $tests];
    }

    /**
     * Build the exact labels and rendered annotations one firing instance of an alert must carry.
     *
     * promtool compares both exactly, so this is also how a test proves the runbook link is attached.
     *
     * @param   string                 $alert   Alert under test.
     * @param   array<string, string>  $labels  Series labels the firing instance carries.
     *
     * @return  array{exp_labels: array<string, string>, exp_annotations: array<string, string>}  The expectation.
     *
     * @throws  RuleViolation  When the alert is unknown or the labels differ from what the rule carries.
     *
     * @since   2.0.0
     */
    public function expectation(string $alert, array $labels): array
    {
        $rule = $this->alerts[$alert] ?? throw RuleViolation::at($alert, 'no such alert is declared');
        $expected = array_values(array_diff($this->carried[$alert] ?? [], array_keys($rule->labels)));
        sort($expected);
        $given = array_keys($labels);
        sort($given);
        if ($expected !== $given) {
            throw RuleViolation::at($alert, sprintf(
                'the scenario states labels [%s] but the alert carries [%s]',
                implode(', ', $given),
                implode(', ', $expected),
            ));
        }
        $all = $labels + $rule->labels;
        ksort($all);
        $annotations = [];
        foreach ($rule->annotations as $key => $template) {
            $annotations[$key] = AnnotationTemplate::render($template, $all + ['alertname' => $alert]);
        }

        return ['exp_labels' => $all, 'exp_annotations' => $annotations];
    }

    /**
     * Build the whole test document from the committed scenario file.
     *
     * @param   array<string, mixed>  $scenarios  Decoded `scenarios.json`.
     * @param   string                $ruleFile   Rule file path as the test document should reference it.
     *
     * @return  array<string, mixed>  The promtool test document.
     *
     * @throws  RuleViolation  When a scenario is malformed, an alert has none, or one names no alert.
     *
     * @since   2.0.0
     */
    public function document(array $scenarios, string $ruleFile): array
    {
        $declared = $scenarios['scenarios'] ?? null;
        if (!is_array($declared)) {
            throw RuleViolation::at('scenarios.json', 'needs a `scenarios` object keyed by alert name');
        }
        foreach (array_keys($this->alerts) as $alert) {
            if (!isset($declared[$alert])) {
                throw RuleViolation::at($alert, 'has no promtool scenario in deploy/observability/tests/scenarios.json');
            }
        }
        $groups = [];
        foreach ($declared as $alert => $scenario) {
            if (!is_array($scenario) || !is_array($scenario['series'] ?? null)) {
                throw RuleViolation::at((string) $alert, 'a scenario needs a `series` list');
            }
            foreach (['healthy', 'firing', 'cleared'] as $phase) {
                if (!is_string($scenario[$phase] ?? null)) {
                    throw RuleViolation::at((string) $alert, sprintf('a scenario needs a `%s` evaluation time', $phase));
                }
            }
            /** @var list<array{series: string, values: string}> $series */
            $series = $scenario['series'];
            /** @var array<string, string> $labels */
            $labels = is_array($scenario['labels'] ?? null) ? $scenario['labels'] : [];
            $groups[] = $this->group((string) $alert, $series, [
                ['at' => $scenario['healthy'], 'fires' => false],
                ['at' => $scenario['firing'], 'fires' => true],
                ['at' => $scenario['cleared'], 'fires' => false],
            ], $labels);
        }

        return ['rule_files' => [$ruleFile], 'evaluation_interval' => '1m', 'tests' => $groups];
    }

    /**
     * Render a document as YAML, quoting every scalar so its reading is unambiguous.
     *
     * @param   array<int|string, mixed>  $document  Nested maps, lists and scalars.
     *
     * @return  string  YAML text.
     *
     * @since   2.0.0
     */
    public static function yaml(array $document): string
    {
        return self::emit($document, 0) . "\n";
    }

    /**
     * Emit one collection at an indentation.
     *
     * @param   array<int|string, mixed>  $value   Map or list.
     * @param   int                       $indent  Indentation in spaces.
     *
     * @return  string  YAML lines without a trailing newline.
     *
     * @since   2.0.0
     */
    private static function emit(array $value, int $indent): string
    {
        $pad = str_repeat(' ', $indent);
        $lines = [];
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            $prefix = $list ? $pad . '- ' : $pad . $key . ':';
            if (is_array($item) && $item === []) {
                $lines[] = $prefix . ($list ? '' : ' ') . (array_is_list($item) ? '[]' : '{}');
                continue;
            }
            if (!is_array($item)) {
                $lines[] = $prefix . ($list ? '' : ' ') . self::scalar($item);
                continue;
            }
            if ($list && !array_is_list($item)) {
                $nested = explode("\n", self::emit($item, $indent + 2));
                $nested[0] = $pad . '- ' . ltrim($nested[0]);
                $lines[] = implode("\n", $nested);
                continue;
            }
            $lines[] = rtrim($prefix);
            $lines[] = self::emit($item, $indent + 2);
        }

        return implode("\n", $lines);
    }

    /**
     * Emit a scalar as a JSON string, which YAML reads as a double-quoted scalar.
     *
     * @param   mixed  $value  Scalar.
     *
     * @return  string  Quoted text.
     *
     * @since   2.0.0
     */
    private static function scalar(mixed $value): string
    {
        return (string) json_encode(
            is_bool($value) ? ($value ? 'true' : 'false') : (string) (is_scalar($value) ? $value : ''),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
