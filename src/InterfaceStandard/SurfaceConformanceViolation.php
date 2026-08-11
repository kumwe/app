<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

use DomainException;

/**
 * Admission failure carrying the complete deterministic KIS diagnostic report.
 *
 * @since  2.0.0
 */
final class SurfaceConformanceViolation extends DomainException
{
    /**
     * Refuse a non-conforming declaration while preserving all findings for extension tooling.
     *
     * @param  SurfaceConformanceReport  $report  Report containing at least one error diagnostic.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly SurfaceConformanceReport $report)
    {
        $codes = array_map(
            static fn (ConformanceDiagnostic $diagnostic): string => $diagnostic->code,
            array_filter(
                $report->diagnostics(),
                static fn (ConformanceDiagnostic $diagnostic): bool =>
                    $diagnostic->severity === ConformanceSeverity::Error,
            ),
        );

        parent::__construct(sprintf(
            'The KIS surface declaration is not conformant: %s.',
            implode(', ', $codes),
        ));
    }
}
