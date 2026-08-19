<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Package;

use FilesystemIterator;
use Kumwe\App\Extension\Application\Package\NonConformingPackage;
use Kumwe\App\Extension\Application\Package\PackageAdmissionReport;
use Kumwe\App\Extension\Application\Package\PackageAdmissionScanner;
use Kumwe\App\Extension\Application\Package\PackageAttestationState;
use Kumwe\App\Extension\Application\Package\PackageBillOfMaterials;
use Kumwe\App\Extension\Application\Package\PackageCodeConformance;
use Kumwe\App\Extension\Application\Package\PackageConformanceMode;
use Kumwe\App\Extension\Application\Package\PackageProvenance;
use Kumwe\App\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\App\Extension\Development\ComponentScaffolder;
use Kumwe\App\Extension\Development\DeterministicPackageBuilder;
use Kumwe\App\Extension\Development\PackageInspector;
use Kumwe\App\Extension\Development\ScaffoldRequest;
use Kumwe\App\Extension\Domain\ExtensionManifest;
use Kumwe\App\Extension\Infrastructure\Package\ZipArchiveContentReader;
use Kumwe\App\Extension\Infrastructure\Package\ZipArchiveReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

#[CoversClass(PackageAdmissionScanner::class)]
#[CoversClass(PackageAdmissionReport::class)]
#[CoversClass(PackageBillOfMaterials::class)]
#[CoversClass(PackageProvenance::class)]
#[CoversClass(PackageCodeConformance::class)]
#[CoversClass(PackageAttestationState::class)]
#[CoversClass(PackageConformanceMode::class)]
#[CoversClass(ZipArchiveContentReader::class)]
/**
 * Exercises install-time admission over real packages built by the shipped SDK builder.
 *
 * Every fixture is scaffolded and built rather than hand-assembled, so the tests prove what an
 * installation will actually meet: the attestations the builder embeds, the digests it records, and the
 * findings the shared static checks raise over generated code.
 *
 * @since  2.0.0
 */
