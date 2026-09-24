<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Checks the shipped Grafana dashboards: valid queries over declared series, and the business/transport split.
 *
 * `kumwe-business-operations.json` answers "how much business work completed, and how fast"; it may count a
 * settlement only when it selects the completed outcome, and it may not read a failure, retry, rollback,
 * dead-letter or lease-expiry series at all. `kumwe-transport-and-retries.json` answers "what did that work cost
 * in retries and waiting"; it never plots the completed outcome as its subject, though it may divide by the
 * total to show a retry ratio. A panel that mixed the two would show a business rate inflated by retries, which
 * is exactly the confusion the split exists to prevent. Every query must also pass the same series, label and
 * function checks as an alert expression.
 *
 * @since  2.0.0
 */
final readonly class DashboardGate
{
    /**
     * Series that describe transport cost only and never belong on the business dashboard.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const TRANSPORT_SERIES = [
        'kumwe_transaction_failures_total',
        'kumwe_jobs_lease_expired',
        'kumwe_jobs_dead',
        'kumwe_jobs_dead_lettered',
        'kumwe_outbox_dead',
        'kumwe_inbox_poison',
    ];

    /**
     * Settlement counters and the outcome value that means business work completed.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public const SETTLEMENTS = [
        'kumwe_queue_settlements_total' => 'completed',
        'kumwe_dispatch_settlements_total' => 'completed',
        'kumwe_consumer_settlements_total' => 'completed',
        'kumwe_transactions_total' => 'committed',
    ];

    /**
     * Dashboards the gate requires, by file name, with the split each must honour.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public const DASHBOARDS = [
        'kumwe-business-operations.json' => 'business',
        'kumwe-transport-and-retries.json' => 'transport',
        'kumwe-platform-health.json' => 'platform',
    ];

    /**
     * Bind the gate to the repository and the declared series.
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
     * List the dashboard files the gate checks.
     *
     * @return  list<string>  File names.
     *
     * @since   2.0.0
     */
    public function files(): array
    {
        return array_keys(self::DASHBOARDS);
    }

    /**
     * Check every required dashboard.
     *
     * @return  list<string>  Problems; empty when every dashboard passes.
     *
     * @since   2.0.0
     */
    public function problems(): array
    {
        $problems = [];
        $analysis = new PromQlAnalysis($this->inventory, $this->forbidden);
        $uids = [];
        foreach (self::DASHBOARDS as $file => $role) {
            $path = $this->root . '/deploy/observability/dashboards/' . $file;
            $dashboard = json_decode((string) @file_get_contents($path), true);
            if (!is_array($dashboard)) {
                $problems[] = sprintf('%s: is missing or not a JSON object.', $file);
                continue;
            }
            foreach (['uid', 'title'] as $key) {
                if (!is_string($dashboard[$key] ?? null) || $dashboard[$key] === '') {
                    $problems[] = sprintf('%s: needs a %s.', $file, $key);
                }
            }
            $uid = (string) ($dashboard['uid'] ?? '');
            if (in_array($uid, $uids, true)) {
                $problems[] = sprintf('%s: reuses the uid %s.', $file, $uid);
            }
            $uids[] = $uid;
            if (!in_array($role, is_array($dashboard['tags'] ?? null) ? $dashboard['tags'] : [], true)) {
                $problems[] = sprintf('%s: must carry the tag %s.', $file, $role);
            }
            $panels = is_array($dashboard['panels'] ?? null) ? $dashboard['panels'] : [];
            if ($panels === []) {
                $problems[] = sprintf('%s: has no panels.', $file);
            }
            $ids = [];
            foreach ($panels as $panel) {
                $problems = array_merge($problems, $this->panel($file, $role, $panel, $analysis, $ids));
            }
        }

        return $problems;
    }

    /**
     * Check one panel.
     *
     * @param   string          $file      Dashboard file.
     * @param   string          $role      Dashboard role.
     * @param   mixed           $panel     Panel definition.
     * @param   PromQlAnalysis  $analysis  Expression analysis.
     * @param   list<int>       $ids       Panel identifiers seen so far.
     *
     * @return  list<string>  Problems.
     *
     * @since   2.0.0
     */
    private function panel(string $file, string $role, mixed $panel, PromQlAnalysis $analysis, array &$ids): array
    {
        if (!is_array($panel) || !is_int($panel['id'] ?? null) || !is_string($panel['title'] ?? null)) {
            return [sprintf('%s: every panel needs an integer id and a title.', $file)];
        }
        $subject = sprintf('%s panel "%s"', $file, $panel['title']);
        $problems = [];
        if (in_array($panel['id'], $ids, true)) {
            $problems[] = sprintf('%s: reuses panel id %d.', $subject, $panel['id']);
        }
        $ids[] = $panel['id'];
        if (($panel['type'] ?? '') === 'row' || ($panel['type'] ?? '') === 'text') {
            return $problems;
        }
        $targets = is_array($panel['targets'] ?? null) ? $panel['targets'] : [];
        if ($targets === []) {
            $problems[] = sprintf('%s: has no query.', $subject);
        }
        foreach ($targets as $target) {
            $expression = is_array($target) ? ($target['expr'] ?? null) : null;
            if (!is_string($expression)) {
                $problems[] = sprintf('%s: every target needs an expr.', $subject);
                continue;
            }
            try {
                $tree = PromQl::parse($expression, $subject);
            } catch (RuleViolation $violation) {
                $problems[] = $violation->getMessage();
                continue;
            }
            $problems = array_merge($problems, $analysis->problems($tree, $subject), $this->split($subject, $role, $tree));
            try {
                $labels = $analysis->shape($tree)['labels'];
                preg_match_all('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', (string) ($target['legendFormat'] ?? ''), $used);
                foreach ($used[1] as $label) {
                    if (!in_array($label, $labels, true)) {
                        $problems[] = sprintf('%s: the legend names %s, which the query does not return.', $subject, $label);
                    }
                }
            } catch (RuleViolation $violation) {
                $problems[] = $violation->getMessage();
            }
        }

        return $problems;
    }

    /**
     * Enforce the business/transport split for one query.
     *
     * @param   string                $subject  Panel, for messages.
     * @param   string                $role     Dashboard role.
     * @param   array<string, mixed>  $tree     Parsed query.
     *
     * @return  list<string>  Problems.
     *
     * @since   2.0.0
     */
    private function split(string $subject, string $role, array $tree): array
    {
        if ($role === 'platform') {
            return [];
        }
        $problems = [];
        foreach (PromQlAnalysis::selectors($tree) as $selector) {
            $name = (string) $selector['name'];
            if ($role === 'business' && in_array($name, self::TRANSPORT_SERIES, true)) {
                $problems[] = sprintf('%s: reads the transport series %s on the business dashboard.', $subject, $name);
            }
            if (!array_key_exists($name, self::SETTLEMENTS)) {
                continue;
            }
            $completed = self::SETTLEMENTS[$name];
            $outcome = null;
            foreach ($selector['matchers'] as $matcher) {
                if ($matcher['label'] === 'outcome') {
                    $outcome = $matcher;
                }
            }
            $isCompleted = $outcome !== null && $outcome['op'] === '=' && $outcome['value'] === $completed;
            if ($role === 'business' && !$isCompleted) {
                $problems[] = sprintf(
                    '%s: counts %s without selecting outcome="%s", so retries would inflate it.',
                    $subject,
                    $name,
                    $completed,
                );
            }
            // A transport panel may divide by the total, but never plot completed work as its subject.
            if ($role === 'transport' && $isCompleted) {
                $problems[] = sprintf('%s: plots completed %s on the transport dashboard.', $subject, $name);
            }
        }

        return $problems;
    }
}
