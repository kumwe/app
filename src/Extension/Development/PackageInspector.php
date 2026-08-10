<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Package\ArchiveReader;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use RuntimeException;
use ZipArchive;

/**
 * Inspects an extension package through the production archive and manifest safety boundaries.
 *
 * No provider, migration, listener, or packaged PHP file is loaded. The only expanded entry is the
 * already bounded root manifest, which is passed directly to `ExtensionManifest` for strict parsing.
 *
 * @since  2.0.0
 */
final readonly class PackageInspector
{
    /**
     * Largest signed manifest document expanded during code-free inspection.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_MANIFEST_BYTES = 1_048_576;

    /**
     * Bind the inspector to the same central-directory reader and safety policy used by installation.
     *
     * @param  ArchiveReader        $archives  Reader that classifies entries without extracting them.
     * @param  PackageSafetyPolicy  $safety    Installation archive limits and link/path policy.
     *
     * @since  2.0.0
     */
    public function __construct(private ArchiveReader $archives, private PackageSafetyPolicy $safety)
    {
    }

    /**
     * Inspect a readable regular ZIP and return its code-free package inventory.
     *
     * @param   string  $archiveFile  Absolute path to the package being inspected.
     *
     * @return  PackageInspection  Verified archive metadata and parsed manifest.
     *
     * @throws  InvalidArgumentException  When the path is relative, linked, unreadable, or the package is unsafe.
     * @throws  RuntimeException  When the manifest or checksum cannot be read completely.
     *
     * @since   2.0.0
     */
    public function inspect(string $archiveFile): PackageInspection
    {
        if (!str_starts_with($archiveFile, '/')) {
            throw new InvalidArgumentException('The extension package path must be absolute.');
        }
        $canonical = realpath($archiveFile);
        if (
            !is_string($canonical)
            || $canonical !== $archiveFile
            || !is_file($canonical)
            || is_link($archiveFile)
            || !is_readable($canonical)
        ) {
            throw new InvalidArgumentException('The extension package must be a canonical readable regular file.');
        }
        $before = lstat($canonical);
        if (!is_array($before)) {
            throw new RuntimeException('The extension package metadata could not be read.');
        }

        $package = $this->archives->inspect($canonical);
        $this->safety->assertSafe($package);
        foreach ($package->entries() as $entry) {
            if (
                $entry->path()->value() === 'kumwe.json'
                && $entry->uncompressedBytes() > self::MAXIMUM_MANIFEST_BYTES
            ) {
                throw new InvalidArgumentException('The extension package manifest exceeds 1 MiB.');
            }
        }
        $digest = hash_file('sha256', $canonical);
        if (!is_string($digest)) {
            throw new RuntimeException('The extension package checksum could not be calculated.');
        }
        $zip = new ZipArchive();
        if ($zip->open($canonical, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('The extension package is not a readable ZIP file.');
        }
        try {
            $manifestJson = $zip->getFromName(
                'kumwe.json',
                self::MAXIMUM_MANIFEST_BYTES + 1,
                ZipArchive::FL_UNCHANGED,
            );
            if (!is_string($manifestJson)) {
                throw new RuntimeException('The extension package manifest could not be read.');
            }
        } finally {
            $zip->close();
        }

        $paths = [];
        $expandedBytes = 0;
        foreach ($package->entries() as $entry) {
            $paths[] = $entry->path()->value();
            $expandedBytes += $entry->uncompressedBytes();
        }
        $after = lstat($canonical);
        $confirmedDigest = hash_file('sha256', $canonical);
        if (
            !is_array($after)
            || $after['dev'] !== $before['dev']
            || $after['ino'] !== $before['ino']
            || $after['size'] !== $before['size']
            || $after['mtime'] !== $before['mtime']
            || $after['ctime'] !== $before['ctime']
            || !is_string($confirmedDigest)
            || !hash_equals($digest, $confirmedDigest)
        ) {
            throw new RuntimeException('The extension package changed while it was inspected.');
        }

        return new PackageInspection(
            $canonical,
            PackageChecksum::sha256($digest),
            $expandedBytes,
            $paths,
            ExtensionManifest::fromJson($manifestJson),
        );
    }
}
