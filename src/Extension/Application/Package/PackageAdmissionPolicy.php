<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

use LogicException;
use Kumwe\Extension\Package\PackageAttestationState;
use Kumwe\Extension\Package\PackageEvidenceReport;
use Kumwe\Extension\Package\PackageEvidenceScope;
use Kumwe\Extension\Package\PackageFinding;

/**
 * Applies App deployment policy to the SDK's neutral package evidence.
 *
 * The inspector remains reusable because it reports facts only. This class is the sole place the App
 * turns those facts into an install decision. Archive, manifest, executable/reference and attestation
 * findings always fail closed. Authoring-quality findings remain advisory and are collected only in
 * scan mode. Coded findings and their deterministic order are retained exactly as produced by the SDK.
 *
 * @since  2.0.0
 */
final readonly class PackageAdmissionPolicy
{
    /**
     * SDK facts that are advisory authoring quality rather than package integrity.
     *
     * Every unknown code fails closed. An SDK release that adds a fact therefore requires an explicit
     * App policy decision instead of silently admitting it under a broad prefix.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private const array ADVISORY_CODES = [
        'author.readme.missing' => true,
        'code.php.strict_types' => true,
        'source.marker.unresolved' => true,
        'source.text.encoding' => true,
    ];

    /**
     * Select whether App also collects advisory authoring evidence.
     *
     * @param  PackageConformanceMode  $mode  Author-evidence collection posture.
     *
     * @since  2.0.0
     */
    public function __construct(private PackageConformanceMode $mode = PackageConformanceMode::Scan)
    {
    }

    /**
     * Select the neutral SDK evidence depth required by this deployment posture.
     *
     * @return  PackageEvidenceScope  Authoring evidence for scan mode; mandatory package evidence for off.
     *
     * @since   2.0.0
     */
    public function evidenceScope(): PackageEvidenceScope
    {
        return $this->mode === PackageConformanceMode::Scan
            ? PackageEvidenceScope::Authoring
            : PackageEvidenceScope::Package;
    }

    /**
     * Admit or refuse the package described by one neutral SDK report.
     *
     * @param   PackageEvidenceReport  $evidence  Complete, deterministic SDK inspection result.
     *
     * @return  PackageAdmissionReport  Admitted evidence plus the App policy outcome.
     *
     * @throws  NonConformingPackage  When any mandatory package or attestation finding is present.
     * @throws  LogicException  When evidence was collected at a different depth than policy requested.
     *
     * @since   2.0.0
     */
    public function admit(PackageEvidenceReport $evidence): PackageAdmissionReport
    {
        if ($evidence->scope !== $this->evidenceScope()) {
            throw new LogicException('Extension package evidence was collected at the wrong inspection depth.');
        }

        [$blocking, $advisory] = $this->classify($evidence->findings);
        $this->assertAttestations($evidence, $blocking);
        if ($blocking !== []) {
            throw new NonConformingPackage(sprintf(
                'The extension package failed mandatory admission: %s',
                implode(' ', array_map(
                    static fn (PackageFinding $finding): string => sprintf(
                        '[%s] %s',
                        $finding->code,
                        $finding->message,
                    ),
                    $blocking,
                )),
            ));
        }

        return new PackageAdmissionReport(
            $evidence->scope,
            $evidence->sbomState,
            $evidence->sbomSha256,
            $evidence->sbomComponents,
            $evidence->sbom,
            $evidence->provenanceState,
            $evidence->provenanceSha256,
            $evidence->builderReference,
            $evidence->provenance,
            $this->mode,
            $this->mode === PackageConformanceMode::Off
                ? 'package_only'
                : ($advisory === [] ? 'passed' : 'warned'),
            $evidence->checks,
            [],
            $advisory,
        );
    }

    /**
     * Refuse evidence documents that are present but invalid, independently of code posture.
     *
     * @param   PackageEvidenceReport  $evidence  SDK evidence to validate.
     * @param   list<PackageFinding>   $blocking  Mandatory coded findings already classified.
     *
     * @return  void
     *
     * @throws  NonConformingPackage  When either attestation state is invalid.
     *
     * @since   2.0.0
     */
    private function assertAttestations(PackageEvidenceReport $evidence, array $blocking): void
    {
        foreach ([$evidence->sbomState, $evidence->provenanceState] as $state) {
            if (in_array($state, [PackageAttestationState::Invalid, PackageAttestationState::NotInspected], true)) {
                if ($blocking !== []) {
                    return;
                }
                throw new NonConformingPackage(
                    'The extension package attestation evidence is invalid or could not be inspected.',
                );
            }
        }
    }

    /**
     * Apply the App's explicit disposition to every neutral SDK finding.
     *
     * @param   list<PackageFinding>  $findings  Deterministically ordered SDK findings.
     *
     * @return  array{list<PackageFinding>, list<PackageFinding>}  Blocking and advisory findings.
     *
     * @since   2.0.0
     */
    private function classify(array $findings): array
    {
        $blocking = [];
        $advisory = [];
        foreach ($findings as $finding) {
            if (isset(self::ADVISORY_CODES[$finding->code])) {
                $advisory[] = $finding;
                continue;
            }
            $blocking[] = $finding;
        }

        return [$blocking, $advisory];
    }
}
