<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * The offline gate over the shipped alert rules, inhibition rules and runbooks.
 *
 * It enforces what makes an alert operable and bounded: a closed label vocabulary (`severity`, `component`),
 * the four annotations every page needs, a runbook section that exists and is named after the alert, templates
 * that interpolate only labels the alert carries, expressions that parse and query only declared series with
 * bounded labels and correctly typed functions, a result small enough to route, and inhibition rules whose
 * matchers and `equal` labels refer to what actually fires. `promtool` and `amtool` re-check the syntax in CI;
 * this gate is what `composer qa` can run without the network.
 *
 * @since  2.0.0
 */
final readonly class RuleGate
{
    /**
     * Severities the rules may declare.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const SEVERITIES = ['page', 'ticket'];

    /**
     * Components the rules may declare; routing and inhibition match on these.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const COMPONENTS = [
        'availability', 'http', 'database', 'queue', 'integration', 'reporting', 'retention', 'recovery',
        'storage', 'extension', 'security', 'observability',
    ];

    /**
     * Annotations every alert carries, and nothing else.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const ANNOTATIONS = ['summary', 'description', 'runbook', 'caught'];

    /**
     * Most distinct alert label sets one rule may produce from its non-topology labels.
     *
     * @var    int
     * @since  2.0.0
     */
    public const MAXIMUM_ALERT_SERIES = 32;

    /**
     * Bind the gate to the repository and the series it checks against.
     *
     * @param  string           $root       Repository root.
     * @param  MetricInventory  $inventory  Declared series.
     * @param  list<string>     $forbidden  Label names the observability contract forbids.
     *
     * @since  2.0.0
     */
    public function __construct(private string $root, private MetricInventory $inventory, private array $forbidden)
    {
    }

    /**
     * Load the alert rules from a Prometheus rule file.
     *
     * @param   string  $path  Absolute rule file path.
     *
     * @return  array<string, AlertRule>  Rules by alert name, in file order.
     *
     * @throws  RuleViolation  When the file is unreadable or structurally invalid.
     *
     * @since   2.0.0
     */
    public static function load(string $path): array
    {
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw RuleViolation::at($path, 'the rule file cannot be read');
        }
        $document = RuleYaml::parse($contents, basename($path));
        $groups = $document['groups'] ?? null;
        if (!is_array($groups) || $groups === [] || array_keys($document) !== ['groups']) {
            throw RuleViolation::at($path, 'a rule file holds exactly one non-empty `groups` list');
        }
        $rules = [];
        $groupNames = [];
        foreach ($groups as $group) {
            if (!is_array($group) || !is_string($group['name'] ?? null) || !is_array($group['rules'] ?? null)) {
                throw RuleViolation::at($path, 'every group needs a `name` and a `rules` list');
            }
            if (in_array($group['name'], $groupNames, true)) {
                throw RuleViolation::at($path, sprintf('the group %s is declared twice', $group['name']));
            }
            $groupNames[] = $group['name'];
            foreach ($group['rules'] as $rule) {
                if (!is_array($rule) || !is_string($rule['alert'] ?? null)) {
                    throw RuleViolation::at($group['name'], 'every rule must be an alerting rule with a name');
                }
                $name = $rule['alert'];
                if (isset($rules[$name])) {
                    throw RuleViolation::at($name, 'the alert is declared twice');
                }
                $unknown = array_diff(array_keys($rule), ['alert', 'expr', 'for', 'labels', 'annotations']);
                if ($unknown !== []) {
                    throw RuleViolation::at($name, sprintf('uses unsupported keys %s', implode(', ', $unknown)));
                }
                if (!is_string($rule['expr'] ?? null)) {
                    throw RuleViolation::at($name, 'the expression is missing');
                }
                $rules[$name] = new AlertRule(
                    $group['name'],
                    $name,
                    trim($rule['expr']),
                    PromQl::parse($rule['expr'], $name),
                    is_string($rule['for'] ?? null) ? $rule['for'] : '0s',
                    self::strings($rule['labels'] ?? [], $name . ' labels'),
                    self::strings($rule['annotations'] ?? [], $name . ' annotations'),
                );
            }
        }

        return $rules;
    }

    /**
     * Run every check over the rule file, the inhibition file and the runbooks.
     *
     * @return  list<string>  Problems; empty when everything passes.
     *
     * @since   2.0.0
     */
    public function problems(): array
    {
        $problems = [];
        try {
            $alerts = self::load($this->root . '/deploy/observability/alerts.yaml');
        } catch (RuleViolation $violation) {
            return [$violation->getMessage()];
        }
        $analysis = new PromQlAnalysis($this->inventory, $this->forbidden);
        $carried = [];
        foreach ($alerts as $name => $alert) {
            $problems = array_merge($problems, $this->alertProblems($alert, $analysis));
            try {
                $shape = $analysis->shape($alert->tree);
                $carried[$name] = array_values(array_unique(array_merge($shape['labels'], array_keys($alert->labels))));
            } catch (RuleViolation) {
                $carried[$name] = array_keys($alert->labels);
            }
        }
        try {
            $contents = @file_get_contents($this->root . '/deploy/observability/alertmanager.yaml');
            if (!is_string($contents)) {
                throw RuleViolation::at('deploy/observability/alertmanager.yaml', 'the file cannot be read');
            }
            $inhibitions = InhibitionRules::fromConfiguration(
                RuleYaml::parse($contents, 'alertmanager.yaml'),
                'alertmanager.yaml',
            );
            $problems = array_merge($problems, $inhibitions->problems($alerts, $carried));
        } catch (RuleViolation $violation) {
            $problems[] = $violation->getMessage();
        }
        $pages = array_filter($alerts, static fn (AlertRule $alert): bool => $alert->severity() === 'page');
        if ($pages === []) {
            $problems[] = 'alerts.yaml: declares no page-severity alert, so nothing would ever wake anyone.';
        }

        return $problems;
    }

    /**
     * Compute the labels each alert is guaranteed to carry, rule labels included.
     *
     * @param   array<string, AlertRule>  $alerts  Alerts by name.
     *
     * @return  array<string, list<string>>  Labels by alert name.
     *
     * @throws  RuleViolation  When an expression cannot be shaped.
     *
     * @since   2.0.0
     */
    public function carried(array $alerts): array
    {
        $analysis = new PromQlAnalysis($this->inventory, $this->forbidden);
        $carried = [];
        foreach ($alerts as $name => $alert) {
            $carried[$name] = array_values(array_unique(array_merge(
                $analysis->shape($alert->tree)['labels'],
                array_keys($alert->labels),
            )));
        }

        return $carried;
    }

    /**
     * Check one alert.
     *
     * @param   AlertRule       $alert     Rule.
     * @param   PromQlAnalysis  $analysis  Expression analysis.
     *
     * @return  list<string>  Problems.
     *
     * @since   2.0.0
     */
    private function alertProblems(AlertRule $alert, PromQlAnalysis $analysis): array
    {
        $name = $alert->name;
        $problems = [];
        if (preg_match('/^Kumwe[A-Z][A-Za-z]+$/D', $name) !== 1) {
            $problems[] = sprintf('%s: alert names are `Kumwe` followed by PascalCase words.', $name);
        }
        if (!str_starts_with($alert->group, 'kumwe.')) {
            $problems[] = sprintf('%s: group %s is not in the kumwe. namespace.', $name, $alert->group);
        }
        try {
            if ($alert->forSeconds() < 60) {
                $problems[] = sprintf('%s: `for` must be at least one minute so a single scrape gap cannot page.', $name);
            }
        } catch (\InvalidArgumentException $invalid) {
            $problems[] = sprintf('%s: %s', $name, $invalid->getMessage());
        }
        if (array_keys($alert->labels) !== ['severity', 'component']) {
            $problems[] = sprintf('%s: labels must be exactly severity and component, in that order.', $name);
        }
        if (!in_array($alert->severity(), self::SEVERITIES, true)) {
            $problems[] = sprintf('%s: severity must be one of %s.', $name, implode(', ', self::SEVERITIES));
        }
        if (!in_array($alert->labels['component'] ?? '', self::COMPONENTS, true)) {
            $problems[] = sprintf('%s: component must be one of %s.', $name, implode(', ', self::COMPONENTS));
        }
        if (array_keys($alert->annotations) !== self::ANNOTATIONS) {
            $problems[] = sprintf('%s: annotations must be exactly %s, in that order.', $name, implode(', ', self::ANNOTATIONS));
        }
        foreach ($alert->annotations as $key => $text) {
            if (trim($text) === '') {
                $problems[] = sprintf('%s: the %s annotation is empty.', $name, $key);
            }
        }
        if (strlen($alert->annotations['summary'] ?? '') > 120) {
            $problems[] = sprintf('%s: the summary is longer than 120 characters.', $name);
        }
        $problems = array_merge($problems, $this->runbookProblems($alert), $analysis->problems($alert->tree, $name));
        try {
            $shape = $analysis->shape($alert->tree);
        } catch (RuleViolation $violation) {
            return array_merge($problems, [$violation->getMessage()]);
        }
        if ($shape['kind'] !== 'vector') {
            $problems[] = sprintf('%s: an alert expression must produce an instant vector, not a %s.', $name, $shape['kind']);
        }
        $allowed = array_merge($shape['labels'], array_keys($alert->labels));
        foreach ($alert->annotations as $key => $text) {
            try {
                foreach (AnnotationTemplate::labels($text, $name . ' ' . $key) as $label) {
                    if (!in_array($label, $allowed, true)) {
                        $problems[] = sprintf('%s: the %s annotation interpolates %s, which the alert does not carry.', $name, $key, $label);
                    }
                }
            } catch (RuleViolation $violation) {
                $problems[] = $violation->getMessage();
            }
        }
        $series = 1;
        foreach ($shape['labels'] as $label) {
            if (in_array($label, MetricInventory::TOPOLOGY_LABELS, true)) {
                continue;
            }
            $cardinality = null;
            foreach ($shape['series'] as $source) {
                $cardinality ??= $this->inventory->cardinality($source, $label);
            }
            if ($cardinality === null) {
                $problems[] = sprintf('%s: the result carries %s, which no queried series bounds.', $name, $label);
                continue;
            }
            $series *= $cardinality;
        }
        if ($series > self::MAXIMUM_ALERT_SERIES) {
            $problems[] = sprintf('%s: can produce %d alert label sets, more than %d.', $name, $series, self::MAXIMUM_ALERT_SERIES);
        }

        return $problems;
    }

    /**
     * Check that the runbook annotation points at a section of its own in an existing document.
     *
     * @param   AlertRule  $alert  Rule.
     *
     * @return  list<string>  Problems.
     *
     * @since   2.0.0
     */
    private function runbookProblems(AlertRule $alert): array
    {
        $reference = $alert->annotations['runbook'] ?? '';
        [$path, $anchor] = array_pad(explode('#', $reference, 2), 2, '');
        if ($path === '' || !is_file($this->root . '/' . $path)) {
            return [sprintf('%s: the runbook %s does not exist.', $alert->name, $path)];
        }
        if ($anchor !== strtolower($alert->name)) {
            return [sprintf('%s: the runbook anchor must be the alert\'s own section, #%s.', $alert->name, strtolower($alert->name))];
        }
        if (!in_array($anchor, self::anchors($this->root . '/' . $path), true)) {
            return [sprintf('%s: %s has no section #%s.', $alert->name, $path, $anchor)];
        }

        return [];
    }

    /**
     * List the heading anchors of a Markdown document as GitHub renders them.
     *
     * @param   string  $path  Absolute document path.
     *
     * @return  list<string>  Anchors of every second- to fourth-level heading.
     *
     * @since   2.0.0
     */
    public static function anchors(string $path): array
    {
        preg_match_all('/^#{2,4} (.+)$/m', (string) @file_get_contents($path), $found);

        return array_map(
            static fn (string $heading): string => strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', trim($heading)), '-')),
            $found[1],
        );
    }

    /**
     * Require a mapping of strings.
     *
     * @param   mixed   $value    Declared mapping.
     * @param   string  $subject  Alert and field, for violations.
     *
     * @return  array<string, string>  The mapping.
     *
     * @throws  RuleViolation  When the value is not a mapping of strings.
     *
     * @since   2.0.0
     */
    private static function strings(mixed $value, string $subject): array
    {
        if (!is_array($value)) {
            throw RuleViolation::at($subject, 'must be a mapping');
        }
        $strings = [];
        foreach ($value as $key => $item) {
            if (!is_string($item)) {
                throw RuleViolation::at($subject, sprintf('%s must be a string', (string) $key));
            }
            $strings[(string) $key] = trim($item);
        }

        return $strings;
    }
}
