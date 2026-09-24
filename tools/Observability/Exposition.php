<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

/**
 * Reads the Prometheus text exposition format and writes series in promtool's notation.
 *
 * The alert drills scrape the real `/metrics` endpoint and the synthetic probe's textfile, and replay what they
 * saw to `promtool test rules`. This class is the bridge: it parses the scrape into samples exactly as a
 * Prometheus server would store them (comment lines ignored, label values unescaped, an optional sample
 * timestamp dropped because the drill assigns its own clock), and renders a series identity back as the
 * selector text promtool's `input_series` expects.
 *
 * @since  2.0.0
 */
final class Exposition
{
    /**
     * Parse one exposition document.
     *
     * @param   string  $text     Exposition text as served by `/metrics` or written by the probe.
     * @param   string  $subject  Source name, for violations.
     *
     * @return  list<array{name: string, labels: array<string, string>, value: float}>  Samples in document order.
     *
     * @throws  RuleViolation  When a sample line is malformed.
     *
     * @since   2.0.0
     */
    public static function parse(string $text, string $subject): array
    {
        $samples = [];
        foreach (preg_split('/\r?\n/', $text) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{.*\})?\s+(\S+)(?:\s+-?\d+)?$/D', $line, $match) !== 1) {
                throw RuleViolation::at($subject, sprintf('line %d is not a sample: %s', $number + 1, $line));
            }
            $samples[] = [
                'name' => $match[1],
                'labels' => self::labels($match[2], $subject, $number + 1),
                'value' => self::number($match[3], $subject, $number + 1),
            ];
        }

        return $samples;
    }

    /**
     * Render a series identity as promtool's `input_series` selector, labels in name order.
     *
     * @param   string                 $name    Metric name.
     * @param   array<string, string>  $labels  Label set.
     *
     * @return  string  For example `up{instance="127.0.0.1:8080",job="kumwe"}`.
     *
     * @since   2.0.0
     */
    public static function series(string $name, array $labels): string
    {
        ksort($labels);
        $pairs = [];
        foreach ($labels as $label => $value) {
            $pairs[] = sprintf('%s="%s"', $label, addcslashes($value, "\\\"\n"));
        }

        return $name . ($pairs === [] ? '' : '{' . implode(',', $pairs) . '}');
    }

    /**
     * Render a sample value in a form promtool's series notation reads back exactly.
     *
     * @param   float  $value  Sample value.
     *
     * @return  string  An integer, a decimal with at most six fractional digits, `Inf`, `-Inf` or `NaN`.
     *
     * @since   2.0.0
     */
    public static function value(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Inf' : '-Inf';
        }
        if (floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%d', (int) $value);
        }

        $rendered = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $rendered === '-0' ? '0' : $rendered;
    }

    /**
     * Parse the braces of a sample line into a label set.
     *
     * @param   string  $braces   Text from `{` to `}` inclusive, or empty.
     * @param   string  $subject  Source name, for violations.
     * @param   int     $line     Line number, for violations.
     *
     * @return  array<string, string>  Unescaped label values keyed by name.
     *
     * @throws  RuleViolation  When the label syntax is malformed.
     *
     * @since   2.0.0
     */
    private static function labels(string $braces, string $subject, int $line): array
    {
        $body = trim(substr($braces, 1, -1));
        $labels = [];
        $offset = 0;
        while ($body !== '' && $offset < strlen($body)) {
            if (
                preg_match(
                    '/\G\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*"((?:[^"\\\\]|\\\\.)*)"\s*(?:,|$)/',
                    $body,
                    $match,
                    0,
                    $offset,
                ) !== 1
            ) {
                throw RuleViolation::at($subject, sprintf('line %d has a malformed label set', $line));
            }
            $labels[$match[1]] = strtr($match[2], ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n"]);
            $offset += strlen($match[0]);
        }

        return $labels;
    }

    /**
     * Parse a sample value, accepting the exposition format's special values.
     *
     * @param   string  $text     Value token.
     * @param   string  $subject  Source name, for violations.
     * @param   int     $line     Line number, for violations.
     *
     * @return  float  The value.
     *
     * @throws  RuleViolation  When the token is not a number.
     *
     * @since   2.0.0
     */
    private static function number(string $text, string $subject, int $line): float
    {
        return match (strtolower($text)) {
            '+inf', 'inf' => INF,
            '-inf' => -INF,
            'nan' => NAN,
            default => is_numeric($text)
                ? (float) $text
                : throw RuleViolation::at($subject, sprintf('line %d has a non-numeric value %s', $line, $text)),
        };
    }
}
