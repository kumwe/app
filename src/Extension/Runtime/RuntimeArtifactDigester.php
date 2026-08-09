<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Reduces a deployed tree of extension bytes to the single digest a runtime publication pins.
 *
 * `ExtensionRuntimeMapCompiler` records one digest per extension for its deployed tree and another for
 * its published assets, then recomputes both before it will trust a publication. That is what extends
 * the signature over the compiled map to the files actually on disk: because the digest is taken over a
 * sorted map of relative path to file digest, a file that is added, removed or renamed moves it just as
 * surely as an edited byte does. Symbolic links and anything that is not a plain file or directory
 * abort the walk instead of being skipped, so nothing hostile can be digested away by being unreadable.
 *
 * @since  2.0.0
 */
final readonly class RuntimeArtifactDigester
{
    /**
     * Digest every regular file beneath a directory into one checksum covering the whole tree.
     *
     * @param   string  $root                    Absolute path of the tree to digest; must be a real
     *          directory rather than a link to one.
     * @param   bool    $excludeRetainedPackage  Whether to skip the `.kumwe-package.zip` archive an
     *          install retains at the top of a deployment, so the digest covers only the files the
     *          runtime loads rather than the archive they came from.
     *
     * @return  string  Lowercase SHA-256 hex digest of the JSON-encoded, path-sorted map of relative
     *          path to file digest; an empty tree digests to the digest of `[]`.
     *
     * @throws  RuntimeException  When the root is missing, is a symbolic link or cannot be resolved,
     *          when an entry under it is a symbolic link or is neither a regular file nor a directory,
     *          or when a file cannot be hashed.
     * @throws  \JsonException  When a deployed path is not valid UTF-8 and the entry map cannot be encoded.
     *
     * @since   2.0.0
     */
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

    /**
     * Digest a `vendor/name/version` tree inside a storage root, proving it does not escape that root.
     *
     * The relative path is treated as untrusted even though it reaches this class from a signed
     * publication: it must be a plain three-segment path, every segment that exists is tested for being
     * a symbolic link, and the resolved directory must still sit under the storage root. `$optional`
     * exists for the public asset root, where an extension that publishes no assets legitimately has no
     * directory at all; the digest of an empty tree is returned in that case, so such an extension
     * digests to the same value at publication time and at every later verification.
     *
     * @param   string  $base                    Absolute storage root the relative path resolves beneath.
     * @param   string  $relative                `vendor/name/version` path of the tree to digest.
     * @param   bool    $optional                Whether an absent storage root or tree yields the
     *          empty-tree digest instead of a failure.
     * @param   bool    $excludeRetainedPackage  Whether to skip the retained `.kumwe-package.zip`
     *          archive, as the deployed extension tree does.
     *
     * @return  string  Lowercase SHA-256 hex digest of the tree, or of `[]` when an optional tree is absent.
     *
     * @throws  RuntimeException  When the relative path is not a safe three-segment path, the storage
     *          root is missing or unsafe, a path segment is a symbolic link, a required tree is absent,
     *          or the tree resolves outside the storage root.
     * @throws  \JsonException  When a deployed path is not valid UTF-8 and the entry map cannot be encoded.
     *
     * @since   2.0.0
     */
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
