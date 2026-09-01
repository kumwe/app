<?php

/**
 * Reproduce the package-admission memory ceiling inside the artifact, under the image's memory limit.
 *
 * The zip reader used to ask the archive for the maximum entry size plus one on every entry, meaning to
 * bound what an under-reporting header could make the process expand. The call allocates exactly the length
 * it is asked for, so every entry cost a full ceiling that was never given back: three files weighing a few
 * kilobytes cost 192 MiB against a 256 MiB image, and deployment acceptance failed identically on all three
 * engines. The unit suite pins the cost too, but it pins it wherever the development runner's limit happens
 * to be. This case pins it where the defect appeared — inside the artifact, under the deployed limit, with
 * the production autoloader resolving the reader.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\Extension\Package\InspectedPackage;
use Kumwe\Extension\Package\ZipArchiveContentReader;
use Kumwe\App\Tests\Deployment\CaseReport;

require __DIR__ . '/../Support/deployment-drill-autoload.php';

$case = 'archive-entry-memory-ceiling';
$detail = [];
$archive = null;

try {
    $limit = ini_get('memory_limit');
    if (!is_string($limit) || $limit === '' || $limit === '-1') {
        throw new RuntimeException(
            'This case is only meaningful under a bounded memory limit, and the process has none. '
            . 'The lane runs it with the image\'s limit for exactly that reason.',
        );
    }
    $detail['memory_limit'] = $limit;

    $directory = sys_get_temp_dir() . '/kumwe-artifact-zip-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0o700, true) && !is_dir($directory)) {
        throw new RuntimeException('A working directory for the package could not be created.');
    }
    $archive = $directory . '/package.zip';

    // The reader only expands packages whose safety inspection is clean, so the drill package must
    // carry a root manifest and content that stays under the compression-ratio limit.
    $entries = [
        'kumwe.json' => (string) json_encode([
            'schema' => 1,
            'name' => 'fixture/memory-ceiling',
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => 'Fixture\\MemoryCeiling\\Provider',
            'autoload' => ['psr-4' => ['Fixture\\MemoryCeiling\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'dependencies' => [],
            'migrations' => [],
            'configuration' => new stdClass(),
            'permissions' => [],
            'routes' => [],
            'events' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR),
        'kumwe.sbom.json' => random_bytes(4_096),
        'kumwe.provenance.json' => random_bytes(4_096),
        'a.php' => '<?php',
        'b.php' => '<?php',
        'c.php' => '<?php',
        'd.php' => '<?php',
    ];

    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The package could not be written.');
    }
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    $canonical = realpath($archive);
    if (!is_string($canonical)) {
        throw new RuntimeException('The package path could not be canonicalized.');
    }
    $package = InspectedPackage::inspect($canonical);

    $before = memory_get_usage(true);
    $retained = [];
    foreach ((new ZipArchiveContentReader())->contents($package) as $path => $entry) {
        $retained[$path] = $entry;
    }
    $growth = memory_get_usage(true) - $before;

    if (count($retained) !== count($entries)) {
        throw new RuntimeException(
            sprintf('The reader returned %d entries; the package holds %d.', count($retained), count($entries)),
        );
    }

    $detail['entries'] = count($retained);
    $detail['growth_bytes'] = $growth;
    $detail['peak_bytes'] = memory_get_peak_usage(true);

    @unlink($archive);
    @rmdir($directory);
    $archive = null;

    if ($growth >= 8 * 1024 * 1024) {
        throw new RuntimeException(sprintf(
            'Retaining every entry of a seven-entry package grew memory by %d bytes. The contract is under '
            . '8 MiB: the per-entry ceiling read that cost 448 MiB here is what took the deployment down.',
            $growth,
        ));
    }
} catch (Throwable $failure) {
    if ($archive !== null && is_file($archive)) {
        @unlink($archive);
        @rmdir(dirname($archive));
    }

    CaseReport::fail($case, $failure->getMessage(), $detail);
}

CaseReport::pass($case, $detail);
