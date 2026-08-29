<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

/**
 * Whether install-time evidence includes SDK authoring-quality checks.
 *
 * Archive safety, manifest integrity and references, PHP syntax, package trust, and attestation
 * integrity are enforced in both modes. The toggle controls only strict-types, unfinished-marker,
 * text-encoding and README observations, which are advisory when collected.
 *
 * @since  2.0.0
 */
enum PackageConformanceMode: string
{
    /**
     * Collect mandatory package evidence and advisory authoring observations.
     *
     * @since  2.0.0
     */
    case Scan = 'scan';

    /**
     * Skip advisory authoring checks while retaining every mandatory package boundary.
     *
     * @since  2.0.0
     */
    case Off = 'off';
}
