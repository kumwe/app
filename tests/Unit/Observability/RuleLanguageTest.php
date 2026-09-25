<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Observability;

use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Tools\Observability\AnnotationTemplate;
use Kumwe\App\Tools\Observability\Duration;
use Kumwe\App\Tools\Observability\MetricInventory;
use Kumwe\App\Tools\Observability\PromQl;
use Kumwe\App\Tools\Observability\PromQlAnalysis;
use Kumwe\App\Tools\Observability\RuleViolation;
use Kumwe\App\Tools\Observability\RuleYaml;
use Kumwe\App\Tools\Observability\SyntheticProbe;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holds the offline rule reader, the PromQL parser and its static analysis to the rules they enforce.
 *
 * The subject is `tools/Observability`: the YAML subset reader must read exactly what Prometheus reads and
 * refuse the rest; the parser must accept PromQL's grammar and refuse malformed input; and the analysis must
 * refuse unknown series, unbounded or forbidden labels, impossible matcher values and mistyped functions,
 * while inferring the result labels the way the engine builds them.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class RuleLanguageTest extends TestCase
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
     * Mappings, sequences, quoted and block scalars and flow lists read the way Prometheus reads them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheYamlSubsetReadsEveryConstructTheRuleFilesUse(): void
    {
        $document = RuleYaml::parse(<<<'YAML'
            # A comment line.
            groups:
              - name: kumwe.probe
                rules:
                  - alert: KumweProbe
                    expr: up{job="kumwe"} == 0   # trailing comment
                    labels:
                      severity: page
                    annotations:
                      summary: "Quoted \"text\" with a {{ $labels.instance }}."
                      single: 'it''s'
                      folded: >-
                        First line
                        continues here.

                        New paragraph.
                      literal: |
                        keep
                        breaks
                    equal: [instance, "volume"]
                    empty: {}
                    nothing:
            YAML, 'probe.yaml');

        $rule = $document['groups'][0]['rules'][0];
        self::assertSame('kumwe.probe', $document['groups'][0]['name']);
        self::assertSame('up{job="kumwe"} == 0', $rule['expr']);
        self::assertSame('Quoted "text" with a {{ $labels.instance }}.', $rule['annotations']['summary']);
        self::assertSame("it's", $rule['annotations']['single']);
        self::assertSame("First line continues here.\nNew paragraph.", $rule['annotations']['folded']);
        self::assertSame("keep\nbreaks\n", $rule['annotations']['literal']);
        self::assertSame(['instance', 'volume'], $rule['equal']);
        self::assertNull($rule['empty']);
        self::assertNull($rule['nothing']);
    }

    /**
     * Constructs outside the subset are refused with the line that carries them.
     *
     * @param   string  $yaml      Document.
     * @param   string  $expected  Fragment the refusal must contain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('refusedYaml')]
    public function testTheYamlSubsetRefusesWhatPrometheusMightReadDifferently(string $yaml, string $expected): void
    {
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage($expected);

        RuleYaml::parse($yaml, 'refused.yaml');
    }

    /**
     * Documents the reader must refuse.
     *
     * @return  array<string, array{string, string}>  Document and expected refusal fragment.
     *
     * @since   2.0.0
     */
    public static function refusedYaml(): array
    {
        return [
            'anchor' => ["groups: &shared\n  - name: a\n", 'indicator `&`'],
            'alias' => ["groups: *shared\n", 'indicator `*`'],
            'tag' => ["groups: !!str a\n", 'indicator `!`'],
            'tab' => ["groups:\n\t- name: a\n", 'tab characters'],
            'duplicate key' => ["groups: []\ngroups: []\n", 'repeats'],
            'unclosed quote' => ["groups: \"open\n", 'never closed'],
            'document marker' => ["---\ngroups: []\n", 'document markers'],
            'flow mapping' => ["groups: {a: b}\n", 'indicator `{`'],
            'top-level list' => ["- a\n", 'must be a mapping'],
            'unknown escape' => ["groups: \"\\x41\"\n", 'escape'],
            'bad indentation' => ["groups:\n  a: b\n   c: d\n", 'expected indentation'],
            'empty block' => ["groups: >-\nnext: a\n", 'no content'],
        ];
    }

    /**
     * PromQL's grammar parses: modifiers, subqueries, grouping on either side, vector matching and precedence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePromQlGrammarParsesWithPrecedenceAndModifiers(): void
    {
        $tree = PromQl::parse(
            'sum by (le) (rate(kumwe_http_request_duration_seconds_bucket[5m] offset 1m)) '
            . '/ on (le) group_left (method) max without (job) (x) > bool 2 or vector(1) ^ 2 ^ 3',
            'probe',
        );

        self::assertSame('binary', $tree['kind']);
        self::assertSame('or', $tree['op']);
        self::assertSame('>', $tree['lhs']['op']);
        self::assertTrue($tree['lhs']['bool']);
        self::assertSame('/', $tree['lhs']['lhs']['op']);
        self::assertSame(
            ['type' => 'on', 'labels' => ['le'], 'group' => 'group_left', 'include' => ['method']],
            $tree['lhs']['lhs']['matching']
        );
        self::assertSame('^', $tree['rhs']['op']);
        self::assertSame('^', $tree['rhs']['rhs']['op'], 'Exponentiation is right-associative.');
        $rate = $tree['lhs']['lhs']['lhs']['expr'];
        self::assertSame('rate', $rate['name']);
        self::assertSame('5m', $rate['args'][0]['range']);
        self::assertSame('1m', $rate['args'][0]['offset']);
        self::assertSame(
            'subquery',
            PromQl::parse('max_over_time(kumwe_ready[1h:5m]) @ 1700000000', 'probe')['args'][0]['kind'],
        );
        self::assertSame('number', PromQl::parse('-Inf', 'probe')['expr']['kind']);
    }

    /**
     * Malformed expressions are refused with the offset of the problem.
     *
     * @param   string  $expression  Malformed PromQL.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('malformedPromQl')]
    public function testMalformedPromQlIsRefused(string $expression): void
    {
        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('probe:');

        PromQl::parse($expression, 'probe');
    }

    /**
     * Expressions the parser must refuse.
     *
     * @return  array<string, array{string}>  Expression.
     *
     * @since   2.0.0
     */
    public static function malformedPromQl(): array
    {
        return [
            'unbalanced' => ['sum(rate(x[5m])'],
            'range on a call' => ['rate(x[5m])[5m]'],
            'bool on arithmetic' => ['x + bool y'],
            'bad matcher' => ['x{job>"a"}'],
            'empty selector' => ['{}'],
            'trailing operator' => ['x >'],
            'stray character' => ['x ; y'],
            'two groupings' => ['sum by (a) (x) by (b)'],
            'bad at' => ['x @ now()'],
        ];
    }

    /**
     * Unknown series, missing labels, impossible values, forbidden labels and mistyped functions are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAnalysisRefusesWhatWouldSilentlyMatchNothingOrGrowUnbounded(): void
    {
        $analysis = self::analysis();
        $problems = static fn (string $expression): string => implode("\n", $analysis->problems(
            PromQl::parse($expression, 'probe'),
            'probe',
        ));

        self::assertSame('', $problems('sum(rate(kumwe_http_requests_total{status="5xx"}[5m]))'));
        self::assertStringContainsString('no catalogue or probe declares', $problems('kumwe_missing_total'));
        self::assertStringContainsString('carries no label path', $problems('kumwe_http_requests_total{path="/"}'));
        self::assertStringContainsString('can never equal "6xx"', $problems('kumwe_http_requests_total{status="6xx"}'));
        self::assertStringContainsString(
            'can never equal "blocked"',
            $problems('kumwe_queue_settlements_total{outcome=~"completed|blocked"}'),
        );
        self::assertSame(
            '',
            $problems('kumwe_queue_settlements_total{outcome=~"compl.*"}'),
            'A real regular expression is left alone.',
        );
        self::assertStringContainsString('forbidden label user_id', $problems('sum by (user_id) (kumwe_ready)'));
        self::assertStringContainsString('applies to counters', $problems('rate(kumwe_ready[5m])'));
        self::assertStringContainsString('applies to gauges', $problems('deriv(kumwe_http_requests_total[5m])'));
        self::assertStringContainsString('does not recognise', $problems('holt_winters(kumwe_ready[5m], 0.5, 0.5)'));
        self::assertStringContainsString('takes 1 argument', $problems('rate(kumwe_ready[5m], 1)'));
        self::assertStringContainsString('must be a matrix', $problems('rate(kumwe_http_requests_total)'));
        self::assertStringContainsString('without a metric name', $problems('{job="kumwe"}'));
        self::assertStringContainsString('instant vector or a scalar', $problems('kumwe_ready[5m] > 1'));
        self::assertStringContainsString('vectors on both sides', $problems('kumwe_ready and 1'));
    }

    /**
     * Result labels follow the engine: aggregation grouping, one-to-one matching, `or` and `histogram_quantile`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResultLabelsAreInferredTheWayTheEngineBuildsThem(): void
    {
        $analysis = self::analysis();
        $labels = static function (string $expression) use ($analysis): array {
            $found = $analysis->shape(PromQl::parse($expression, 'probe'))['labels'];
            sort($found);

            return $found;
        };

        $free = 'kumwe_storage_free_bytes';
        self::assertSame(['instance', 'job', 'volume'], $labels($free . ' / kumwe_storage_capacity_bytes'));
        self::assertSame(['volume'], $labels($free . ' / on (volume) kumwe_storage_capacity_bytes'));
        self::assertSame(['instance', 'job'], $labels($free . ' / ignoring (volume) kumwe_storage_capacity_bytes'));
        self::assertSame(['store'], $labels('max by (store) (kumwe_retention_backlog_rows)'));
        self::assertSame(['instance', 'job'], $labels('max without (store) (kumwe_retention_backlog_rows)'));
        self::assertSame([], $labels('max(kumwe_ready) or max(kumwe_workers_registered)'));
        self::assertSame(['method'], $labels(
            'histogram_quantile(0.95, sum by (le, method) (rate(kumwe_http_request_duration_seconds_bucket[5m])))',
        ));
        self::assertSame(['instance', 'job', 'method', 'status', 'volume'], $labels(
            'kumwe_http_requests_total / on (instance) group_left (volume) kumwe_storage_free_bytes',
        ));
        self::assertSame([], $labels('time() - max(kumwe_probe_last_run_timestamp_seconds)'));
        self::assertSame('scalar', $analysis->shape(PromQl::parse('time() * 2', 'probe'))['kind']);
        self::assertContains('release', $labels('kumwe_build_info'));
    }

    /**
     * Only label interpolation is allowed in an annotation, and it renders the alert's labels.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnnotationTemplatesAllowOnlyLabelInterpolation(): void
    {
        self::assertSame(
            ['instance', 'volume'],
            AnnotationTemplate::labels('{{ $labels.instance }} {{$labels.volume}}', 'a'),
        );
        self::assertSame('app-1 on', AnnotationTemplate::render('{{ $labels.instance }} on{{ $labels.missing }}', [
            'instance' => 'app-1',
        ]));

        $this->expectException(RuleViolation::class);
        $this->expectExceptionMessage('is not `{{ $labels.name }}`');
        AnnotationTemplate::labels('{{ $value | humanize }}', 'a');
    }

    /**
     * Durations convert both ways and malformed ones are refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDurationsConvert(): void
    {
        self::assertSame(5_400, Duration::seconds('1h30m'));
        self::assertSame(604_800, Duration::seconds('1w'));
        self::assertSame('25m', Duration::format(1_500));
        self::assertSame('90s', Duration::format(90));

        $this->expectException(\InvalidArgumentException::class);
        Duration::seconds('5 minutes');
    }

    /**
     * Build the analysis over the shipped catalogue.
     *
     * @return  PromQlAnalysis  The analysis.
     *
     * @since   2.0.0
     */
    private static function analysis(): PromQlAnalysis
    {
        $contract = ObservabilityContract::load(dirname(__DIR__, 3));

        return new PromQlAnalysis(
            MetricInventory::create(MetricCatalog::create($contract, '2.0.0', 'http'), SyntheticProbe::CHECKS),
            $contract->forbiddenLabels,
        );
    }
}
