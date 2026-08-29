<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

/**
 * How strictly the host applies neutral SDK package findings during installation.
 *
 * The SDK always reports the same evidence and never decides whether a package may be installed.
 * This App-owned posture is the deployment policy that interprets those findings. Attestation
 * discrepancies remain refusals in every mode because they mean signed package bytes disagree with
 * the evidence carried inside them.
 *
 * @since  2.0.0
 */
enum PackageConformanceMode: string
{
    /**
     * Refuse an install carrying an integrity finding.
     *
     * @since  2.0.0
     */
    case Enforce = 'enforce';

    /**
     * Admit code-integrity findings but record and surface them to operators.
     *
     * @since  2.0.0
     */
    case Warn = 'warn';

    /**
     * Skip host code-conformance policy while still validating any package attestations.
     *
     * @since  2.0.0
     */
    case Off = 'off';
}
