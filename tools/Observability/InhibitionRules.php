<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * The Alertmanager inhibition rules, parsed, validated against the alert rules, and evaluable.
 *
 * Validation refuses a matcher naming an alert that does not exist, and an `equal` label that the source or any
 * target alert does not carry — Alertmanager compares a label missing on both sides as equal, so such a rule
 * would silently mute unrelated alerts. Evaluation reproduces Alertmanager's semantics for a set of firing
 * alerts, which is what lets a drill assert that the specific alert pages and its consequence stays quiet.
 *
 * @since  2.0.0
 */
final readonly class InhibitionRules
{
    /**
     * Bind the parsed rules.
     *
     * @param  list<array{source: list<array{string, string, string}>, target: list<array{string, string, string}>,
     *         equal: list<string>}>  $rules  Rules with matchers as label, operator, value triples.
     *
     * @since  2.0.0
     */
    private function __construct(public array $rules)
    {
    }

    /**
     * Parse the `inhibit_rules` of an Alertmanager configuration.
     *
     * @param   array<string, mixed>  $configuration  Parsed Alertmanager configuration.
     * @param   string                $file           Path, for violations.
     *
     * @return  self  The rules.
     *
     * @throws  RuleViolation  When a rule or matcher is malformed.
     *
     * @since   2.0.0
     */
    public static function fromConfiguration(array $configuration, string $file): self
    {
        $rules = [];
        $declared = $configuration['inhibit_rules'] ?? null;
        if (!is_array($declared) || $declared === []) {
            throw RuleViolation::at($file, 'no inhibit_rules are declared');
        }
        foreach ($declared as $index => $rule) {
            $subject = sprintf('%s inhibit_rules[%d]', $file, (int) $index);
            if (!is_array($rule)) {
                throw RuleViolation::at($subject, 'a rule must be a mapping');
            }
            $unknown = array_diff(array_keys($rule), ['source_matchers', 'target_matchers', 'equal']);
            if ($unknown !== []) {
                throw RuleViolation::at($subject, sprintf('uses unsupported keys %s', implode(', ', $unknown)));
            }
            $equal = $rule['equal'] ?? [];
            if (!is_array($equal)) {
                throw RuleViolation::at($subject, '`equal` must be a list of label names');
            }
            $rules[] = [
                'source' => self::matchers($rule['source_matchers'] ?? null, $subject . ' source'),
                'target' => self::matchers($rule['target_matchers'] ?? null, $subject . ' target'),
                'equal' => array_values(array_map(static fn (mixed $label): string => (string) $label, $equal)),
            ];
        }

        return new self($rules);
    }

    /**
     * Validate every rule against the alert rules and their inferred result labels.
     *
     * @param   array<string, AlertRule>     $alerts  Alerts by name.
     * @param   array<string, list<string>>  $labels  Labels each alert is guaranteed to carry, rule labels included.
     *
     * @return  list<string>  Problems; empty when every rule is sound.
     *
     * @since   2.0.0
     */
    public function problems(array $alerts, array $labels): array
    {
        $problems = [];
        foreach ($this->rules as $index => $rule) {
            $subject = sprintf('alertmanager.yaml inhibit_rules[%d]', $index);
            $sources = $this->matching($rule['source'], $alerts, $labels);
            $targets = $this->matching($rule['target'], $alerts, $labels);
            foreach (array_merge($rule['source'], $rule['target']) as [$label, $operator, $value]) {
                if ($label === 'alertname' && $operator === '=' && !isset($alerts[$value])) {
                    $problems[] = sprintf('%s: names the alert %s, which alerts.yaml does not declare.', $subject, $value);
                }
            }
            if ($sources === []) {
                $problems[] = sprintf('%s: its source matchers match no declared alert.', $subject);
            }
            if ($targets === []) {
                $problems[] = sprintf('%s: its target matchers match no declared alert.', $subject);
            }
            foreach ($rule['equal'] as $label) {
                foreach (array_merge($sources, $targets) as $name) {
                    if (!in_array($label, $labels[$name], true)) {
                        $problems[] = sprintf(
                            '%s: equal label %s is not carried by %s, so the comparison would treat absence as a match.',
                            $subject,
                            $label,
                            $name,
                        );
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * Decide which of a set of firing alerts Alertmanager would inhibit.
     *
     * @param   list<array<string, string>>  $firing  Label sets of firing alerts, `alertname` included.
     *
     * @return  list<string>  Names of the inhibited alerts, deduplicated.
     *
     * @since   2.0.0
     */
    public function inhibited(array $firing): array
    {
        $inhibited = [];
        foreach ($firing as $target) {
            foreach ($this->rules as $rule) {
                if (!self::matches($rule['target'], $target)) {
                    continue;
                }
                foreach ($firing as $source) {
                    if ($source === $target || !self::matches($rule['source'], $source)) {
                        continue;
                    }
                    // An alert that matches both sides of a rule never inhibits an alert that does too.
                    if (self::matches($rule['target'], $source) && self::matches($rule['source'], $target)) {
                        continue;
                    }
                    $equal = true;
                    foreach ($rule['equal'] as $label) {
                        $equal = $equal && ($source[$label] ?? '') === ($target[$label] ?? '');
                    }
                    if ($equal) {
                        $inhibited[] = $target['alertname'] ?? '';
                        continue 3;
                    }
                }
            }
        }

        return array_values(array_unique($inhibited));
    }

    /**
     * Parse a list of Alertmanager matchers such as `alertname=~"A|B"`.
     *
     * @param   mixed   $matchers  Declared matcher list.
     * @param   string  $subject   Rule side, for violations.
     *
     * @return  list<array{string, string, string}>  Label, operator and value triples.
     *
     * @throws  RuleViolation  When the list is empty or a matcher is malformed.
     *
     * @since   2.0.0
     */
    private static function matchers(mixed $matchers, string $subject): array
    {
        if (!is_array($matchers) || $matchers === []) {
            throw RuleViolation::at($subject, 'needs at least one matcher');
        }
        $parsed = [];
        foreach ($matchers as $matcher) {
            if (
                !is_string($matcher)
                || preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(=~|!~|!=|=)"([^"]*)"$/D', $matcher, $match) !== 1
            ) {
                throw RuleViolation::at($subject, sprintf('`%s` is not a `label="value"` matcher', (string) json_encode($matcher)));
            }
            if (in_array($match[2], ['=~', '!~'], true) && @preg_match('/^(?:' . $match[3] . ')$/', '') === false) {
                throw RuleViolation::at($subject, sprintf('`%s` is not a valid regular expression', $match[3]));
            }
            $parsed[] = [$match[1], $match[2], $match[3]];
        }

        return $parsed;
    }

    /**
     * List the declared alerts a matcher list selects, judged by alert name and rule labels.
     *
     * @param   list<array{string, string, string}>  $matchers  Matchers.
     * @param   array<string, AlertRule>             $alerts    Alerts by name.
     * @param   array<string, list<string>>          $labels    Labels each alert carries.
     *
     * @return  list<string>  Matching alert names.
     *
     * @since   2.0.0
     */
    private function matching(array $matchers, array $alerts, array $labels): array
    {
        $names = [];
        foreach ($alerts as $name => $alert) {
            $known = ['alertname' => $name] + $alert->labels;
            $possible = true;
            foreach ($matchers as [$label, $operator, $value]) {
                if (!array_key_exists($label, $known)) {
                    // A series label decides at run time; it must at least be one the alert carries.
                    $possible = $possible && in_array($label, $labels[$name], true);
                    continue;
                }
                $possible = $possible && self::matches([[$label, $operator, $value]], $known);
            }
            if ($possible) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Evaluate matchers against one label set.
     *
     * @param   list<array{string, string, string}>  $matchers  Matchers.
     * @param   array<string, string>                $labels    Label set.
     *
     * @return  bool  True when every matcher holds.
     *
     * @since   2.0.0
     */
    private static function matches(array $matchers, array $labels): bool
    {
        foreach ($matchers as [$label, $operator, $value]) {
            $actual = $labels[$label] ?? '';
            $holds = match ($operator) {
                '=' => $actual === $value,
                '!=' => $actual !== $value,
                '=~' => preg_match('/^(?:' . $value . ')$/D', $actual) === 1,
                default => preg_match('/^(?:' . $value . ')$/D', $actual) !== 1,
            };
            if (!$holds) {
                return false;
            }
        }

        return true;
    }
}
