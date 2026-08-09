<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use Kumwe\CMS\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Application\Trust\UntrustedPackage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Re-proves that a deployed extension on the local filesystem still digests to what its signature covered.
 *
 * This is the shipped `ExtensionArtifactVerifier`. An install retains the signed package beside the
 * unpacked files and records three digests on the release; this verifier re-hashes the retained archive
 * and re-walks the deployment directory, so an edit made after installation is caught before the runtime
 * is allowed to trust the extension again. The deployment digest is taken over a sorted map of relative
 * path to file digest, which makes it sensitive to added, removed and renamed files and not only to
 * changed bytes. Symbolic links are refused both inside the deployment and along the path that reaches
 * it, so nothing outside the configured extension root can be presented as extension bytes.
 *
 * @since  2.0.0
 */
final readonly class FilesystemExtensionArtifactVerifier implements ExtensionArtifactVerifier
{
    /**
     * Name the signed package is retained under inside an extension's deployment directory.
     *
     * `DoctrineExtensionManager` copies the uploaded archive here as it stages a deployment, and the
     * digest walk skips it, because that digest covers the files the runtime loads rather than the
     * archive they were unpacked from.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ARTIFACT = '.kumwe-package.zip';

    /**
     * Bind the verifier to the directory every extension deployment lives under.
     *
     * @param  string  $extensionRoot  Base directory a release's `runtime_path` is resolved beneath;
     *         anything that resolves outside it is refused rather than verified.
     *
     * @since  2.0.0
     */
    public function __construct(private string $extensionRoot)
    {
    }

    /**
     * Assert that the deployment on disk still matches the digests recorded for the release.
     *
     * The retained package has to hash to both `package_sha256` and `artifact_sha256` — the value the
     * signature covered and the value the release record pinned, which must agree — and the deployment
     * tree has to hash to `deployed_tree_sha256`. Comparisons go through `hash_equals`, and a mismatch
     * reports only that the retained package or the deployed bytes no longer match — never which file or
     * which byte gave it away.
     *
     * @param   array<string, mixed>  $release  Release record holding the runtime path and the three
     *          digests captured at install time.
     *
     * @return  void
     *
     * @throws  UntrustedPackage  When a required field is missing or malformed, when the runtime path is
     *          unsafe or unresolvable, or when the retained package or the deployed bytes no longer match.
     *
     * @since   2.0.0
     */
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

    /**
     * Compute the content digest of a deployment directory, ignoring the retained package.
     *
     * The walk is deliberately strict: a symbolic link, a socket, a device node — anything that is not a
     * regular file — aborts it instead of being skipped, so nothing hostile can be digested away by
     * being unreadable. Entries are keyed by their path relative to `$root`, sorted as strings, and the
     * map is JSON encoded before hashing, which is what makes the digest cover the file list as well as
     * the contents. It is static because `ExtensionMigrationRunner` and the trust migration call it to
     * pin a deployment they are about to record, with no verifier instance in hand.
     *
     * @param   string  $root  Path of the deployment directory to digest.
     *
     * @return  string  Lowercase SHA-256 hex digest of the sorted relative-path-to-file-digest map.
     *
     * @throws  UntrustedPackage  When the root is not a regular directory, when an entry is a symbolic
     *          link or not a regular file, or when a file cannot be hashed.
     * @throws  \JsonException  When a deployed path is not valid UTF-8 and the entry map cannot be encoded.
     *
     * @since   2.0.0
     */
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

    /**
     * Resolve a release's runtime path to a real directory proven to sit inside the extension root.
     *
     * Three checks, because each catches what the others miss: the recorded path is refused outright if
     * it is absolute or contains `..`, the resolved real path must still be under the resolved extension
     * root, and every intermediate segment is tested for being a symbolic link — so a link cannot
     * redirect the walk even when its target happens to land back inside the root.
     *
     * @param   string  $runtimePath  Deployment path relative to the extension root, as recorded on the
     *          release.
     *
     * @return  string  Canonical path of the deployment directory, with symbolic links already ruled out.
     *
     * @throws  UntrustedPackage  When the path is absolute, contains `..`, cannot be resolved, escapes
     *          the extension root, or traverses a symbolic link.
     *
     * @since   2.0.0
     */
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

    /**
     * Read a field the release record must carry as a non-empty string.
     *
     * A release row assembled by an older or partial migration can be missing a column entirely, so the
     * absence is treated as broken trust rather than as an empty value to carry on with.
     *
     * @param   array<string, mixed>  $release  Release record to read from.
     * @param   string                $field    Column name whose value is required, also used in the
     *          refusal message.
     *
     * @return  string  The field value, guaranteed non-empty.
     *
     * @throws  UntrustedPackage  When the field is absent, not a string, or empty.
     *
     * @since   2.0.0
     */
    private function string(array $release, string $field): string
    {
        $value = $release[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new UntrustedPackage(sprintf('The extension release %s is missing.', $field));
        }
        return $value;
    }

    /**
     * Read a release field that must be a SHA-256 digest, in the exact form `hash_file()` produces.
     *
     * Validating the stored digest before comparing against it keeps `hash_equals()` from being handed a
     * truncated or upper-case value that could never match, which would otherwise read as tampering.
     *
     * @param   array<string, mixed>  $release  Release record to read from.
     * @param   string                $field    Column name holding the digest, also used in the refusal
     *          message.
     *
     * @return  string  The 64-character lowercase hexadecimal digest.
     *
     * @throws  UntrustedPackage  When the field is missing, empty, or not 64 lowercase hex characters.
     *
     * @since   2.0.0
     */
    private function digest(array $release, string $field): string
    {
        $value = $this->string($release, $field);
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new UntrustedPackage(sprintf('The extension release %s is invalid.', $field));
        }
        return $value;
    }
}
