<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Package;

use Kumwe\App\Extension\Application\Package\NonConformingPackage;
use Kumwe\App\Extension\Application\Package\PackageAdmissionPolicy;
use Kumwe\App\Extension\Application\Package\PackageAdmissionReport;
use Kumwe\App\Extension\Application\Package\PackageConformanceMode;
use Kumwe\Extension\Package\PackageAttestationState;
use Kumwe\Extension\Package\PackageBillOfMaterials;
use Kumwe\Extension\Package\PackageEvidenceReport;
use Kumwe\Extension\Package\PackageEvidenceScope;
use Kumwe\Extension\Package\PackageFinding;
use LogicException;
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
     * Scan mode retains coded authoring observations as advisory evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testScanModePreservesNeutralAdvisoryFindings(): void
    {
        $evidence = self::evidence(
            findings: [
                new PackageFinding('author.readme.missing', 'The package carries no README.', 'README.md'),
                new PackageFinding('code.php.strict_types', 'A PHP file omits strict types.', 'src/Provider.php'),
            ],
            checks: [
                'archive_safety' => true,
                'static_php_syntax' => true,
                'manifest_references' => true,
                'strict_types' => false,
                'authoring_readme' => false,
                'sbom' => true,
                'provenance' => true,
            ],
        );

        $policy = new PackageAdmissionPolicy();
        $report = $policy->admit($evidence);

        self::assertSame(PackageEvidenceScope::Authoring, $policy->evidenceScope());
        self::assertSame('warned', $report->conformanceState);
        self::assertSame([], $report->blocking);
        self::assertSame($evidence->findings, $report->advisory);
        self::assertSame($evidence->checks, $report->checks);
        self::assertSame('author.readme.missing', $report->toArray()['conformance']['advisory'][0]['code']);
    }

    /**
     * PHP syntax and manifest-reference failures block under the complete scan.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testScanModeRefusesMandatoryPackageFindings(): void
    {
        self::assertRefused(
            new PackageAdmissionPolicy(),
            self::evidence(findings: [
                new PackageFinding('code.php.syntax', 'PHP syntax failure in src/Broken.php.', 'src/Broken.php'),
                new PackageFinding(
                    'manifest.reference.class_missing',
                    'Manifest class Provider is absent.',
                    'src/Provider.php',
                ),
            ]),
            '[code.php.syntax]',
        );
    }

    /**
     * Off mode still blocks mandatory package evidence while omitting author-only checks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOffModeStillRefusesPhpSyntaxFailure(): void
    {
        $policy = new PackageAdmissionPolicy(PackageConformanceMode::Off);
        self::assertSame(PackageEvidenceScope::Package, $policy->evidenceScope());
        self::assertRefused(
            $policy,
            self::evidence(
                scope: PackageEvidenceScope::Package,
                findings: [new PackageFinding(
                    'code.php.syntax',
                    'PHP syntax failure in src/Broken.php.',
                    'src/Broken.php',
                )],
            ),
            '[code.php.syntax]',
        );
    }

    /**
     * Evidence collected at a different depth than policy requested is refused outright.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEvidenceCollectedAtTheWrongDepthIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('wrong inspection depth');

        (new PackageAdmissionPolicy(PackageConformanceMode::Scan))
            ->admit(self::evidence(scope: PackageEvidenceScope::Package));
    }

    /**
     * An invalid attestation refuses admission even when no other finding blocks the package.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidAttestationWithoutOtherFindingsIsStillRefused(): void
    {
        self::assertRefused(
            new PackageAdmissionPolicy(PackageConformanceMode::Off),
            self::evidence(
                scope: PackageEvidenceScope::Package,
                sbomState: PackageAttestationState::Invalid,
                checks: ['sbom' => false],
            ),
            'attestation evidence is invalid',
        );
    }

    /**
     * Invalid inventory evidence fails closed when authoring checks are disabled.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvalidAttestationIsRefusedInOffMode(): void
    {
        self::assertRefused(
            new PackageAdmissionPolicy(PackageConformanceMode::Off),
            self::evidence(
                scope: PackageEvidenceScope::Package,
                sbomState: PackageAttestationState::Invalid,
                findings: [new PackageFinding(
                    'attestation.sbom.mismatch',
                    'The packaged bill of materials does not describe this package.',
                    PackageBillOfMaterials::PATH,
                )],
                checks: ['sbom' => false, 'provenance' => false],
            ),
            '[attestation.sbom.mismatch]',
        );
    }

    /**
     * Off mode records mandatory evidence honestly as a package-only scan.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOffModeRecordsVerifiedEvidenceAsPackageOnly(): void
    {
        $checks = [
            'archive_safety' => true,
            'static_php_syntax' => true,
            'manifest_references' => true,
            'sbom' => true,
            'provenance' => true,
        ];
        $report = (new PackageAdmissionPolicy(PackageConformanceMode::Off))->admit(self::evidence(
            scope: PackageEvidenceScope::Package,
            checks: $checks,
        ));

        self::assertSame(PackageAttestationState::Verified, $report->sbomState);
        self::assertSame(PackageAttestationState::Verified, $report->provenanceState);
        self::assertSame(PackageEvidenceScope::Package, $report->scope);
        self::assertSame('package_only', $report->conformanceState);
        self::assertSame($checks, $report->checks);
        self::assertSame([], $report->blocking);
        self::assertSame([], $report->advisory);
    }

    /**
     * Proves absent attestations remain an honest admissible state rather than being reported as verified.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAbsentAttestationsRemainAdmissibleAndRecorded(): void
    {
        $report = (new PackageAdmissionPolicy())->admit(self::evidence(
            sbomState: PackageAttestationState::Absent,
            provenanceState: PackageAttestationState::Absent,
        ));

        self::assertSame(PackageAttestationState::Absent, $report->sbomState);
        self::assertSame(PackageAttestationState::Absent, $report->provenanceState);
        self::assertSame('passed', $report->conformanceState);
    }

    /**
     * A new SDK finding blocks until App policy classifies it explicitly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownFindingCodeFailsClosed(): void
    {
        self::assertRefused(
            new PackageAdmissionPolicy(),
            self::evidence(findings: [
                new PackageFinding('future.package.fact', 'A new package fact needs host policy.'),
            ]),
            '[future.package.fact]',
        );
    }

    /**
     * Prove one evidence report is refused with a machine-addressable code in the explanation.
     *
     * @param   PackageAdmissionPolicy  $policy    App policy under test.
     * @param   PackageEvidenceReport   $evidence  Neutral evidence offered to it.
     * @param   string                  $code      Finding code expected in the refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertRefused(
        PackageAdmissionPolicy $policy,
        PackageEvidenceReport $evidence,
        string $code,
    ): void {
        try {
            $policy->admit($evidence);
            self::fail('Mandatory package evidence was admitted.');
        } catch (NonConformingPackage $failure) {
            self::assertStringContainsString($code, $failure->getMessage());
        }
    }

    /**
     * Build deterministic SDK evidence for host-policy tests.
     *
     * @param   PackageEvidenceScope     $scope      Neutral evidence depth represented by the report.
     * @param   PackageAttestationState  $sbomState  Inventory state.
     * @param   PackageAttestationState  $provenanceState  Provenance state.
     * @param   list<PackageFinding>     $findings   Neutral coded findings.
     * @param   array<string, bool>      $checks     Neutral objective checks.
     *
     * @return  PackageEvidenceReport  Complete neutral evidence.
     *
     * @since   2.0.0
     */
    private static function evidence(
        PackageEvidenceScope $scope = PackageEvidenceScope::Authoring,
        PackageAttestationState $sbomState = PackageAttestationState::Verified,
        PackageAttestationState $provenanceState = PackageAttestationState::Verified,
        array $findings = [],
        array $checks = [
            'archive_safety' => true,
            'static_php_syntax' => true,
            'manifest_references' => true,
            'strict_types' => true,
            'complete_sources' => true,
            'text_encoding' => true,
            'authoring_readme' => true,
            'sbom' => true,
            'provenance' => true,
        ],
    ): PackageEvidenceReport {
        return new PackageEvidenceReport(
            $scope,
            $sbomState,
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            7,
            ['bomFormat' => 'CycloneDX'],
            $provenanceState,
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'kumwe-extension-sdk@0.2.0',
            ['predicateType' => 'https://slsa.dev/provenance/v1'],
            $checks,
            $findings,
        );
    }
}
