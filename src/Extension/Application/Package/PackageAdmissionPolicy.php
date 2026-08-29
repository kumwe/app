<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use Kumwe\Extension\Package\PackageAttestationState;
use Kumwe\Extension\Package\PackageEvidenceReport;

/**
 * Applies App deployment policy to the SDK's neutral package evidence.
 *
 * The inspector remains reusable because it reports facts only. This class is the sole place the App
 * turns those facts into an install decision: malformed or contradictory attestations always fail
 * closed, while code-integrity findings follow the configured deployment posture. Finding strings and
 * ordering are retained exactly as produced by the SDK.
 *
 * @since  2.0.0
 */
final readonly class PackageAdmissionPolicy
{
    /**
     * Select the App posture applied to code-integrity findings.
     *
     * @param  PackageConformanceMode  $mode  Deployment posture for code findings.
     *
     * @since  2.0.0
     */
    public function __construct(private PackageConformanceMode $mode = PackageConformanceMode::Enforce)
    {
    }

    /**
     * Admit or refuse the package described by one neutral SDK report.
     *
     * @param   PackageEvidenceReport  $evidence  Complete, deterministic SDK inspection result.
     *
     * @return  PackageAdmissionReport  Admitted evidence plus the App policy outcome.
     *
     * @throws  NonConformingPackage  When attestation evidence is invalid in any mode or integrity
     *          findings are present under `Enforce`.
     *
     * @since   2.0.0
     */
    public function admit(PackageEvidenceReport $evidence): PackageAdmissionReport
    {
        $this->assertAttestations($evidence);

        if ($evidence->integrityFindings !== [] && $this->mode === PackageConformanceMode::Enforce) {
            throw new NonConformingPackage(sprintf(
                'The extension package failed install-time code conformance: %s',
                implode(' ', $evidence->integrityFindings),
            ));
        }

        $scanning = $this->mode !== PackageConformanceMode::Off;

        return new PackageAdmissionReport(
            $evidence->sbomState,
            $evidence->sbomSha256,
            $evidence->sbomComponents,
            $evidence->sbom,
            $evidence->provenanceState,
            $evidence->provenanceSha256,
            $evidence->builderReference,
            $evidence->provenance,
            $this->mode,
            !$scanning ? 'skipped' : ($evidence->integrityFindings === [] ? 'passed' : 'warned'),
            $scanning ? $this->codeChecks($evidence->checks) : [],
            $scanning ? $evidence->integrityFindings : [],
            $scanning ? $evidence->qualityFindings : [],
        );
    }

    /**
     * Refuse evidence documents that are present but invalid, independently of code posture.
     *
     * @param   PackageEvidenceReport  $evidence  SDK evidence to validate.
     *
     * @return  void
     *
     * @throws  NonConformingPackage  When either attestation state is invalid.
     *
     * @since   2.0.0
     */
    private function assertAttestations(PackageEvidenceReport $evidence): void
    {
        if (
            $evidence->sbomState === PackageAttestationState::Absent
            && $evidence->provenanceState === PackageAttestationState::Invalid
        ) {
            throw new NonConformingPackage(
                'The extension package carries a provenance statement but no bill of materials to bind it to.',
            );
        }

        if ($evidence->sbomState === PackageAttestationState::Invalid) {
            throw new NonConformingPackage($this->attestationMessage(
                $evidence->integrityFindings,
                'The packaged bill of materials',
                'The packaged bill of materials is invalid.',
            ));
        }

        if ($evidence->provenanceState === PackageAttestationState::Invalid) {
            throw new NonConformingPackage($this->attestationMessage(
                $evidence->integrityFindings,
                'The packaged provenance statement',
                'The packaged provenance statement is invalid.',
            ));
        }
    }

    /**
     * Select the SDK finding that explains one invalid attestation.
     *
     * @param   list<string>  $findings  Deterministically ordered SDK integrity findings.
     * @param   string        $prefix    Stable attestation category prefix.
     * @param   string        $fallback  Message used only when the SDK supplied no detailed finding.
     *
     * @return  string  Detailed refusal message without changing the SDK text.
     *
     * @since   2.0.0
     */
    private function attestationMessage(array $findings, string $prefix, string $fallback): string
    {
        foreach ($findings as $finding) {
            if (str_starts_with($finding, $prefix)) {
                return $finding;
            }
        }

        return $fallback;
    }

    /**
     * Keep attestation outcomes out of the host's code-conformance check group.
     *
     * @param   array<string, bool>  $checks  Full SDK objective check set.
     *
     * @return  array<string, bool>  Code and authoring checks in their original order.
     *
     * @since   2.0.0
     */
    private function codeChecks(array $checks): array
    {
        unset($checks['sbom'], $checks['provenance']);

        return $checks;
    }
}
