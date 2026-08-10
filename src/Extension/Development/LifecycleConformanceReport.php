<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

/**
 * Stable result of a full platform-backed extension lifecycle conformance run.
 *
 * @since  2.0.0
 */
final readonly class LifecycleConformanceReport
{
    /**
     * Record ordered gate verdicts and their platform failure evidence.
     *
     * @param  array<string, bool>  $checks      Ordered lifecycle gate verdicts.
     * @param  list<string>         $violations  Failure messages in observation order.
     *
     * @since  2.0.0
     */
    public function __construct(public array $checks, public array $violations)
    {
    }

    /**
     * Decide whether every declared platform gate and recovery passed.
     *
     * @return  bool  True only when all checks are true and no violation was recorded.
     *
     * @since   2.0.0
     */
    public function conforms(): bool
    {
        return $this->violations === [] && !in_array(false, $this->checks, true);
    }

    /**
     * Export a machine-readable lifecycle report for CI artifacts.
     *
     * @return  array{format: string, conforms: bool, checks: array<string, bool>, violations: list<string>}
     *          Stable full-lifecycle verdict.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'format' => 'kumwe-extension-lifecycle-conformance-v1',
            'conforms' => $this->conforms(),
            'checks' => $this->checks,
            'violations' => $this->violations,
        ];
    }
}