final class PackageAdmissionScannerTest extends TestCase
{
    /**
     * Canonical private root allocated for one test invocation.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $temporary;

    /**
     * Allocate a private test root with a canonical absolute path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary = sys_get_temp_dir() . '/kumwe-admission-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->temporary, 0700));
    }

    /**
     * Remove only the private root allocated by this test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        if (isset($this->temporary) && is_dir($this->temporary) && !is_link($this->temporary)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->temporary, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }
                if ($entry->isDir() && !$entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->temporary);
        }
        parent::tearDown();
    }

    /**
     * A package built by the SDK carries both attestations and reconciles against its own bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBuiltPackageCarriesVerifiableBillOfMaterialsAndProvenance(): void
    {
        [$archive, $manifest] = $this->build('acme/admission-clean', 'Acme\\AdmissionClean');
        $report = $this->scanner()->scan($archive, $manifest);

        self::assertSame(PackageAttestationState::Verified, $report->sbomState);
        self::assertSame(PackageAttestationState::Verified, $report->provenanceState);
        self::assertSame('passed', $report->conformanceState);
        self::assertSame([], $report->blocking);
        self::assertGreaterThan(5, $report->sbomComponents);
        self::assertSame(
            PackageProvenance::BUILDER_NAME . '@' . PackageProvenance::BUILDER_VERSION,
            $report->builderReference,
        );
        self::assertSame('CycloneDX', $report->sbom['bomFormat'] ?? null);
        self::assertSame(PackageBillOfMaterials::SPEC_VERSION, $report->sbom['specVersion'] ?? null);
        self::assertSame('verified', $report->auditMetadata()['sbom']);
    }

    /**
     * Two builds of one source tree produce byte-identical packages despite carrying attestations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAttestationsDoNotBreakByteReproducibility(): void
    {
        $source = $this->temporary . '/reproducible';
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            'acme/admission-repeatable',
            'Acme\\AdmissionRepeatable',
            $source,
            'Repeatable Component',
        ));
        $builder = new DeterministicPackageBuilder($this->inspector());
        $first = $builder->build($source, $this->temporary . '/first.zip');
        $second = $builder->build($source, $this->temporary . '/second.zip');

        self::assertSame((string) $first->inspection->checksum, (string) $second->inspection->checksum);
        self::assertContains(PackageBillOfMaterials::PATH, $first->inspection->paths);
        self::assertContains(PackageProvenance::PATH, $first->inspection->paths);
    }

    /**
     * A packaged file the bill of materials does not describe refuses the install regardless of mode.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnlistedPackagedFileIsRefusedEvenWhenTheScanOnlyWarns(): void
    {
        [$archive, $manifest] = $this->build('acme/admission-smuggled', 'Acme\\AdmissionSmuggled');
        $this->addEntry($archive, 'src/Smuggled.php', "<?php\n\ndeclare(strict_types=1);\n");

        $this->expectException(NonConformingPackage::class);
        $this->expectExceptionMessage('does not describe this package');
        $this->scanner(PackageConformanceMode::Warn)->scan($archive, $manifest);
    }

    /**
     * A packaged file whose bytes changed after the inventory was recorded refuses the install.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRewrittenPackagedFileIsRefused(): void
    {
        [$archive, $manifest] = $this->build('acme/admission-rewritten', 'Acme\\AdmissionRewritten');
        $this->addEntry($archive, 'README.md', "# Replaced after the inventory was recorded\n");

        $this->expectException(NonConformingPackage::class);
        $this->expectExceptionMessage('records a different digest');
        $this->scanner()->scan($archive, $manifest);
    }

    /**
     * A package with no attestations still installs, and is recorded as having claimed nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPackageWithoutAttestationsIsAdmittedAndRecordedAsAbsent(): void
    {
        [$archive, $manifest] = $this->build('acme/admission-legacy', 'Acme\\AdmissionLegacy');
        $this->removeEntries($archive, [PackageBillOfMaterials::PATH, PackageProvenance::PATH]);
        $report = $this->scanner()->scan($archive, $manifest);

        self::assertSame(PackageAttestationState::Absent, $report->sbomState);
        self::assertSame(PackageAttestationState::Absent, $report->provenanceState);
        self::assertNull($report->sbomSha256);
        self::assertSame('passed', $report->conformanceState);
    }

    /**
     * A provenance statement with no bill of materials beside it cannot be bound and is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProvenanceWithoutABillOfMaterialsIsRefused(): void
    {
        [$archive, $manifest] = $this->build('acme/admission-unbound', 'Acme\\AdmissionUnbound');
        $this->removeEntries($archive, [PackageBillOfMaterials::PATH]);

        $this->expectException(NonConformingPackage::class);
        $this->expectExceptionMessage('no bill of materials to bind it to');
        $this->scanner()->scan($archive, $manifest);
    }

    /**
     * PHP that does not parse blocks the install under enforce and is recorded under warn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnparseablePhpBlocksUnderEnforceAndWarnsUnderWarn(): void
    {
        $source = $this->temporary . '/broken';
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            'acme/admission-broken',
            'Acme\\AdmissionBroken',
            $source,
            'Broken Component',
        ));
        $path = $source . '/src/Application/OverviewService.php';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $broken = $contents . "\nfunction (\n";
        self::assertSame(strlen($broken), file_put_contents($path, $broken, LOCK_EX));
        $archive = (new DeterministicPackageBuilder($this->inspector()))
            ->build($source, $this->temporary . '/broken.zip');
        $manifest = $archive->inspection->manifest;

        $warned = $this->scanner(PackageConformanceMode::Warn)->scan($archive->archive, $manifest);
        self::assertSame('warned', $warned->conformanceState);
        self::assertFalse($warned->checks['static_php_syntax']);
        self::assertNotSame([], $warned->blocking);
        self::assertSame(PackageAttestationState::Verified, $warned->sbomState);

        $this->expectException(NonConformingPackage::class);
        $this->expectExceptionMessage('failed install-time code conformance');
        $this->scanner()->scan($archive->archive, $manifest);
    }

    /**
     * Turning the scan off records `skipped`, and still verifies the attestations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDisabledScanStillVerifiesAttestations(): void
    {
        [$archive, $manifest] = $this->build('acme/admission-unscanned', 'Acme\\AdmissionUnscanned');
        $report = $this->scanner(PackageConformanceMode::Off)->scan($archive, $manifest);

        self::assertSame('skipped', $report->conformanceState);
        self::assertSame([], $report->checks);
        self::assertSame(PackageAttestationState::Verified, $report->sbomState);
        self::assertSame(PackageAttestationState::Verified, $report->provenanceState);
    }

    /**
     * A report for an installation with no scanner asserts nothing rather than a pass.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNotTakenReportAssertsNothing(): void
    {
        $report = PackageAdmissionReport::notTaken();

        self::assertSame('skipped', $report->conformanceState);
        self::assertSame(PackageAttestationState::Absent, $report->sbomState);
        self::assertSame(0, $report->auditMetadata()['blocking_findings']);
    }

    /**
     * Scaffold and build one package, returning its archive path and parsed manifest.
     *
     * @param   string  $identifier  `vendor/name` identifier for the scaffolded component.
     * @param   string  $namespace   PSR-4 namespace prefix for the scaffolded component.
     *
     * @return  array{0: string, 1: ExtensionManifest}  Archive path and its parsed manifest.
     *
     * @since   2.0.0
     */
    private function build(string $identifier, string $namespace): array
    {
        $source = $this->temporary . '/' . str_replace('/', '-', $identifier);
        (new ComponentScaffolder())->scaffold(new ScaffoldRequest(
            $identifier,
            $namespace,
            $source,
            'Admission Fixture',
        ));
        $result = (new DeterministicPackageBuilder($this->inspector()))
            ->build($source, $this->temporary . '/' . str_replace('/', '-', $identifier) . '.zip');

        return [$result->archive, $result->inspection->manifest];
    }

