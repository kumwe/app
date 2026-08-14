<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Observability;

/**
 * Renders collected samples as a Prometheus text exposition body.
 *
 * The format was chosen over OpenMetrics, StatsD and a bespoke JSON document for one reason that
 * outweighs the others: it is a pull format that every mainstream collector already speaks — Prometheus
 * itself, but equally the OpenTelemetry Collector's `prometheus` receiver, Grafana Agent, Datadog's
 * OpenMetrics check and VictoriaMetrics — so shipping it costs Kumwe no vendor dependency and costs the
 * operator no adapter. A push format such as StatsD would have required Kumwe to hold a destination
 * address, retry policy and buffering for a system it cannot see, and would have made the application
 * responsible for delivery. Pull keeps the failure mode where it belongs: a scrape that does not happen
 * is the monitoring system's outage, not the application's.
 *
 * Rendering is deliberately dumb: no state, no I/O, no clock. It takes samples and produces bytes, so
 * the format is testable against a literal expected document.
 *
 * @since  2.0.0
 */
final readonly class PrometheusExposition
{
    /**
     * Content type a Prometheus-compatible scraper expects on the response.
     *
     * @var    string
     * @since  2.0.0
     */
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * Render the samples, grouped into the families the catalogue declares.
     *
     * Families are emitted in catalogue order and their series in stable sorted order, so two scrapes
     * of an unchanged system produce byte-identical documents and a diff of two captures shows only
     * what actually moved. Samples naming an undeclared family are dropped rather than rendered: the
     * catalogue is the contract, and a series that escaped it is exactly the cardinality accident this
     * design exists to prevent.
     *
     * @param   MetricCatalog       $catalog  Declared families supplying the help and type lines.
     * @param   list<MetricSample>  $samples  Collected series.
     *
     * @return  string  The exposition body, newline-terminated.
     *
     * @since   2.0.0
     */
    public function render(MetricCatalog $catalog, array $samples): string
    {
        $grouped = [];
        foreach ($samples as $sample) {
            $grouped[$sample->family][] = $sample;
        }
        $body = '';
        foreach ($catalog->definitions() as $name => $definition) {
            $series = $grouped[$name] ?? [];
            if ($series === []) {
                continue;
            }
            $lines = [];
            foreach ($series as $sample) {
                $lines[] = $sample->name . $this->labels($sample->labels) . ' ' . $this->value($sample->value);
            }
            sort($lines);
            $body .= sprintf("# HELP %s %s\n# TYPE %s %s\n", $name, $definition->help, $name, $definition->type->value);
            $body .= implode("\n", $lines) . "\n";
        }

        return $body;
    }

    /**
     * Render one label set as the exposition format writes it.
     *
     * @param   array<string, string>  $labels  Bound labels, already restricted to the enumeration.
     *
     * @return  string  The bracketed label list, or an empty string when there are no labels.
     *
     * @since   2.0.0
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }
        $pairs = [];
        foreach ($labels as $label => $value) {
            $pairs[] = sprintf('%s="%s"', $label, $this->escape($value));
        }

        return '{' . implode(',', $pairs) . '}';
    }

    /**
     * Escape a label value so it cannot break out of its quoted position.
     *
     * Label values in this application come from closed enumerations and from the configured release
     * string. The release is operator-supplied, so it is escaped rather than trusted: an unescaped
     * quote or newline there would corrupt every following line of the document.
     *
     * @param   string  $value  Raw label value.
     *
     * @return  string  Value with backslashes, quotes and newlines escaped.
     *
     * @since   2.0.0
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value);
    }

    /**
     * Render a sample value in a locale-independent form the format accepts.
     *
     * @param   float  $value  Sample value.
     *
     * @return  string  Decimal rendering, with whole numbers written without a fractional part.
     *
     * @since   2.0.0
     */
    private function value(float $value): string
    {
        if (!is_finite($value)) {
            return $value > 0 ? '+Inf' : ($value < 0 ? '-Inf' : 'NaN');
        }
        if ($value === floor($value) && abs($value) < 1.0e15) {
            return number_format($value, 0, '.', '');
        }

        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
