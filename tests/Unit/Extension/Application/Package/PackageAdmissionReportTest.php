<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Package;

use Kumwe\App\Extension\Application\Package\PackageAdmissionReport;
use Kumwe\App\Extension\Application\Package\PackageConformanceMode;
use Kumwe\Extension\Package\PackageAttestationState;
use Kumwe\Extension\Package\PackageEvidenceScope;
use Kumwe\Extension\Package\PackageFinding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageAdmissionReport::class)]
/**
 * Proves the admission report exports its policy decision consistently for storage and audit.
 *
 * @since  2.0.0
 */
final class PackageAdmissionReportTest extends TestCase
{
    /**
     * Prove the audit metadata flattens the exact decision the stored summary carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAuditMetadataFlattensTheStoredDecision(): void
    {
        $report = self::report();

        self::assertSame([
            'scope' => 'authoring',
            'sbom' => 'verified',
            'provenance' => 'absent',
            'conformance' => 'warned',
            'conformance_mode' => 'scan',
            'blocking_findings' => 0,
            'advisory_findings' => 1,
        ], $report->auditMetadata());
    }

    /**
     * Prove the stored summary retains the same states, digests and ordered findings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStoredSummaryCarriesTheSameDecision(): void
    {
        $summary = self::report()->toArray();

        self::assertSame('kumwe-extension-admission-v2', $summary['format']);
        self::assertSame('authoring', $summary['scope']);
        self::assertSame('verified', $summary['sbom']['state']);
        self::assertSame(str_repeat('c', 64), $summary['sbom']['sha256']);
        self::assertSame(3, $summary['sbom']['components']);
        self::assertSame('absent', $summary['provenance']['state']);
        self::assertNull($summary['provenance']['builder']);
        self::assertSame('warned', $summary['conformance']['state']);
        self::assertSame(['static_entry_points' => true], $summary['conformance']['checks']);
        self::assertSame([], $summary['conformance']['blocking']);
        self::assertSame(
            [['code' => 'authoring.telemetry', 'message' => 'A telemetry call was found.', 'path' => 'src/Probe.php']],
            $summary['conformance']['advisory'],
        );
    }

    /**
     * Build one admitted report with a single advisory finding and no blockers.
     *
     * @return  PackageAdmissionReport  Report under test.
     *
     * @since   2.0.0
     */
    private static function report(): PackageAdmissionReport
    {
        return new PackageAdmissionReport(
            PackageEvidenceScope::Authoring,
            PackageAttestationState::Verified,
            str_repeat('c', 64),
            3,
            ['bomFormat' => 'CycloneDX'],
            PackageAttestationState::Absent,
            null,
            null,
            null,
            PackageConformanceMode::Scan,
            'warned',
            ['static_entry_points' => true],
            [],
            [new PackageFinding('authoring.telemetry', 'A telemetry call was found.', 'src/Probe.php')],
        );
    }
}