    /**
     * Write one entry into an already-published package, simulating a post-build edit.
     *
     * @param   string  $archive   Absolute package path.
     * @param   string  $path      Package path to write.
     * @param   string  $contents  Entry bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addEntry(string $archive, string $path, string $contents): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        self::assertTrue($zip->addFromString($path, $contents));
        self::assertTrue($zip->close());
    }

    /**
     * Delete entries from an already-published package, simulating a package built before attestations.
     *
     * @param   string        $archive  Absolute package path.
     * @param   list<string>  $paths    Package paths to remove.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removeEntries(string $archive, array $paths): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        foreach ($paths as $path) {
            self::assertTrue($zip->deleteName($path));
        }
        self::assertTrue($zip->close());
    }

    /**
     * Build the admission scanner under one conformance posture.
     *
     * @param   PackageConformanceMode  $mode  Posture the code scan runs under.
     *
     * @return  PackageAdmissionScanner  Scanner bound to the shipped ZIP content reader.
     *
     * @since   2.0.0
     */
    private function scanner(PackageConformanceMode $mode = PackageConformanceMode::Enforce): PackageAdmissionScanner
    {
        return new PackageAdmissionScanner(
            new ZipArchiveContentReader(),
            new PackageCodeConformance(),
            $mode,
        );
    }

    /**
     * Build the production package inspector the SDK builder verifies through.
     *
     * @return  PackageInspector  Inspector bound to the shipped reader and safety policy.
     *
     * @since   2.0.0
     */
    private function inspector(): PackageInspector
    {
        return new PackageInspector(new ZipArchiveReader(), new PackageSafetyPolicy());
    }
}
