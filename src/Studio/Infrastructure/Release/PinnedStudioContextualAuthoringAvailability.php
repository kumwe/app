<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Release;

use JsonException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;

/**
 * Fail-closed contextual-authoring gate over the exact vendored and packaged deployment evidence.
 *
 * A release's profile claims are deliberately irrelevant here. The gate requires the canonical
 * contextual schemas and operation registry, every authoring capability and route, the compiled
 * contextual browser entry, and an explicit App PHP-adapter readiness decision. The withdrawn RC
 * therefore cannot enable contextual authoring merely because it claimed an older `authoring-web` profile.
 *
 * @since  2.0.0
 */
final readonly class PinnedStudioContextualAuthoringAvailability implements StudioContextualAuthoringAvailability
{
    /**
     * Public Studio packages that must belong to one exact release family.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array STUDIO_PACKAGES = [
        '@kumwe/studio',
        '@kumwe/studio-core',
        '@kumwe/studio-media',
        '@kumwe/studio-preview',
        '@kumwe/studio-protocol',
        '@kumwe/studio-renderer-web',
        '@kumwe/studio-rich-text',
        '@kumwe/studio-testkit',
    ];

    /**
     * Canonical schema identities required by contextual authoring.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array REQUIRED_SCHEMA_IDS = [
        'authoring-target.schema.json' => 'https://schemas.kumwe.org/studio/v1/authoring-target.schema.json',
        'authoring-session.schema.json' => 'https://schemas.kumwe.org/studio/v1/authoring-session.schema.json',
        'authoring-save.schema.json' => 'https://schemas.kumwe.org/studio/v1/authoring-save.schema.json',
        'reusable-content-type.schema.json' => 'https://schemas.kumwe.org/studio/v1/reusable-content-type.schema.json',
        'host-operations.schema.json' => 'https://schemas.kumwe.org/studio/v1/host-operations.schema.json',
    ];

    /**
     * Minimum canonical definitions each required schema must publish.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const array REQUIRED_SCHEMA_DEFINITIONS = [
        'authoring-target.schema.json' => [
            'presentationState',
            'saveOutcome',
            'startKind',
            'eligibility',
            'declaration',
            'resolveRequest',
            'resolution',
        ],
        'authoring-session.schema.json' => [
            'startSource',
            'startRequest',
            'artifactCoordinates',
            'artifactState',
            'capabilities',
            'presentation',
            'snapshot',
        ],
        'authoring-save.schema.json' => [
            'saveItemDraft',
            'saveNewTypeVersionDraft',
            'saveAsNewTypeDraft',
            'saveIntent',
            'savePlan',
            'saveItemRequest',
            'saveNewTypeVersionRequest',
            'saveAsNewTypeRequest',
            'saveResult',
        ],
        'reusable-content-type.schema.json' => [
            'reference',
            'authoringPolicy',
            'definition',
            'summary',
            'listQuery',
            'listPage',
        ],
        'host-operations.schema.json' => [
            'operationCapability',
            'operationRoute',
            'portCapability',
            'portName',
        ],
    ];

    /**
     * Exact root documents each contextual schema admits.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const array REQUIRED_ROOT_REFERENCES = [
        'authoring-target.schema.json' => ['#/$defs/declaration'],
        'authoring-session.schema.json' => ['#/$defs/snapshot'],
        'authoring-save.schema.json' => [
            '#/$defs/saveIntent',
            '#/$defs/savePlan',
            '#/$defs/saveItemRequest',
            '#/$defs/saveNewTypeVersionRequest',
            '#/$defs/saveAsNewTypeRequest',
            '#/$defs/saveResult',
        ],
        'reusable-content-type.schema.json' => ['#/$defs/definition'],
        'host-operations.schema.json' => [],
    ];

    /**
     * Protocol epoch published by Studio.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string SCHEMA_EPOCH = 'https://schemas.kumwe.org/studio/v1/';

    /**
     * JSON Schema dialect published by Studio.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string JSON_SCHEMA_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    /**
     * Exact capability-to-route pairs required by Studio's contextual Authoring port.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array REQUIRED_OPERATIONS = [
        'studio.operation/authoring.resolve-target' => 'authoring/resolve-target',
        'studio.operation/authoring.list-types' => 'authoring/list-types',
        'studio.operation/authoring.start' => 'authoring/start',
        'studio.operation/authoring.plan-save' => 'authoring/plan-save',
        'studio.operation/authoring.save-item' => 'authoring/save-item',
        'studio.operation/authoring.save-new-type-version' => 'authoring/save-new-type-version',
        'studio.operation/authoring.save-as-new-type' => 'authoring/save-as-new-type',
    ];

    /**
     * Vite source key the packaged contextual browser entry must own.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CONTEXTUAL_BROWSER_ENTRY = 'assets/administrator/components/studio-contextual.ts';

    /**
     * Point the gate at one exact App deployment.
     *
     * @param  string                                   $root           Absolute App root holding the vendored
     *         corpus and built assets.
     * @param  ?StudioContextualAuthoringQualification  $qualification  App-owned exact adapter qualification.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $root,
        private ?StudioContextualAuthoringQualification $qualification,
    ) {
    }

    /**
     * Require exact protocol, compiled browser, and PHP host evidence in that order.
     *
     * @return  StudioContextualAuthoringReadiness  First failed boundary, or qualified readiness.
     *
     * @since   2.0.0
     */
    public function current(): StudioContextualAuthoringReadiness
    {
        if (!$this->protocolAvailable()) {
            return StudioContextualAuthoringReadiness::fallback(
                StudioContextualAuthoringFallbackReason::ProtocolUnavailable,
            );
        }
        if (!$this->browserRuntimeAvailable()) {
            return StudioContextualAuthoringReadiness::fallback(
                StudioContextualAuthoringFallbackReason::BrowserRuntimeUnavailable,
            );
        }
        if (!$this->hostAdapterQualified()) {
            return StudioContextualAuthoringReadiness::fallback(
                StudioContextualAuthoringFallbackReason::HostAdapterUnavailable,
            );
        }

        return StudioContextualAuthoringReadiness::available();
    }

    /**
     * Require App-owned qualification to match the exact deployed evidence bytes.
     *
     * @return  bool  True only for the reviewed release, pin, schema, and browser coordinates.
     *
     * @since   2.0.0
     */
    private function hostAdapterQualified(): bool
    {
        if ($this->qualification === null) {
            return false;
        }

        $contractRoot = $this->root . '/resources/studio-contract';
        $release = $this->decode($contractRoot . '/studio-release.json');
        if ($release === null || ($release['release'] ?? null) !== $this->qualification->release) {
            return false;
        }

        foreach (
            [
                $contractRoot . '/studio-release.json' => $this->qualification->releaseRecordSha256,
                $contractRoot . '/PIN.json' => $this->qualification->pinRecordSha256,
                $contractRoot . '/protocol/schemas/manifest.json' => $this->qualification->schemaManifestSha256,
                $this->root . '/public/assets/build/.vite/manifest.json' =>
                    $this->qualification->browserManifestSha256,
            ] as $path => $expected
        ) {
            $actual = is_file($path) ? hash_file('sha256', $path) : false;
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                return false;
            }
        }

        $browserEntry = $this->contextualBrowserPath();
        $browserDigest = $browserEntry === null ? false : hash_file('sha256', $browserEntry);
        if (
            !is_string($browserDigest)
            || !hash_equals($this->qualification->browserEntrySha256, $browserDigest)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Verify canonical contextual schemas and the closed operation/route vocabularies.
     *
     * @return  bool  True only when the exact coordinated corpus contains every required member.
     *
     * @since   2.0.0
     */
    private function protocolAvailable(): bool
    {
        $release = $this->pinnedRelease();
        if ($release === null) {
            return false;
        }

        $schemas = $this->root . '/resources/studio-contract/protocol/schemas';
        $manifest = $this->decode($schemas . '/manifest.json');
        $entries = is_array($manifest) ? ($manifest['schemas'] ?? null) : null;
        if (
            !is_array($manifest)
            || ($manifest['contractVersion'] ?? null) !== ($release['contractVersion'] ?? null)
            || ($manifest['epoch'] ?? null) !== self::SCHEMA_EPOCH
            || ($manifest['kind'] ?? null) !== 'schema-manifest'
            || !is_array($entries)
        ) {
            return false;
        }

        /** @var array<string, array{id: string, digest: string}> $published */
        $published = [];
        foreach ($entries as $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            $digest = is_array($entry) ? ($entry['digest'] ?? null) : null;
            if (
                !is_string($file)
                || basename($file) !== $file
                || preg_match('/^[a-z0-9-]+\.schema\.json$/D', $file) !== 1
                || !is_string($id)
                || $id !== self::SCHEMA_EPOCH . $file
                || !is_string($digest)
                || isset($published[$file])
            ) {
                return false;
            }
            $published[$file] = ['id' => $id, 'digest' => $digest];
        }

        $listed = ['manifest.json' => true];
        foreach ($published as $file => $entry) {
            $path = $schemas . '/' . $file;
            $schema = $this->decode($path);
            $digest = $this->sriDigest($path);
            if (
                $schema === null
                || ($schema['$schema'] ?? null) !== self::JSON_SCHEMA_DIALECT
                || ($schema['$id'] ?? null) !== $entry['id']
                || $digest === null
                || !hash_equals($entry['digest'], $digest)
            ) {
                return false;
            }
            $listed[$file] = true;
        }
        $directoryEntries = is_dir($schemas) ? scandir($schemas) : false;
        if (!is_array($directoryEntries)) {
            return false;
        }
        foreach ($directoryEntries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!isset($listed[$entry]) || !is_file($schemas . '/' . $entry)) {
                return false;
            }
        }

        foreach (self::REQUIRED_SCHEMA_IDS as $file => $expectedId) {
            $path = $schemas . '/' . $file;
            $schema = $this->decode($path);
            $entry = $published[$file] ?? null;
            $definitions = is_array($schema) ? ($schema['$defs'] ?? null) : null;
            $digest = $this->sriDigest($path);
            if (
                !is_array($schema)
                || ($schema['$schema'] ?? null) !== self::JSON_SCHEMA_DIALECT
                || ($schema['$id'] ?? null) !== $expectedId
                || !$this->canonicalSchemaShape($file, $schema, $definitions)
                || !is_array($entry)
                || $entry['id'] !== $expectedId
                || $digest === null
                || !hash_equals($entry['digest'], $digest)
            ) {
                return false;
            }
        }

        $registry = $this->decode($schemas . '/host-operations.schema.json');
        $definitions = is_array($registry) ? ($registry['$defs'] ?? null) : null;
        $capabilityDefinition = is_array($definitions) ? ($definitions['operationCapability'] ?? null) : null;
        $routeDefinition = is_array($definitions) ? ($definitions['operationRoute'] ?? null) : null;
        $capabilities = is_array($capabilityDefinition) ? ($capabilityDefinition['enum'] ?? null) : null;
        $routes = is_array($routeDefinition) ? ($routeDefinition['enum'] ?? null) : null;
        if (!is_array($capabilities) || !is_array($routes)) {
            return false;
        }
        $portCapabilityDefinition = $definitions['portCapability'] ?? null;
        $portNameDefinition = $definitions['portName'] ?? null;
        $portCapabilities = is_array($portCapabilityDefinition) ? ($portCapabilityDefinition['enum'] ?? null) : null;
        $portNames = is_array($portNameDefinition) ? ($portNameDefinition['enum'] ?? null) : null;
        if (
            !is_array($portCapabilities)
            || !in_array('studio.port/authoring', $portCapabilities, true)
            || !is_array($portNames)
            || !in_array('authoring', $portNames, true)
        ) {
            return false;
        }
        foreach (self::REQUIRED_OPERATIONS as $capability => $route) {
            if (!in_array($capability, $capabilities, true) || !in_array($route, $routes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Require the definitions and root document references contextual Studio actually coordinates.
     *
     * @param   string                $file         Canonical schema filename.
     * @param   array<string, mixed>  $schema       Decoded schema document.
     * @param   mixed                 $definitions  Candidate `$defs` member.
     *
     * @return  bool  True only when the required semantic shape is present.
     *
     * @since   2.0.0
     */
    private function canonicalSchemaShape(string $file, array $schema, mixed $definitions): bool
    {
        if (!is_array($definitions)) {
            return false;
        }
        foreach (self::REQUIRED_SCHEMA_DEFINITIONS[$file] ?? [] as $definition) {
            if (!is_array($definitions[$definition] ?? null)) {
                return false;
            }
        }

        $references = [];
        $rootReference = $schema['$ref'] ?? null;
        if (is_string($rootReference)) {
            $references[] = $rootReference;
        }
        $rootChoices = $schema['oneOf'] ?? null;
        if (is_array($rootChoices)) {
            foreach ($rootChoices as $choice) {
                $reference = is_array($choice) ? ($choice['$ref'] ?? null) : null;
                if (!is_string($reference)) {
                    return false;
                }
                $references[] = $reference;
            }
        }

        return $references === (self::REQUIRED_ROOT_REFERENCES[$file] ?? null);
    }

    /**
     * Bind runtime protocol evidence to the exact coordinated release and package pin.
     *
     * The corpus verifier closes the full extracted trees in CI. This runtime check repeats the
     * release-record, package-byte, and vendored protocol identity boundaries needed before a
     * deployment may use those already-qualified bytes.
     *
     * @return  array<string, mixed>|null  Exact release record, or null for incomplete/mismatched evidence.
     *
     * @since   2.0.0
     */
    private function pinnedRelease(): ?array
    {
        $contractRoot = $this->root . '/resources/studio-contract';
        $releasePath = $contractRoot . '/studio-release.json';
        $releaseBytes = is_file($releasePath) ? file_get_contents($releasePath) : false;
        $release = $this->decode($releasePath);
        $pin = $this->decode($contractRoot . '/PIN.json');
        if (!is_string($releaseBytes) || $release === null || $pin === null) {
            return null;
        }

        $releaseName = $release['release'] ?? null;
        $contractVersion = $release['contractVersion'] ?? null;
        $protocolVersion = $release['protocolVersion'] ?? null;
        $corpusDigest = $release['corpusManifestDigest'] ?? null;
        $packages = $release['packages'] ?? null;
        if (
            ($release['kind'] ?? null) !== 'studio-release'
            || !is_string($releaseName)
            || preg_match(
                '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D',
                $releaseName,
            ) !== 1
            || !is_string($contractVersion)
            || !is_string($protocolVersion)
            || !is_string($corpusDigest)
            || preg_match('/^sha256-[A-Za-z0-9+\/]{43}=$/D', $corpusDigest) !== 1
            || !is_array($packages)
            || count($packages) !== count(self::STUDIO_PACKAGES)
        ) {
            return null;
        }

        $releasePin = $pin['release_record'] ?? null;
        $pinnedPackages = $pin['pinned'] ?? null;
        $pinnedReleaseDigest = is_array($releasePin) ? ($releasePin['sha256'] ?? null) : null;
        $releaseDigest = hash('sha256', $releaseBytes);
        if (
            !is_array($releasePin)
            || ($releasePin['file'] ?? null) !== 'studio-release.json'
            || ($releasePin['release'] ?? null) !== $releaseName
            || !is_string($pinnedReleaseDigest)
            || !hash_equals($pinnedReleaseDigest, $releaseDigest)
            || !is_array($pinnedPackages)
            || count($pinnedPackages) !== count(self::STUDIO_PACKAGES)
        ) {
            return null;
        }

        foreach (self::STUDIO_PACKAGES as $package) {
            $packagePin = $pinnedPackages[$package] ?? null;
            $file = is_array($packagePin) ? ($packagePin['file'] ?? null) : null;
            $digest = is_array($packagePin) ? ($packagePin['npm_tarball_sha256'] ?? null) : null;
            if (
                ($packages[$package] ?? null) !== $releaseName
                || !is_array($packagePin)
                || ($packagePin['version'] ?? null) !== $releaseName
                || !is_string($file)
                || basename($file) !== $file
                || !is_string($digest)
                || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1
            ) {
                return null;
            }
            $tarball = $contractRoot . '/packages/' . $file;
            $actual = is_file($tarball) ? hash_file('sha256', $tarball) : false;
            if (!is_string($actual) || !hash_equals($digest, $actual)) {
                return null;
            }
        }

        $protocolRelease = $contractRoot . '/protocol/studio-release.json';
        $protocolReleaseBytes = is_file($protocolRelease) ? file_get_contents($protocolRelease) : false;
        $protocolPackage = $this->decode($contractRoot . '/protocol/package.json');
        if (
            !is_string($protocolReleaseBytes)
            || !hash_equals(hash('sha256', $releaseBytes), hash('sha256', $protocolReleaseBytes))
            || $protocolPackage === null
            || ($protocolPackage['name'] ?? null) !== '@kumwe/studio-protocol'
            || ($protocolPackage['version'] ?? null) !== $releaseName
        ) {
            return null;
        }

        return $release;
    }

    /**
     * Verify the contextual Vite entry resolves to a packaged JavaScript file.
     *
     * @return  bool  True only for a safe manifest-relative JavaScript artifact that exists.
     *
     * @since   2.0.0
     */
    private function browserRuntimeAvailable(): bool
    {
        return $this->contextualBrowserPath() !== null;
    }

    /**
     * Resolve the packaged contextual entry without accepting an absolute or traversing path.
     *
     * @return  ?string  Existing absolute entry path, or null when the browser evidence is unsafe.
     *
     * @since   2.0.0
     */
    private function contextualBrowserPath(): ?string
    {
        $manifest = $this->decode($this->root . '/public/assets/build/.vite/manifest.json');
        $entry = is_array($manifest) ? ($manifest[self::CONTEXTUAL_BROWSER_ENTRY] ?? null) : null;
        $file = is_array($entry) ? ($entry['file'] ?? null) : null;
        if (!is_string($file) || preg_match('#^js/[A-Za-z0-9._-]+\.js$#D', $file) !== 1) {
            return null;
        }

        $path = $this->root . '/public/assets/build/' . $file;

        return is_file($path) ? $path : null;
    }

    /**
     * Compute the SRI digest format published by Studio's schema manifest.
     *
     * @param   string  $path  Absolute path to one schema document.
     *
     * @return  ?string  `sha256-<base64>` digest, or null when the file cannot be read.
     *
     * @since   2.0.0
     */
    private function sriDigest(string $path): ?string
    {
        $digest = is_file($path) ? hash_file('sha256', $path, true) : false;

        return is_string($digest) ? 'sha256-' . base64_encode($digest) : null;
    }

    /**
     * Decode one deployment-owned JSON document without letting malformed evidence enable a feature.
     *
     * @param   string  $path  Absolute JSON path.
     *
     * @return  array<string, mixed>|null  Object-shaped JSON, or null when absent or malformed.
     *
     * @since   2.0.0
     */
    private function decode(string $path): ?array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($json)) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }
        $document = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                return null;
            }
            $document[$key] = $value;
        }

        return $document;
    }
}
