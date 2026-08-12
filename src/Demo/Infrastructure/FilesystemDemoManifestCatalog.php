<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Infrastructure;

use JsonException;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use RuntimeException;

/**
 * Loads, bounds, and fingerprints the immutable JSON manifests shipped with Kumwe releases.
 *
 * Manifests are data-only release resources. They never contain PHP callbacks, SQL, credentials, or
 * environment interpolation, and this catalog refuses an unexpected format/profile pair before a handler
 * sees it. Canonical JSON checksums make key ordering irrelevant while preserving list order, so release
 * updates can be compared reliably across database engines and processes.
 *
 * @since  2.0.0
 */
final readonly class FilesystemDemoManifestCatalog
{
    /**
     * Bind the catalog to the repository or installed-package root.
     *
     * @param   string  $root  Absolute Kumwe application root containing `resources/demo`.
     *
     * @throws  RuntimeException  When the root is not absolute.
     *
     * @since   2.0.0
     */
    public function __construct(private string $root)
    {
        if (!str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The demo manifest root must be absolute.');
        }
    }

    /**
     * Grammar every selectable demo profile name must satisfy.
     *
     * The same rule bounds discovered manifest files and operator-provided selections, so a traversal
     * attempt or malformed selector is refused before any filesystem path is derived from it.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PROFILE_NAME_PATTERN = '/^[a-z][a-z0-9-]{0,62}$/D';

    /**
     * Discover the site-content profiles shipped with this release.
     *
     * A site-content profile is one `resources/demo/content/<name>.json` manifest. Discovery keeps the
     * selectable vocabulary open for forks and derived distributions: dropping a new manifest next to the
     * released ones makes it selectable without code changes.
     *
     * @return  list<string>  Sorted profile names available for selection.
     *
     * @since   2.0.0
     */
    public function contentProfiles(): array
    {
        $paths = glob(sprintf('%s/resources/demo/content/*.json', $this->root));
        $profiles = [];
        foreach ($paths === false ? [] : $paths as $path) {
            $name = basename($path, '.json');
            if (preg_match(self::PROFILE_NAME_PATTERN, $name) === 1) {
                $profiles[] = $name;
            }
        }
        sort($profiles);

        return $profiles;
    }

    /**
     * Discover the business demonstration profiles shipped with this release.
     *
     * A business profile is one `resources/demo/business/<name>/profile.json` manifest with its definition
     * and records documents beside it. The explicit `none` selection is not a shipped profile and never
     * appears here.
     *
     * @return  list<string>  Sorted profile names available for selection.
     *
     * @since   2.0.0
     */
    public function businessProfiles(): array
    {
        $paths = glob(sprintf('%s/resources/demo/business/*/profile.json', $this->root));
        $profiles = [];
        foreach ($paths === false ? [] : $paths as $path) {
            $name = basename(dirname($path));
            if (preg_match(self::PROFILE_NAME_PATTERN, $name) === 1) {
                $profiles[] = $name;
            }
        }
        sort($profiles);

        return $profiles;
    }

    /**
     * Load one discovered site-content profile.
     *
     * @param   string  $profile  Discovered profile name, for example `documentation`.
     *
     * @return  array{manifest: array<string, mixed>, checksum: string}  Validated document and canonical digest.
     *
     * @since   2.0.0
     */
    public function content(string $profile): array
    {
        if (
            preg_match(self::PROFILE_NAME_PATTERN, $profile) !== 1
            || !in_array($profile, $this->contentProfiles(), true)
        ) {
            throw new RuntimeException('The selected site-content demo profile is unsupported.');
        }

        return $this->load(
            sprintf('%s/resources/demo/content/%s.json', $this->root, $profile),
            'kumwe.demo-content/v1',
            $profile,
        );
    }

    /**
     * Load the released Vast Development Method business dataset.
     *
     * @return  array{manifest: array<string, mixed>, checksum: string}  Validated document and canonical digest.
     *
     * @since   2.0.0
     */
    public function vdmBusiness(): array
    {
        return $this->business('vdm');
    }

    /**
     * Load one discovered business demonstration dataset.
     *
     * @param   string  $profile  Discovered profile name, for example `vdm`.
     *
     * @return  array{manifest: array<string, mixed>, checksum: string}  Validated document and canonical digest.
     *
     * @throws  RuntimeException  When the profile is undiscovered or any manifest violates its contract.
     *
     * @since   2.0.0
     */
    public function business(string $profile): array
    {
        if (
            preg_match(self::PROFILE_NAME_PATTERN, $profile) !== 1
            || !in_array($profile, $this->businessProfiles(), true)
        ) {
            throw new RuntimeException('The selected business demo profile is unsupported.');
        }
        $base = sprintf('%s/resources/demo/business/%s', $this->root, $profile);
        $loaded = $this->loadDocument($base . '/profile.json');
        $manifest = $loaded['manifest'];
        if (
            ($manifest['format'] ?? null) !== 'kumwe.demo-business-profile/v1'
            || ($manifest['profile'] ?? null) !== $profile
        ) {
            throw new RuntimeException(
                sprintf('The %s business demo manifest has an invalid contract.', $profile),
            );
        }
        $order = $manifest['installation_order'] ?? null;
        if (!is_array($order) || !array_is_list($order) || $order === [] || count($order) > 64) {
            throw new RuntimeException(
                sprintf('The %s business demo definition order is invalid.', $profile),
            );
        }
        $definitions = [];
        foreach ($order as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new RuntimeException(
                    sprintf('A %s business demo definition entry is invalid.', $profile),
                );
            }
            $fixtureKey = $entry['fixture_key'] ?? null;
            $file = $entry['file'] ?? null;
            if (
                !is_string($fixtureKey)
                || preg_match('/^definition\.[a-z][a-z0-9_]{0,63}$/D', $fixtureKey) !== 1
                || !is_string($file)
                || preg_match('/^definitions\/[a-z][a-z0-9-]{0,62}\.json$/D', $file) !== 1
                || isset($definitions[$fixtureKey])
            ) {
                throw new RuntimeException(
                    sprintf('A %s business demo definition reference is invalid.', $profile),
                );
            }
            $definition = $this->decodeDocument($base . '/' . $file);
            if (($definition['id'] ?? null) !== ($entry['id'] ?? null)) {
                throw new RuntimeException(sprintf(
                    'Business demo definition %s has an unexpected identity.',
                    $fixtureKey,
                ));
            }
            $definitions[$fixtureKey] = $definition;
        }
        $recordsFile = $manifest['records_file'] ?? null;
        if (!is_string($recordsFile) || $recordsFile !== 'records.json') {
            throw new RuntimeException(
                sprintf('The %s business demo records reference is invalid.', $profile),
            );
        }
        $aggregate = [
            ...$manifest,
            'definition_documents' => $definitions,
            'records_document' => $this->decodeDocument($base . '/' . $recordsFile),
        ];

        return ['manifest' => $aggregate, 'checksum' => CanonicalDefinitionJson::checksum($aggregate)];
    }

    /**
     * Read and validate one bounded manifest file.
     *
     * @param   string  $path             Absolute JSON file path.
     * @param   string  $expectedFormat   Exact format discriminator.
     * @param   string  $expectedProfile  Exact selected profile name.
     *
     * @return  array{manifest: array<string, mixed>, checksum: string}  Document and canonical digest.
     *
     * @throws  RuntimeException  When bytes are unavailable, oversized, malformed, or contradict selection.
     *
     * @since   2.0.0
     */
    private function load(string $path, string $expectedFormat, string $expectedProfile): array
    {
        $loaded = $this->loadDocument($path);
        $manifest = $loaded['manifest'];
        if (($manifest['format'] ?? null) !== $expectedFormat || ($manifest['profile'] ?? null) !== $expectedProfile) {
            throw new RuntimeException(sprintf('Demo manifest %s has an invalid contract.', basename($path)));
        }

        return $loaded;
    }

    /**
     * Read one bounded manifest object and compute its canonical checksum.
     *
     * @param   string  $path  Absolute JSON file path.
     *
     * @return  array{manifest: array<string, mixed>, checksum: string}  Document and canonical digest.
     *
     * @throws  RuntimeException  When bytes are unavailable, oversized, malformed, or unversioned.
     *
     * @since   2.0.0
     */
    private function loadDocument(string $path): array
    {
        $manifest = $this->decodeDocument($path);
        if (
            !is_int($manifest['version'] ?? null)
            || $manifest['version'] < 1
        ) {
            throw new RuntimeException(sprintf('Demo manifest %s has an invalid contract.', basename($path)));
        }

        /** @var array<string, mixed> $manifest */
        return [
            'manifest' => $manifest,
            'checksum' => CanonicalDefinitionJson::checksum($manifest),
        ];
    }

    /**
     * Decode one bounded JSON object without imposing a particular document-version field.
     *
     * @param   string  $path  Absolute JSON path below the fixed demo-resource root.
     *
     * @return  array<string, mixed>  Decoded object.
     *
     * @throws  RuntimeException  When bytes are unavailable, oversized, malformed, or not an object.
     *
     * @since   2.0.0
     */
    private function decodeDocument(string $path): array
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 2_000_000) {
            throw new RuntimeException(sprintf('Demo manifest %s is unavailable or exceeds 2 MB.', basename($path)));
        }
        try {
            $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Demo manifest %s is invalid JSON.', basename($path)), 0, $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new RuntimeException(sprintf('Demo manifest %s must contain one JSON object.', basename($path)));
        }

        /** @var array<string, mixed> $document */
        return $document;
    }
}
