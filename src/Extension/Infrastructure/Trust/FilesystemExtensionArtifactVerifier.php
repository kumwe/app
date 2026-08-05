<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use Kumwe\CMS\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Application\Trust\UntrustedPackage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class FilesystemExtensionArtifactVerifier implements ExtensionArtifactVerifier
{
    public const ARTIFACT = '.kumwe-package.zip';

    public function __construct(private string $extensionRoot)
    {
    }

    public function assertMatches(array $release): void
    {
        $runtimePath = $this->string($release, 'runtime_path');
        $packageDigest = $this->digest($release, 'package_sha256');
        $artifactDigest = $this->digest($release, 'artifact_sha256');
        $treeDigest = $this->digest($release, 'deployed_tree_sha256');
        $root = $this->safeRoot($runtimePath);
        $artifact = $root . '/' . self::ARTIFACT;
        $actualArtifact = is_file($artifact) && !is_link($artifact) ? hash_file('sha256', $artifact) : false;
        if (
            !is_string($actualArtifact) || !hash_equals($packageDigest, $actualArtifact)
            || !hash_equals($artifactDigest, $actualArtifact)
        ) {
            throw new UntrustedPackage('The retained extension package does not match its signed digest.');
        }
        if (!hash_equals($treeDigest, self::treeDigest($root))) {
            throw new UntrustedPackage('The deployed extension bytes have changed since signature verification.');
        }
    }

    public static function treeDigest(string $root): string
    {
        if (is_link($root) || !is_dir($root)) {
            throw new UntrustedPackage('An extension deployment root must be a regular directory.');
        }
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                throw new UntrustedPackage('An extension deployment entry could not be inspected.');
            }
            if ($item->isLink()) {
                throw new UntrustedPackage('Extension deployments cannot contain symbolic links.');
            }
            if (!$item->isFile()) {
                throw new UntrustedPackage('Extension deployments can contain only regular files and directories.');
            }
            $relative = substr($item->getPathname(), strlen(rtrim($root, '/')) + 1);
            if ($relative === self::ARTIFACT) {
                continue;
            }
            $digest = hash_file('sha256', $item->getPathname());
            if (!is_string($digest)) {
                throw new UntrustedPackage('An extension deployment file could not be hashed.');
            }
            $entries[$relative] = $digest;
        }
        ksort($entries, SORT_STRING);
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function safeRoot(string $runtimePath): string
    {
        if (str_contains($runtimePath, '..') || str_starts_with($runtimePath, '/')) {
            throw new UntrustedPackage('The installed extension runtime path is unsafe.');
        }
        $root = realpath($this->extensionRoot . '/' . $runtimePath);
        $base = realpath($this->extensionRoot);
        if (!is_string($root) || !is_string($base) || !str_starts_with($root . '/', $base . '/')) {
            throw new UntrustedPackage('The installed extension runtime path is missing or unsafe.');
        }
        $candidate = rtrim($base, '/');
        foreach (explode('/', $runtimePath) as $segment) {
            $candidate .= '/' . $segment;
            if (is_link($candidate)) {
                throw new UntrustedPackage('The installed extension runtime path contains a symbolic link.');
            }
        }
        return $root;
    }

    /** @param array<string, mixed> $release */
    private function string(array $release, string $field): string
    {
        $value = $release[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new UntrustedPackage(sprintf('The extension release %s is missing.', $field));
        }
        return $value;
    }

    /** @param array<string, mixed> $release */
    private function digest(array $release, string $field): string
    {
        $value = $this->string($release, $field);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new UntrustedPackage(sprintf('The extension release %s is invalid.', $field));
        }
        return $value;
    }
}
