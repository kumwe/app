<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

/**
 * Everything install-time admission established about a package, in the form an operator later reads.
 *
 * The report exists because a supply-chain control nobody can see is a control nobody can act on. It
 * is written to the release row it belongs to, summarised into the `extension.install` audit record,
 * and rendered on the Extensions screen, so the question "what is inside this extension, where does it
 * claim to come from, and what did we check" has one answer that outlives the install command's
 * output. A refused install produces no report — the exception is the report — which is why every
 * instance of this class describes a package that was admitted.
 *
 * @since  2.0.0
 */
final readonly class PackageAdmissionReport
{
    /**
     * Record what admission found, once every refusal has already been raised.
     *
     * @param  PackageAttestationState  $sbomState         Whether the bill of materials was present and
     *         reconciled against the packaged bytes.
     * @param  ?string                  $sbomSha256        Lowercase SHA-256 of the bill of materials, or
     *         null when the package carried none.
     * @param  int                      $sbomComponents    File components the bill of materials inventories.
     * @param  ?array<string, mixed>    $sbom              The CycloneDX document itself, or null when absent.
     * @param  PackageAttestationState  $provenanceState   Whether the provenance statement was present and
     *         described this package.
     * @param  ?string                  $provenanceSha256  Lowercase SHA-256 of the provenance statement, or
     *         null when the package carried none.
     * @param  ?string                  $builderReference  Builder identity the statement asserts, or null
     *         when no statement was carried; recorded as a claim, never as a verified fact.
     * @param  ?array<string, mixed>    $provenance        The provenance statement itself, or null when absent.
     * @param  PackageConformanceMode   $conformanceMode   Posture the installation ran the code scan under.
     * @param  string                   $conformanceState  `passed`, `warned`, or `skipped`.
     * @param  array<string, bool>      $checks            Each conformance check by name and whether it held.
     * @param  list<string>             $blocking          Findings that would refuse an install under
     *         `Enforce`; non-empty only when the mode admitted them anyway.
     * @param  list<string>             $advisory          Findings recorded and shown but never blocking.
     *
     * @since  2.0.0
     */
    public function __construct(
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
     * Build the report of an installation that deliberately took no scan at all.
     *
     * Reach for this only where the admission scanner is not wired, such as a container assembled
     * without it. It records `skipped` rather than a passing scan, so a release that was never examined
     * can never be mistaken on the Extensions screen for one that was.
     *
     * @return  self  A report asserting nothing was established.
     *
     * @since   2.0.0
     */
    public static function notTaken(): self
    {
        return new self(
            PackageAttestationState::Absent,
            null,
            0,
            null,
            PackageAttestationState::Absent,
            null,
            null,
            null,
            PackageConformanceMode::Off,
            'skipped',
            [],
            [],
            [],
        );
    }

    /**
     * Export the summary stored on the release row and echoed into the audit record.
     *
     * The two attestation documents are deliberately not part of this: they are stored in columns of
     * their own so a policy query can reach a component list without decoding a summary blob.
     *
     * @return  array{format: string, sbom: array{state: string, sha256: ?string, components: int,
     *          format: string}, provenance: array{state: string, sha256: ?string, builder: ?string},
     *          conformance: array{mode: string, state: string, checks: array<string, bool>,
     *          blocking: list<string>, advisory: list<string>}}  JSON-compatible admission summary.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'format' => 'kumwe-extension-admission-v1',
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
                'mode' => $this->conformanceMode->value,
                'state' => $this->conformanceState,
                'checks' => $this->checks,
                'blocking' => $this->blocking,
                'advisory' => $this->advisory,
            ],
        ];
    }

    /**
     * Reduce the report to the handful of fields an audit record should carry.
     *
     * Audit metadata is read under pressure, so it stays short: the two attestation states, the scan
     * posture and its outcome, and how many findings of each class were recorded. The full lists live
     * on the release row for whoever follows up.
     *
     * @return  array{sbom: string, provenance: string, conformance: string, conformance_mode: string,
     *          blocking_findings: int, advisory_findings: int}  Flat audit metadata.
     *
     * @since   2.0.0
     */
    public function auditMetadata(): array
    {
        return [
            'sbom' => $this->sbomState->value,
            'provenance' => $this->provenanceState->value,
            'conformance' => $this->conformanceState,
            'conformance_mode' => $this->conformanceMode->value,
            'blocking_findings' => count($this->blocking),
            'advisory_findings' => count($this->advisory),
        ];
    }
}
