<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use Kumwe\Extension\Package\PackageAttestationState;
use Kumwe\Extension\Package\PackageBillOfMaterials;
use Kumwe\Extension\Package\PackageEvidenceScope;
use Kumwe\Extension\Package\PackageFinding;

/**
 * App admission policy result persisted for operators after a package is admitted.
 *
 * The underlying inspection and evidence vocabulary belong to the SDK. This host-owned report adds
 * only the deployment posture and decision that the SDK deliberately does not make, while retaining
 * the exact documents and finding order received from the neutral evidence report.
 *
 * @since  2.0.0
 */
final readonly class PackageAdmissionReport
{
    /**
     * Record the admitted evidence and the App policy decision applied to it.
     *
     * @param  PackageEvidenceScope     $scope             Neutral evidence depth that was executed.
     * @param  PackageAttestationState  $sbomState         Inventory verification state.
     * @param  ?string                  $sbomSha256        Inventory digest, or null when absent.
     * @param  int                      $sbomComponents    File components in the readable inventory.
     * @param  ?array<string, mixed>    $sbom              Readable CycloneDX document, or null.
     * @param  PackageAttestationState  $provenanceState   Provenance verification state.
     * @param  ?string                  $provenanceSha256  Provenance digest, or null when absent.
     * @param  ?string                  $builderReference  Publisher-asserted builder identity, or null.
     * @param  ?array<string, mixed>    $provenance        Readable provenance document, or null.
     * @param  PackageConformanceMode   $conformanceMode   App posture applied to code findings.
     * @param  string                   $conformanceState  `passed`, `warned`, or `package_only`.
     * @param  array<string, bool>      $checks            Named code-conformance outcomes.
     * @param  list<PackageFinding>     $blocking          Mandatory findings; empty for an admitted package.
     * @param  list<PackageFinding>     $advisory          Non-integrity authoring observations.
     *
     * @since  2.0.0
     */
    public function __construct(
        public PackageEvidenceScope $scope,
        public PackageAttestationState $sbomState,
        public ?string $sbomSha256,
        public int $sbomComponents,
        public ?array $sbom,
        public PackageAttestationState $provenanceState,
        public ?string $provenanceSha256,
        public ?string $builderReference,
        public ?array $provenance,
        public PackageConformanceMode $conformanceMode,
        public string $conformanceState,
        public array $checks,
        public array $blocking,
        public array $advisory,
    ) {
    }

    /**
     * Export the policy-bearing summary stored on the release row.
     *
     * @return  array{format: string, scope: string, sbom: array{state: string, sha256: ?string, components: int,
     *          format: string}, provenance: array{state: string, sha256: ?string, builder: ?string},
     *          conformance: array{scope: string, mode: string, state: string, checks: array<string, bool>,
     *          blocking: list<array{code: string, message: string, path?: string}>,
     *          advisory: list<array{code: string, message: string, path?: string}>}}  JSON-compatible summary.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'format' => 'kumwe-extension-admission-v2',
            'scope' => $this->scope->value,
            'sbom' => [
                'state' => $this->sbomState->value,
                'sha256' => $this->sbomSha256,
                'components' => $this->sbomComponents,
                'format' => 'CycloneDX/' . PackageBillOfMaterials::SPEC_VERSION,
            ],
            'provenance' => [
                'state' => $this->provenanceState->value,
                'sha256' => $this->provenanceSha256,
                'builder' => $this->builderReference,
            ],
            'conformance' => [
                'scope' => $this->scope->value,
                'mode' => $this->conformanceMode->value,
                'state' => $this->conformanceState,
                'checks' => $this->checks,
                'blocking' => array_map(
                    static fn (PackageFinding $finding): array => $finding->toArray(),
                    $this->blocking,
                ),
                'advisory' => array_map(
                    static fn (PackageFinding $finding): array => $finding->toArray(),
                    $this->advisory,
                ),
            ],
        ];
    }

    /**
     * Reduce the decision to concise audit metadata.
     *
     * @return  array{scope: string, sbom: string, provenance: string, conformance: string,
     *          conformance_mode: string, blocking_findings: int, advisory_findings: int}  Flat audit metadata.
     *
     * @since   2.0.0
     */
    public function auditMetadata(): array
    {
        return [
            'scope' => $this->scope->value,
            'sbom' => $this->sbomState->value,
            'provenance' => $this->provenanceState->value,
            'conformance' => $this->conformanceState,
            'conformance_mode' => $this->conformanceMode->value,
            'blocking_findings' => count($this->blocking),
            'advisory_findings' => count($this->advisory),
        ];
    }
}
