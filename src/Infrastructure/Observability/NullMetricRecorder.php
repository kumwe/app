<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

/**
 * The recorder a deployment gets when metrics are switched off, which is the shipped default.
 *
 * It exists so the instrumentation call sites are unconditional. A middleware that has to ask "are
 * metrics on?" before every increment grows a branch that is wrong in one of the two states; a
 * middleware that always calls a recorder has no such branch, and the cost of the disabled case is one
 * method call into an empty body — small enough that turning metrics off is not a performance decision
 * anybody has to reason about.
 *
 * @since  2.0.0
 */
final readonly class NullMetricRecorder implements MetricRecorder
{
    /**
     * Discard a counter increment.
     *
     * @param   string                 $metric  Declared counter family name; ignored.
     * @param   array<string, string>  $labels  Labels; ignored.
     * @param   float                  $value   Amount; ignored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function increment(string $metric, array $labels = [], float $value = 1.0): void
    {
    }

    /**
     * Discard a histogram observation.
     *
     * @param   string                 $metric       Declared histogram family name; ignored.
     * @param   array<string, string>  $labels       Labels; ignored.
     * @param   float                  $observation  Observed value; ignored.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function observe(string $metric, array $labels, float $observation): void
    {
    }

    /**
     * Report that nothing is recorded.
     *
     * @return  list<MetricSample>  Always empty.
     *
     * @since   2.0.0
     */
    public function samples(): array
    {
        return [];
    }
}
