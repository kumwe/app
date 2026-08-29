<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Release;

use JsonException;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringAvailability;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringFallbackReason;
use Kumwe\App\Studio\Application\Authoring\StudioContextualAuthoringReadiness;
use Kumwe\Producer\Schema\StudioContractRelease;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use Kumwe\Producer\Wire\OperationRegistry;
use Throwable;

/**
 * Fail-closed contextual-authoring gate over one exact App deployment.
 *
 * Producer is the sole authority for the pinned Studio schemas and host-operation registry. App
 * proves only the deployment evidence it owns: the coordinated release record, npm tarballs,
 * compiled browser entry, and an explicit host-implementation qualification. A release profile
 * claim cannot enable contextual authoring when Producer does not publish every required document
 * kind and operation.
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
     * Canonical document kinds required by contextual authoring.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array REQUIRED_DOCUMENT_KINDS = [
        'authoring-target',
        'authoring-session',
        'authoring-save',
        'reusable-content-type',
    ];

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
     * @param  string                                   $root           Absolute App root holding its release
     *         pin, npm tarballs, and built assets.
     * @param  ?StudioContextualAuthoringQualification  $qualification  App-owned exact host qualification.
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
        if (!$this->hostImplementationQualified()) {
            return StudioContextualAuthoringReadiness::fallback(
                StudioContextualAuthoringFallbackReason::HostAdapterUnavailable,
            );
        }

        return StudioContextualAuthoringReadiness::available();
    }

    /**
     * Require App-owned qualification to match the exact deployed evidence bytes.
     *
     * @return  bool  True only for the reviewed release, pin, browser, and host coordinates.
     *
     * @since   2.0.0
     */
    private function hostImplementationQualified(): bool
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

        return is_string($browserDigest)
            && hash_equals($this->qualification->browserEntrySha256, $browserDigest);
    }

    /**
     * Verify Producer's exact schema and operation authorities against App's deployment pin.
     *
     * @return  bool  True only when one coordinated release publishes every contextual member.
     *
     * @since   2.0.0
     */
    private function protocolAvailable(): bool
    {
        try {
            $release = StudioContractResources::releaseRecord();
            StudioDocumentSchemaRegistry::fromVendoredCorpus();
        } catch (Throwable) {
            return false;
        }
        if (!$this->appPinMatches($release)) {
            return false;
        }

        foreach (self::REQUIRED_DOCUMENT_KINDS as $kind) {
            if (!in_array($kind, StudioDocumentSchemaRegistry::DOCUMENT_KINDS, true)) {
                return false;
            }
        }
        foreach (self::REQUIRED_OPERATIONS as $capability => $route) {
            if (
                !OperationRegistry::isCapability($capability)
                || !OperationRegistry::isRoute($route)
                || OperationRegistry::byCapability($capability)->route !== $route
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bind App-owned release and tarball bytes to Producer's immutable release coordinates.
     *
     * @param   StudioContractRelease  $installed  Producer's fully verified coordinated release.
     *
     * @return  bool  True only when App pins exactly the same release and eight package bytes.
     *
     * @since   2.0.0
     */
    private function appPinMatches(StudioContractRelease $installed): bool
    {
        $contractRoot = $this->root . '/resources/studio-contract';
        $releasePath = $contractRoot . '/studio-release.json';
        $releaseBytes = is_file($releasePath) ? file_get_contents($releasePath) : false;
        $release = $this->decode($releasePath);
        $pin = $this->decode($contractRoot . '/PIN.json');
        if (!is_string($releaseBytes) || $release === null || $pin === null) {
            return false;
        }

        $packages = $release['packages'] ?? null;
        $profiles = $release['claimedProfiles'] ?? null;
        if (!is_array($packages) || array_is_list($packages) || !is_array($profiles) || !array_is_list($profiles)) {
            return false;
        }
        $releasePackages = [];
        foreach ($packages as $package => $version) {
            if (!is_string($package) || !is_string($version)) {
                return false;
            }
            $releasePackages[$package] = $version;
        }
        ksort($releasePackages);
        $installedPackages = $installed->packages();
        ksort($installedPackages);
        if (
            ($release['kind'] ?? null) !== 'studio-release'
            || ($release['contractVersion'] ?? null) !== $installed->contractVersion()
            || ($release['release'] ?? null) !== $installed->release()
            || ($release['protocolVersion'] ?? null) !== $installed->protocolVersion()
            || ($release['corpusManifestDigest'] ?? null) !== $installed->corpusManifestDigest()
            || $profiles !== $installed->claimedProfiles()
            || $releasePackages !== $installedPackages
            || !hash_equals($installed->recordSha256(), hash('sha256', $releaseBytes))
        ) {
            return false;
        }

        $releasePin = $pin['release_record'] ?? null;
        $pinned = $pin['pinned'] ?? null;
        if (
            !is_array($releasePin)
            || ($releasePin['file'] ?? null) !== 'studio-release.json'
            || ($releasePin['release'] ?? null) !== $installed->release()
            || ($releasePin['sha256'] ?? null) !== $installed->recordSha256()
            || !is_array($pinned)
            || array_is_list($pinned)
        ) {
            return false;
        }

        $expectedPackages = self::STUDIO_PACKAGES;
        sort($expectedPackages);
        $pinnedNames = array_keys($pinned);
        sort($pinnedNames);
        $releaseNames = array_keys($installedPackages);
        sort($releaseNames);
        if ($pinnedNames !== $expectedPackages || $releaseNames !== $expectedPackages) {
            return false;
        }

        $listedTarballs = [];
        foreach (self::STUDIO_PACKAGES as $package) {
            $packagePin = $pinned[$package] ?? null;
            $file = is_array($packagePin) ? ($packagePin['file'] ?? null) : null;
            $digest = is_array($packagePin) ? ($packagePin['npm_tarball_sha256'] ?? null) : null;
            if (
                !is_array($packagePin)
                || ($packagePin['version'] ?? null) !== ($installedPackages[$package] ?? null)
                || !is_string($file)
                || basename($file) !== $file
                || preg_match('/^[A-Za-z0-9._-]+\.tgz$/D', $file) !== 1
                || isset($listedTarballs[$file])
                || !is_string($digest)
                || preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1
            ) {
                return false;
            }
            $listedTarballs[$file] = true;
            $actual = hash_file('sha256', $contractRoot . '/packages/' . $file);
            if (!is_string($actual) || !hash_equals($digest, $actual)) {
                return false;
            }
        }

        $directory = is_dir($contractRoot . '/packages') ? scandir($contractRoot . '/packages') : false;
        if (!is_array($directory)) {
            return false;
        }
        foreach ($directory as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!isset($listedTarballs[$entry]) || !is_file($contractRoot . '/packages/' . $entry)) {
                return false;
            }
        }

        return true;
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
