<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

/**
 * Stable, machine-readable result of code-free extension package conformance checks.
 *
 * @since  2.0.0
 */
final readonly class ConformanceReport
{
    /**
     * Record the inspected package and every deterministic check result.
     *
     * @param  PackageInspection    $inspection  Safe package inventory.
     * @param  array<string, bool>  $checks      Named checks in stable order.
     * @param  list<string>         $violations  Operator-readable failures in stable order.
     *
     * @since  2.0.0
     */
    public function __construct(
        public PackageInspection $inspection,
        public array $checks,
        public array $violations,
    ) {
    }

    /**
     * Decide whether every conformance check passed.
     *
     * @return  bool  True only when no violation was found.
     *
     * @since   2.0.0
     */
    public function conforms(): bool
    {
        return $this->violations === [] && !in_array(false, $this->checks, true);
    }

    /**
     * Export the stable JSON-compatible report consumed by CI and the console command.
     *
     * @return  array<string, mixed>  Package identity, checks, and violations.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'format' => 'kumwe-extension-conformance-v1',
            'conforms' => $this->conforms(),
            'package' => $this->inspection->toArray(),
            'checks' => $this->checks,
            'violations' => $this->violations,
        ];
    }
}
