<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Package;

use Kumwe\App\Extension\Application\Package\NonConformingPackage;
use Kumwe\App\Extension\Application\Package\PackageAdmissionPolicy;
use Kumwe\App\Extension\Application\Package\PackageAdmissionReport;
use Kumwe\App\Extension\Application\Package\PackageConformanceMode;
use Kumwe\Extension\Package\PackageAttestationState;
use Kumwe\Extension\Package\PackageEvidenceReport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageAdmissionPolicy::class)]
#[CoversClass(PackageAdmissionReport::class)]
#[CoversClass(NonConformingPackage::class)]
#[CoversClass(PackageConformanceMode::class)]
/**
 * Proves that the App adds policy without changing the SDK's neutral evidence.
 *
 * @since  2.0.0
 */
final class PackageAdmissionPolicyTest extends TestCase
{
    /**
     * Warning mode admits code findings and retains SDK order while separating policy state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWarningModePreservesNeutralFindings(): void
    {
        $evidence = self::evidence(
            integrity: ['PHP syntax failure in src/Broken.php.', 'Manifest reference Provider is absent.'],
            quality: ['The package carries no README.md for operators to read.'],
            checks: [
                'static_php_syntax' => false,
                'manifest_references' => false,
                'strict_types' => true,
                'complete_sources' => true,
                'authoring_readme' => false,
                'sbom' => true,
                'provenance' => true,
            ],
        );

        $report = (new PackageAdmissionPolicy(PackageConformanceMode::Warn))->admit($evidence);

        self::assertSame('warned', $report->conformanceState);
        self::assertSame($evidence->integrityFindings, $report->blocking);
        self::assertSame($evidence->qualityFindings, $report->advisory);
        self::assertSame([
            'static_php_syntax' => false,
            'manifest_references' => false,
            'strict_types' => true,
            'complete_sources' => true,
            'authoring_readme' => false,
        ], $report->checks);
    }

    /**
     * Enforce mode refuses the same neutral integrity evidence warning mode records.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEnforceModeRefusesCodeIntegrityFinding(): void
    {
        $this->expectException(NonConformingPackage::class);
        $this->expectExceptionMessage('failed install-time code conformance');

        (new PackageAdmissionPolicy())->admit(self::evidence(
            integrity: ['PHP syntax failure in src/Broken.php.'],
        ));
    }

    /**
     * Invalid inventory evidence fails closed even when code conformance is disabled.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidAttestationIsRefusedInOffMode(): void
    {
        $this->expectException(NonConformingPackage::class);
        $this->expectExceptionMessage('does not describe this package');

        (new PackageAdmissionPolicy(PackageConformanceMode::Off))->admit(self::evidence(
            sbomState: PackageAttestationState::Invalid,
            integrity: ['The packaged bill of materials does not describe this package: digest mismatch.'],
            checks: ['sbom' => false, 'provenance' => true],
        ));
    }

    /**
     * Off mode keeps verified evidence but asserts no code scan outcome.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOffModeRecordsVerifiedEvidenceAsSkipped(): void
    {
        $report = (new PackageAdmissionPolicy(PackageConformanceMode::Off))->admit(self::evidence());

        self::assertSame(PackageAttestationState::Verified, $report->sbomState);
        self::assertSame(PackageAttestationState::Verified, $report->provenanceState);
        self::assertSame('skipped', $report->conformanceState);
        self::assertSame([], $report->checks);
        self::assertSame([], $report->blocking);
        self::assertSame([], $report->advisory);
    }

    /**
     * Build deterministic SDK evidence for host-policy tests.
     *
     * @param   PackageAttestationState  $sbomState       Inventory state.
     * @param   list<string>             $integrity       Neutral integrity findings.
     * @param   list<string>             $quality         Neutral quality findings.
     * @param   array<string, bool>      $checks          Neutral objective checks.
     *
     * @return  PackageEvidenceReport  Complete neutral evidence.
     *
     * @since   2.0.0
     */
    private static function evidence(
        PackageAttestationState $sbomState = PackageAttestationState::Verified,
        array $integrity = [],
        array $quality = [],
        array $checks = [
            'static_php_syntax' => true,
            'manifest_references' => true,
            'strict_types' => true,
            'complete_sources' => true,
            'authoring_readme' => true,
            'sbom' => true,
            'provenance' => true,
        ],
    ): PackageEvidenceReport {
        return new PackageEvidenceReport(
            $sbomState,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            7,
            ['bomFormat' => 'CycloneDX'],
            PackageAttestationState::Verified,
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'kumwe-extension-sdk@0.2.0',
            ['predicateType' => 'https://slsa.dev/provenance/v1'],
            $checks,
            $integrity,
            $quality,
        );
    }
}
