<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

use Kumwe\CMS\Extension\Application\Package\PackageBillOfMaterials;
use Kumwe\CMS\Extension\Application\Package\PackageCodeConformance;
use Kumwe\CMS\Extension\Application\Package\PackageProvenance;
use RuntimeException;
use ZipArchive;

/**
 * Performs bounded static conformance checks without loading or executing extension code.
 *
 * The per-file checks are not implemented here: they live in `PackageCodeConformance`, which
 * install-time admission runs as well, so what an author sees from `extension:conformance` and what an
 * installation refuses are the same findings produced by the same code. What stays here is everything
 * only a publisher cares about — deterministic entry order, ZIP metadata normalization, and the
 * authoring README — plus the report shape the SDK's own tests are written against.
 *
 * @since  2.0.0
 */
final readonly class StaticConformanceRunner
{
    /**
     * Timestamp assigned by the reproducible package builder to every entry.
     *
     * @var    int
     * @since  2.0.0
     */
    private const ZIP_EPOCH = 315532800;

    /**
     * Bind conformance to the production package inspector and the shared static checks.
     *
     * @param  PackageInspector        $inspector    Safe archive and manifest inspection boundary.
     * @param  PackageCodeConformance  $conformance  Per-file checks shared with install-time admission.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PackageInspector $inspector,
        private PackageCodeConformance $conformance = new PackageCodeConformance(),
    ) {
    }

    /**
     * Inspect and statically validate one installable package.
     *
     * @param   string  $archiveFile  Canonical absolute package path.
     *
     * @return  ConformanceReport  Stable report containing every violation found.
     *
     * @throws  RuntimeException  When an inspected entry cannot be read within its safety bound.
     *
     * @since   2.0.0
     */
    public function run(string $archiveFile): ConformanceReport
    {
        $inspection = $this->inspector->inspect($archiveFile);
        $violations = [];
        $paths = $inspection->paths;
        $sorted = $paths;
        sort($sorted, SORT_STRING);
        if ($paths !== $sorted) {
            $violations[] = 'Archive entries must be sorted bytewise for deterministic packaging.';
        }

        $zip = new ZipArchive();
        if ($zip->open($inspection->archive, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The inspected extension package could not be reopened.');
        }
        if ($zip->numFiles !== count($paths)) {
            $zip->close();
            throw new RuntimeException('The extension package changed before conformance checks began.');
        }
        $metadataNormalized = true;
        try {
            foreach ($paths as $index => $path) {
                $metadataNormalized = $this->checkMetadata($zip, $index, $path, $violations)
                    && $metadataNormalized;
                if (str_ends_with($path, '/')) {
                    continue;
                }
                $contents = $zip->getFromIndex($index, 67_108_865, ZipArchive::FL_UNCHANGED);
                if (!is_string($contents)) {
                    throw new RuntimeException(sprintf('Package entry %s could not be read.', $path));
                }
                if ($this->conformance->isTextPath($path)) {
                    $violations = [...$violations, ...$this->conformance->markerViolations($path, $contents)];
                }
                if ($this->conformance->isPhpPath($path)) {
                    $violations = [...$violations, ...$this->conformance->phpViolations($path, $contents)];
                }
            }
        } finally {
            $zip->close();
        }
        $digest = hash_file('sha256', $inspection->archive);
        if (!is_string($digest) || !hash_equals((string) $inspection->checksum, $digest)) {
            throw new RuntimeException('The extension package changed during conformance checks.');
        }

        $violations = [
            ...$violations,
            ...$this->conformance->referenceViolations($inspection->manifest, $inspection->paths),
        ];
        sort($violations, SORT_STRING);
        $checks = [
            'production_package_safety' => true,
            'manifest_schema' => true,
            'deterministic_entry_order' => $paths === $sorted,
            'deterministic_entry_metadata' => $metadataNormalized,
            'static_php_syntax' => !$this->containsPrefix($violations, 'PHP syntax failure'),
            'strict_types' => !$this->containsPrefix($violations, 'PHP file'),
            'complete_sources' => !$this->containsPrefix($violations, 'Unresolved marker'),
            'manifest_references' => !$this->containsPrefix($violations, 'Manifest reference'),
            'authoring_readme' => in_array('README.md', $paths, true),
            'package_bill_of_materials' => in_array(PackageBillOfMaterials::PATH, $paths, true),
            'package_provenance' => in_array(PackageProvenance::PATH, $paths, true),
        ];
        foreach (
            [
                'package_bill_of_materials' => PackageBillOfMaterials::PATH,
                'package_provenance' => PackageProvenance::PATH,
            ] as $check => $path
        ) {
            if (!$checks[$check]) {
                $violations[] = sprintf('Attestation document %s is missing; rebuild with extension:build.', $path);
            }
        }
        if (!$checks['authoring_readme']) {
            $violations[] = 'Manifest reference README.md is missing.';
            sort($violations, SORT_STRING);
        }

        return new ConformanceReport($inspection, $checks, $violations);
    }

    /**
     * Verify one entry carries the compression, timestamp, and Unix mode emitted by the builder.
     *
     * @param   ZipArchive    $zip         Open inspected package.
     * @param   int           $index       Central-directory index.
     * @param   string        $path        Expected entry path.
     * @param   list<string>  $violations  Accumulated violations.
     *
     * @return  bool  True when every deterministic metadata field matches.
     *
     * @since   2.0.0
     */
    private function checkMetadata(ZipArchive $zip, int $index, string $path, array &$violations): bool
    {
        $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
        $externalAttributes = $this->externalAttributes($zip, $index);
        $valid = is_array($stat)
            && ($stat['name'] ?? null) === $path
            && ($stat['comp_method'] ?? null) === ZipArchive::CM_STORE
            && ($stat['mtime'] ?? null) === self::ZIP_EPOCH
            && $externalAttributes !== null
            && $externalAttributes['operating_system'] === ZipArchive::OPSYS_UNIX
            && (($externalAttributes['attributes'] >> 16) & 0xFFFF) === 0100644
            && !str_ends_with($path, '/');
        if (!$valid) {
            $violations[] = sprintf('Archive metadata for %s is not deterministic.', $path);
        }

        return $valid;
    }

    /**
     * Read external attributes through the mutation-based ZipArchive API.
     *
     * @param   ZipArchive  $zip    Open inspected package.
     * @param   int         $index  Central-directory index.
     *
     * @return  ?array{operating_system: int, attributes: int}  Attributes, or null when unavailable.
     *
     * @since   2.0.0
     */
    private function externalAttributes(ZipArchive $zip, int $index): ?array
    {
        $operatingSystem = 0;
        $attributes = 0;
        if (!$zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return null;
        }
        if (!is_int($operatingSystem) || !is_int($attributes)) {
            return null;
        }

        return ['operating_system' => $operatingSystem, 'attributes' => $attributes];
    }

    /**
     * Determine whether any violation starts with a stable category prefix.
     *
     * @param   list<string>  $violations  Sorted or unsorted violation messages.
     * @param   string        $prefix      Category prefix.
     *
     * @return  bool  True when at least one violation belongs to the category.
     *
     * @since   2.0.0
     */
    private function containsPrefix(array $violations, string $prefix): bool
    {
        foreach ($violations as $violation) {
            if (str_starts_with($violation, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
