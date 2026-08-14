<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Observability;

/**
 * The write side of metrics: what a hot path is allowed to call, and what it costs.
 *
 * PHP shares nothing between requests, so a counter incremented in one request is gone by the next
 * unless it lands somewhere shared. That is the whole reason this is an interface: the shipped default
 * is a no-op, so a deployment that has not turned metrics on pays a method call and nothing else, and
 * the Redis-backed implementation is only wired when an operator asks for it.
 *
 * Two obligations bind every implementation. It must never throw — a monitoring backend that has gone
 * away must not turn into a 500 on a page render — and it must not block: a recorder that waits on a
 * slow store has changed the behaviour of the system it was supposed to observe.
 *
 * @since  2.0.0
 */
interface MetricRecorder
{
    /**
     * Add to a counter.
     *
     * @param   string                 $metric  Declared counter family name.
     * @param   array<string, string>  $labels  Labels to bind against the declared enumeration.
     * @param   float                  $value   Amount to add; callers normally pass the default.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function increment(string $metric, array $labels = [], float $value = 1.0): void;

    /**
     * Record one observation into a histogram.
     *
     * @param   string                 $metric       Declared histogram family name.
     * @param   array<string, string>  $labels       Labels to bind against the declared enumeration.
     * @param   float                  $observation  Observed value, in the family's declared unit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function observe(string $metric, array $labels, float $observation): void;

    /**
     * Read back every series this recorder holds, for the exposition endpoint.
     *
     * @return  list<MetricSample>  Current series, or an empty list when nothing is recorded or readable.
     *
     * @since   2.0.0
     */
    public function samples(): array;
}
