<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Observability;

/**
 * One time series and its current value, ready to be written into an exposition body.
 *
 * The exposition name is carried whole rather than derived, because a histogram publishes three
 * differently-named series — `_bucket`, `_sum` and `_count` — from a single declared family, and the
 * renderer should not have to know that rule twice.
 *
 * @since  2.0.0
 */
final readonly class MetricSample
{
    /**
     * Bind one series to its value.
     *
     * @param  string                 $family  Declared family this series belongs to, for its help and type lines.
     * @param  string                 $name    Exposition series name, including any histogram suffix.
     * @param  array<string, string>  $labels  Bound label set, already restricted to the declared enumeration.
     * @param  float                  $value   Current value of the series.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $family,
        public string $name,
        public array $labels,
        public float $value,
    ) {
    }
}
