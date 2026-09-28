<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Static checks over a parsed PromQL expression: known series, bounded labels, typed functions, result labels.
 *
 * Every selector must name a declared series, every matcher a label that series carries, and every equality
 * or literal alternation a value the label's enumeration contains, so a rule can never silently match nothing.
 * Counter functions (`rate`, `irate`, `increase`, `resets`) apply only to counters and histogram series, and
 * gauge-slope functions (`delta`, `idelta`, `deriv`, `predict_linear`) never to counters. The result label set
 * is inferred the way the engine builds it — aggregation keeps its `by` labels or drops its `without` labels,
 * one-to-one matching keeps `on` labels or drops `ignoring` labels, `or` guarantees only the labels both sides
 * carry — which is what lets the gate refuse an annotation template or an inhibition `equal` naming a label the
 * alert will not have.
 *
 * @since  2.0.0
 */
final readonly class PromQlAnalysis
{
    /**
     * Functions the gate understands: argument kinds and how the result's labels relate to the arguments.
     *
     * Argument kinds are `vector`, `matrix`, `scalar` and `string`; a trailing `?` marks an optional argument.
     * Results are `keep` (the vector or matrix argument's labels), `drop_le` (the same without `le`), `scalar`,
     * `none` (a vector with no labels) and `absent` (only the equality-matched labels).
     *
     * @var    array<string, array{list<string>, string}>
     * @since  2.0.0
     */
    public const FUNCTIONS = [
        'rate' => [['matrix'], 'keep'],
        'irate' => [['matrix'], 'keep'],
        'increase' => [['matrix'], 'keep'],
        'resets' => [['matrix'], 'keep'],
        'delta' => [['matrix'], 'keep'],
        'idelta' => [['matrix'], 'keep'],
        'deriv' => [['matrix'], 'keep'],
        'changes' => [['matrix'], 'keep'],
        'predict_linear' => [['matrix', 'scalar'], 'keep'],
        'avg_over_time' => [['matrix'], 'keep'],
        'min_over_time' => [['matrix'], 'keep'],
        'max_over_time' => [['matrix'], 'keep'],
        'sum_over_time' => [['matrix'], 'keep'],
        'count_over_time' => [['matrix'], 'keep'],
        'last_over_time' => [['matrix'], 'keep'],
        'present_over_time' => [['matrix'], 'keep'],
        'stddev_over_time' => [['matrix'], 'keep'],
        'stdvar_over_time' => [['matrix'], 'keep'],
        'quantile_over_time' => [['scalar', 'matrix'], 'keep'],
        'absent' => [['vector'], 'absent'],
        'absent_over_time' => [['matrix'], 'absent'],
        'abs' => [['vector'], 'keep'],
        'ceil' => [['vector'], 'keep'],
        'floor' => [['vector'], 'keep'],
        'exp' => [['vector'], 'keep'],
        'ln' => [['vector'], 'keep'],
        'log2' => [['vector'], 'keep'],
        'log10' => [['vector'], 'keep'],
        'sqrt' => [['vector'], 'keep'],
        'sgn' => [['vector'], 'keep'],
        'round' => [['vector', 'scalar?'], 'keep'],
        'clamp' => [['vector', 'scalar', 'scalar'], 'keep'],
        'clamp_min' => [['vector', 'scalar'], 'keep'],
        'clamp_max' => [['vector', 'scalar'], 'keep'],
        'timestamp' => [['vector'], 'keep'],
        'sort' => [['vector'], 'keep'],
        'sort_desc' => [['vector'], 'keep'],
        'histogram_quantile' => [['scalar', 'vector'], 'drop_le'],
        'time' => [[], 'scalar'],
        'scalar' => [['vector'], 'scalar'],
        'vector' => [['scalar'], 'none'],
    ];

    /**
     * Functions whose argument must be a counter series.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const COUNTER_FUNCTIONS = ['rate', 'irate', 'increase', 'resets'];

    /**
     * Functions whose argument must not be a counter series.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const GAUGE_FUNCTIONS = ['delta', 'idelta', 'deriv', 'predict_linear'];

    /**
     * Bind the analysis to the inventory it checks against.
     *
     * @param  MetricInventory  $inventory  Declared series.
     * @param  list<string>     $forbidden  Label names the observability contract forbids anywhere.
     *
     * @since  2.0.0
     */
    public function __construct(private MetricInventory $inventory, private array $forbidden)
    {
    }

    /**
     * Collect every problem in an expression.
     *
     * @param   array<string, mixed>  $node     Parsed expression.
     * @param   string                $subject  Alert or panel, for messages.
     *
     * @return  list<string>  Problems; empty when the expression passes.
     *
     * @since   2.0.0
     */
    public function problems(array $node, string $subject): array
    {
        $problems = [];
        $this->walk($node, $subject, $problems);
        try {
            $this->shape($node);
        } catch (RuleViolation $violation) {
            $problems[] = $violation->getMessage();
        }

        return $problems;
    }

    /**
     * Infer the labels every result series is guaranteed to carry, and the result's kind.
     *
     * @param   array<string, mixed>  $node  Parsed expression.
     *
     * @return  array{kind: string, labels: list<string>, series: list<string>}  Kind (`vector`, `matrix`,
     *          `scalar`, `string`), guaranteed labels, and the series names contributing labels.
     *
     * @throws  RuleViolation  When an operand has the wrong kind for its position.
     *
     * @since   2.0.0
     */
    public function shape(array $node): array
    {
        switch ($node['kind']) {
            case 'number':
                return ['kind' => 'scalar', 'labels' => [], 'series' => []];
            case 'string':
                return ['kind' => 'string', 'labels' => [], 'series' => []];
            case 'paren':
            case 'unary':
                return $this->shape($node['expr']);
            case 'subquery':
                $inner = $this->shape($node['expr']);

                return ['kind' => 'matrix'] + $inner;
            case 'selector':
                $labels = MetricInventory::TOPOLOGY_LABELS;
                $series = [];
                if (is_string($node['name'])) {
                    $declared = $this->inventory->series($node['name']);
                    $labels = array_values(array_unique(array_merge(
                        ['instance', 'job'],
                        array_keys($declared['labels'] ?? []),
                        $node['name'] === 'kumwe_build_info' ? ['release', 'runtime'] : [],
                    )));
                    $series = [$node['name']];
                }

                return ['kind' => $node['range'] === null ? 'vector' : 'matrix', 'labels' => $labels, 'series' => $series];
            case 'call':
                return $this->call($node);
            case 'aggregate':
                $inner = $this->shape($node['expr']);
                if ($inner['kind'] !== 'vector') {
                    throw RuleViolation::at($node['op'], 'an aggregation needs an instant vector');
                }
                $grouping = $node['grouping'];
                $labels = match (true) {
                    $grouping === null => [],
                    $grouping['type'] === 'by' => $grouping['labels'],
                    default => array_values(array_diff($inner['labels'], $grouping['labels'])),
                };
                if (in_array($node['op'], ['topk', 'bottomk', 'limitk', 'limit_ratio'], true)) {
                    $labels = $inner['labels'];
                }

                return ['kind' => 'vector', 'labels' => $labels, 'series' => $inner['series']];
            case 'binary':
                return $this->binary($node);
        }

        throw RuleViolation::at('expression', sprintf('unknown node kind %s', (string) $node['kind']));
    }

    /**
     * Collect every selector in an expression.
     *
     * @param   array<string, mixed>  $node  Parsed expression.
     *
     * @return  list<array<string, mixed>>  Selector nodes in source order.
     *
     * @since   2.0.0
     */
    public static function selectors(array $node): array
    {
        if ($node['kind'] === 'selector') {
            return [$node];
        }
        $found = [];
        foreach (['expr', 'lhs', 'rhs', 'param'] as $child) {
            if (is_array($node[$child] ?? null)) {
                $found = array_merge($found, self::selectors($node[$child]));
            }
        }
        foreach ($node['args'] ?? [] as $argument) {
            $found = array_merge($found, self::selectors($argument));
        }

        return $found;
    }

    /**
     * Visit every node, recording selector, matcher, grouping and function problems.
     *
     * @param   array<string, mixed>  $node      Node being visited.
     * @param   string                $subject   Alert or panel, for messages.
     * @param   list<string>          $problems  Accumulated problems.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function walk(array $node, string $subject, array &$problems): void
    {
        if ($node['kind'] === 'selector') {
            $this->selector($node, $subject, $problems);
        }
        if ($node['kind'] === 'aggregate' && $node['grouping'] !== null) {
            foreach ($node['grouping']['labels'] as $label) {
                if ($this->forbids($label)) {
                    $problems[] = sprintf('%s: groups by the forbidden label %s.', $subject, $label);
                }
            }
        }
        if ($node['kind'] === 'binary' && $node['matching'] !== null) {
            foreach (array_merge($node['matching']['labels'], $node['matching']['include']) as $label) {
                if ($this->forbids($label)) {
                    $problems[] = sprintf('%s: matches on the forbidden label %s.', $subject, $label);
                }
            }
        }
        if ($node['kind'] === 'call') {
            $this->function($node, $subject, $problems);
        }
        foreach (['expr', 'lhs', 'rhs', 'param'] as $child) {
            if (is_array($node[$child] ?? null)) {
                $this->walk($node[$child], $subject, $problems);
            }
        }
        foreach ($node['args'] ?? [] as $argument) {
            $this->walk($argument, $subject, $problems);
        }
    }

    /**
     * Check one selector against the inventory.
     *
     * @param   array<string, mixed>  $node      Selector node.
     * @param   string                $subject   Alert or panel, for messages.
     * @param   list<string>          $problems  Accumulated problems.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function selector(array $node, string $subject, array &$problems): void
    {
        $name = $node['name'];
        if (!is_string($name)) {
            $problems[] = sprintf('%s: selects without a metric name, which can match any series.', $subject);

            return;
        }
        $series = $this->inventory->series($name);
        if ($series === null) {
            $problems[] = sprintf('%s: selects %s, which no catalogue or probe declares.', $subject, $name);

            return;
        }
        foreach ($node['matchers'] as $matcher) {
            $label = $matcher['label'];
            if ($this->forbids($label)) {
                $problems[] = sprintf('%s: matches on the forbidden label %s.', $subject, $label);
                continue;
            }
            if (!$this->inventory->bounded($name, $label)) {
                $problems[] = sprintf('%s: %s carries no label %s.', $subject, $name, $label);
                continue;
            }
            $values = $series['labels'][$label] ?? null;
            if ($values === null || in_array($matcher['op'], ['!=', '!~'], true)) {
                continue;
            }
            $candidates = $matcher['op'] === '=' ? [$matcher['value']] : self::alternatives($matcher['value']);
            foreach ($candidates ?? [] as $candidate) {
                if (!in_array($candidate, $values, true) && $candidate !== 'other') {
                    $problems[] = sprintf(
                        '%s: %s{%s} can never equal "%s"; its values are %s.',
                        $subject,
                        $name,
                        $label,
                        $candidate,
                        implode(', ', $values),
                    );
                }
            }
        }
    }

    /**
     * Check one function call's name, arity and argument series types.
     *
     * @param   array<string, mixed>  $node      Call node.
     * @param   string                $subject   Alert or panel, for messages.
     * @param   list<string>          $problems  Accumulated problems.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function function(array $node, string $subject, array &$problems): void
    {
        $name = $node['name'];
        $signature = self::FUNCTIONS[$name] ?? null;
        if ($signature === null) {
            $problems[] = sprintf('%s: calls %s, which the gate does not recognise.', $subject, $name);

            return;
        }
        $required = count(array_filter($signature[0], static fn (string $kind): bool => !str_ends_with($kind, '?')));
        $arguments = count($node['args']);
        if ($arguments < $required || $arguments > count($signature[0])) {
            $problems[] = sprintf('%s: %s takes %d argument(s), not %d.', $subject, $name, $required, $arguments);

            return;
        }
        $counter = in_array($name, self::COUNTER_FUNCTIONS, true);
        $gauge = in_array($name, self::GAUGE_FUNCTIONS, true);
        if (!$counter && !$gauge) {
            return;
        }
        foreach (self::selectors($node['args'][0]) as $selector) {
            $series = is_string($selector['name']) ? $this->inventory->series($selector['name']) : null;
            if ($series === null) {
                continue;
            }
            if ($counter && $series['type'] !== 'counter') {
                $problems[] = sprintf('%s: %s applies to counters, but %s is a %s.', $subject, $name, $selector['name'], $series['type']);
            }
            if ($gauge && $series['type'] === 'counter') {
                $problems[] = sprintf('%s: %s applies to gauges, but %s is a counter.', $subject, $name, $selector['name']);
            }
        }
    }

    /**
     * Infer a call's result shape, checking its argument kinds.
     *
     * @param   array<string, mixed>  $node  Call node.
     *
     * @return  array{kind: string, labels: list<string>, series: list<string>}  Result shape.
     *
     * @throws  RuleViolation  When an argument has the wrong kind.
     *
     * @since   2.0.0
     */
    private function call(array $node): array
    {
        $signature = self::FUNCTIONS[$node['name']] ?? [[], 'none'];
        $vector = null;
        foreach ($node['args'] as $index => $argument) {
            $expected = rtrim($signature[0][$index] ?? 'scalar', '?');
            $shape = $this->shape($argument);
            if ($shape['kind'] !== $expected) {
                throw RuleViolation::at(
                    $node['name'],
                    sprintf('argument %d must be a %s, not a %s', $index + 1, $expected, $shape['kind']),
                );
            }
            if ($expected === 'vector' || $expected === 'matrix') {
                $vector = $shape;
            }
        }

        return match ($signature[1]) {
            'scalar' => ['kind' => 'scalar', 'labels' => [], 'series' => []],
            'none' => ['kind' => 'vector', 'labels' => [], 'series' => []],
            'drop_le' => ['kind' => 'vector', 'labels' => array_values(array_diff($vector['labels'] ?? [], ['le'])),
                'series' => $vector['series'] ?? []],
            'absent' => ['kind' => 'vector', 'labels' => [], 'series' => []],
            default => ['kind' => 'vector', 'labels' => $vector['labels'] ?? [], 'series' => $vector['series'] ?? []],
        };
    }

    /**
     * Infer a binary expression's result shape the way the engine's vector matching builds it.
     *
     * @param   array<string, mixed>  $node  Binary node.
     *
     * @return  array{kind: string, labels: list<string>, series: list<string>}  Result shape.
     *
     * @throws  RuleViolation  When an operand is a range vector or a set operator has a scalar side.
     *
     * @since   2.0.0
     */
    private function binary(array $node): array
    {
        $left = $this->shape($node['lhs']);
        $right = $this->shape($node['rhs']);
        foreach ([$left, $right] as $side) {
            if ($side['kind'] === 'matrix' || $side['kind'] === 'string') {
                throw RuleViolation::at($node['op'], 'a binary operand must be an instant vector or a scalar');
            }
        }
        $set = in_array($node['op'], ['and', 'or', 'unless'], true);
        if ($left['kind'] === 'scalar' && $right['kind'] === 'scalar') {
            if ($set) {
                throw RuleViolation::at($node['op'], 'a set operator needs vectors on both sides');
            }

            return $left;
        }
        if ($left['kind'] === 'scalar' || $right['kind'] === 'scalar') {
            if ($set) {
                throw RuleViolation::at($node['op'], 'a set operator needs vectors on both sides');
            }

            return $left['kind'] === 'scalar' ? $right : $left;
        }
        $series = array_values(array_unique(array_merge($left['series'], $right['series'])));
        if ($node['op'] === 'or') {
            return ['kind' => 'vector', 'labels' => array_values(array_intersect($left['labels'], $right['labels'])),
                'series' => $series];
        }
        if ($set) {
            return ['kind' => 'vector', 'labels' => $left['labels'], 'series' => $series];
        }
        $matching = $node['matching'];
        if ($matching !== null && $matching['group'] !== null) {
            $base = $matching['group'] === 'group_left' ? $left['labels'] : $right['labels'];

            return ['kind' => 'vector', 'labels' => array_values(array_unique(array_merge(
                $base,
                $matching['include'],
            ))), 'series' => $series];
        }
        $labels = $left['labels'];
        if ($matching !== null && $matching['type'] === 'on') {
            $labels = array_values(array_intersect($labels, $matching['labels']));
        } elseif ($matching !== null) {
            $labels = array_values(array_diff($labels, $matching['labels']));
        }

        return ['kind' => 'vector', 'labels' => $labels, 'series' => $series];
    }

    /**
     * Split a regular expression into literal alternatives when it is nothing but a plain alternation.
     *
     * @param   string  $pattern  Matcher regular expression.
     *
     * @return  ?list<string>  The alternatives, or null when the pattern uses any other regex feature.
     *
     * @since   2.0.0
     */
    private static function alternatives(string $pattern): ?array
    {
        if (preg_match('/^[A-Za-z0-9_.:+-]+(?:\|[A-Za-z0-9_.:+-]+)*$/D', $pattern) !== 1 || str_contains($pattern, '.')) {
            return null;
        }

        return explode('|', $pattern);
    }

    /**
     * Report whether the contract forbids a label name, by containment as the runtime does.
     *
     * @param   string  $label  Label name.
     *
     * @return  bool  True when forbidden.
     *
     * @since   2.0.0
     */
    private function forbids(string $label): bool
    {
        foreach ($this->forbidden as $forbidden) {
            if (str_contains(strtolower($label), $forbidden)) {
                return true;
            }
        }

        return false;
    }
}
