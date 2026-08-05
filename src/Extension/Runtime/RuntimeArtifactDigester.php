<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final readonly class RuntimeArtifactDigester
{
    public function digest(string $root, bool $excludeRetainedPackage = false): string
    {
        if (!is_dir($root) || is_link($root)) {
            throw new RuntimeException('A runtime artifact root is missing or unsafe.');
        }

        $resolved = realpath($root);
        if (!is_string($resolved)) {
            throw new RuntimeException('A runtime artifact root cannot be resolved.');
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || $item->isLink()) {
                throw new RuntimeException('Runtime artifacts may contain only regular files and directories.');
            }
            if ($item->isDir()) {
                continue;
            }
            if (!$item->isFile()) {
                throw new RuntimeException('Runtime artifacts may contain only regular files and directories.');
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($resolved) + 1));
            if ($excludeRetainedPackage && $relative === '.kumwe-package.zip') {
                continue;
            }
            $digest = hash_file('sha256', $item->getPathname());
            if (!is_string($digest)) {
                throw new RuntimeException('A runtime artifact file could not be digested.');
            }
            $files[$relative] = $digest;
        }
        ksort($files, SORT_STRING);

        return hash('sha256', json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function digestRelative(
        string $base,
        string $relative,
        bool $optional = false,
        bool $excludeRetainedPackage = false,
    ): string {
        if (
            preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+$#D', $relative) !== 1
            || str_contains($relative, '..')
        ) {
            throw new RuntimeException('The runtime artifact relative path is unsafe.');
        }
        if (!file_exists($base) && $optional) {
            return hash('sha256', '[]');
        }
        if (!is_dir($base) || is_link($base)) {
            throw new RuntimeException('The runtime artifact storage root is missing or unsafe.');
        }
        $resolvedBase = realpath($base);
        if (!is_string($resolvedBase)) {
            throw new RuntimeException('The runtime artifact storage root cannot be resolved.');
        }
        $candidate = rtrim($base, '/') . '/' . $relative;
        $component = rtrim($base, '/');
        foreach (explode('/', $relative) as $segment) {
            $component .= '/' . $segment;
            if (is_link($component)) {
                throw new RuntimeException('Runtime artifact paths may not contain symbolic links.');
            }
            if (!file_exists($component)) {
                break;
            }
        }
        if (!file_exists($candidate)) {
            if ($optional) {
                return hash('sha256', '[]');
            }
            throw new RuntimeException('A runtime artifact root is missing or unsafe.');
        }
        $resolved = realpath($candidate);
        if (!is_string($resolved) || !str_starts_with($resolved . '/', $resolvedBase . '/')) {
            throw new RuntimeException('A runtime artifact root escapes its storage boundary.');
        }

        return $this->digest($candidate, $excludeRetainedPackage);
    }
}
