<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class ExtensionRuntimeMapCompiler implements TrustRuntimeInvalidator
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private string $mapFile,
        private string $extensionRoot,
        private string $publicAssetRoot,
        private ClockInterface $clock,
        private RuntimeIdentity $identity,
        private RuntimePublicationKeyRing $keys,
        private RuntimeArtifactDigester $artifacts,
        private int $retentionSeconds = 3_600,
        private int $replicaLeaseSeconds = 300,
    ) {
        if (min($retentionSeconds, $replicaLeaseSeconds) < 1) {
            throw new InvalidArgumentException('Runtime publication trust and retention settings are invalid.');
        }
    }

    /**
     * Persist an immutable runtime publication inside the caller's registry transaction.
     */
    public function stage(string $action): int
    {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('Runtime publication must be staged inside the registry transaction.');
        }

        $this->lockGeneration();

        return $this->stageDocument($action, $this->runtimeState());
    }

    /**
     * Publish break-glass removal of a damaged administrator theme while retaining signed-publication trust.
     */
    public function stageAdministratorRecovery(string $action, string $identifier): int
    {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('Runtime recovery must be staged inside the recovery transaction.');
        }

        $current = $this->lockGeneration();
        $extensions = $this->runtimeState();
        $publication = $this->publication($current)
            ?? throw new RuntimeException('The signed runtime publication required for recovery is missing.');
        $prior = $this->verifiedDocument($publication, false);
        $this->assertAdministratorRecoveryTransition($prior, $extensions, $identifier);
        $this->assertArtifacts($extensions);

        return $this->stageDocument($action, $extensions, true, true);
    }

    /**
     * Reconcile an unmaterialized migration or interrupted publisher and materialize it locally.
     */
    public function reconcileAndMaterialize(
        bool $acknowledgeLoaded = true,
        bool $publishReadiness = true,
    ): RuntimeMaterializationState {
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            try {
                $this->database->transactional(function (): void {
                    $this->lockGeneration();
                    $extensions = $this->runtimeState();
                    $stateSha256 = $this->stateChecksum($extensions);
                    $current = $this->currentGeneration();
                    $publication = $this->publication($current);
                    $trusted = false;
                    if ($publication !== null) {
                        // A present but unverifiable publication is security drift, not a reason to sign over it.
                        $document = $this->verifiedDocument($publication);
                        $trusted = ($document['generation'] ?? null) === $current
                            && ($document['state_sha256'] ?? null) === $stateSha256
                            && ($document['signing_key_id'] ?? null) === $this->keys->activeKeyId;
                    }

                    if (!$trusted) {
                        $this->stageDocument('runtime.reconcile', $extensions, false);
                    }
                });
                break;
            } catch (RuntimeException $exception) {
                if (
                    $attempt === 3
                    || $exception->getMessage()
                        !== 'The runtime registry changed concurrently; retry the complete mutation.'
                ) {
                    throw $exception;
                }
            }
        }

        return $this->materializeLatest($acknowledgeLoaded, $publishReadiness);
    }

    public function rebuild(): int
    {
        return $this->reconcileAndMaterialize(false)->generation;
    }

    public function advance(string $reason, ?string $extensionIdentifier = null): int
    {
        $action = $extensionIdentifier === null ? $reason : $reason . ':' . $extensionIdentifier;
        if ($this->database->isTransactionActive()) {
            return $this->stage($action);
        }

        return $this->database->transactional(fn (): int => $this->stage($action));
    }

    public function materialize(): int
    {
        if ($this->database->isTransactionActive()) {
            return $this->currentGeneration();
        }

        return $this->reconcileAndMaterialize()->generation;
    }

    public function discardLocal(): void
    {
        foreach ([$this->mapFile, $this->mapFile . '.verified', $this->mapFile . '.ready'] as $path) {
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new RuntimeException('The replica-local runtime state is unsafe.');
            }
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('The replica-local runtime state could not be discarded.');
            }
        }
    }

    public function materializeLatest(
        bool $acknowledgeLoaded = false,
        bool $publishReadiness = true,
    ): RuntimeMaterializationState {
        $generation = $this->currentGeneration();
        $publication = $this->publication($generation)
            ?? throw new RuntimeException('The authoritative runtime publication is missing.');
        $document = $this->verifiedDocument($publication);
        $payload = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $marker = $this->markerPayload($document, $payload);
        $readiness = $this->readinessPayload($document);
        $directory = dirname($this->mapFile);

        if (is_link($directory)) {
            throw new RuntimeException('The extension runtime cache directory is unsafe.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The extension runtime cache directory could not be created.');
        }
        $lockPath = $this->mapFile . '.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
            throw new RuntimeException('The extension runtime map lock target is unsafe.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('The extension runtime map lock could not be acquired.');
        }
        try {
            $this->assertOpenLockFile($lock, $lockPath);
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('The extension runtime map lock could not be acquired.');
            }
            $this->assertOpenLockFile($lock, $lockPath);
            if (!fchmod($lock, 0600)) {
                throw new RuntimeException('The extension runtime map lock permissions could not be secured.');
            }
            if (file_exists($this->mapFile) && (!is_file($this->mapFile) || is_link($this->mapFile))) {
                throw new RuntimeException('The extension runtime map target is not a regular file.');
            }
            $local = $this->inspectLocal();
            if ($local->trusted && $local->generation > $generation) {
                throw new RuntimeException('Refusing to replace a newer local runtime generation.');
            }
            if (
                $local->trusted
                && $local->generation === $generation
                && !hash_equals(
                    $local->publicationChecksum,
                    $this->requiredString($publication, 'publication_sha256'),
                )
            ) {
                throw new RuntimeException('The local runtime generation conflicts with database authority.');
            }
            if (!$local->trusted || $local->generation < $generation) {
                $temporary = $this->mapFile . '.tmp.' . bin2hex(random_bytes(8));
                try {
                    if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                        throw new RuntimeException('The extension runtime map could not be written completely.');
                    }
                    chmod($temporary, 0600);
                    if (!rename($temporary, $this->mapFile)) {
                        throw new RuntimeException('The extension runtime map could not be activated atomically.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
            }
            $this->writeAtomicFile($this->mapFile . '.verified', $marker);
            if ($publishReadiness) {
                $this->writeAtomicFile($this->mapFile . '.ready', $readiness, false);
            }
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        $state = new RuntimeMaterializationState(
            $this->identity->leaseId,
            $generation,
            $this->requiredString($publication, 'publication_sha256'),
            $this->requiredString($publication, 'trust_hmac'),
            true,
            new VerifiedRuntimePublication($document),
        );
        if ($acknowledgeLoaded) {
            $this->acknowledge($state);
        }
        $this->reconcileOrphanedRuntimes();
        $this->collectRetiredRuntimes();
        $this->purgeRuntimeHistory();

        return $state;
    }

    public function publishLocalReadiness(RuntimeMaterializationState $state): void
    {
        $local = $this->inspectLocal();
        if (
            !$state->trusted
            || $state->publication === null
            || !$local->trusted
            || $local->generation !== $state->generation
            || !hash_equals($local->publicationChecksum, $state->publicationChecksum)
        ) {
            throw new RuntimeException('Readiness may be published only for the loaded local runtime generation.');
        }
        $this->writeAtomicFile(
            $this->mapFile . '.ready',
            $this->readinessPayload($state->publication->document),
            false,
        );
    }

    public function inspectLocal(): RuntimeMaterializationState
    {
        if (!is_file($this->mapFile) || !is_file($this->mapFile . '.verified')) {
            return RuntimeMaterializationState::unavailable($this->identity->leaseId);
        }

        try {
            $cacheKey = $this->localPublicationCacheKey();
            if ($cacheKey !== null && function_exists('apcu_enabled') && apcu_enabled()) {
                $success = false;
                $cached = apcu_fetch($cacheKey, $success);
                if ($success && is_array($cached) && !array_is_list($cached)) {
                    return $this->materializationState($cached);
                }
            }
            $payload = file_get_contents($this->mapFile);
            $markerPayload = file_get_contents($this->mapFile . '.verified');
            if (!is_string($payload) || !is_string($markerPayload)) {
                return RuntimeMaterializationState::unavailable($this->identity->leaseId);
            }
            $document = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            $marker = json_decode($markerPayload, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($document) || array_is_list($document)) {
                return RuntimeMaterializationState::unavailable($this->identity->leaseId);
            }
            if (!is_array($marker) || array_is_list($marker)) {
                return RuntimeMaterializationState::unavailable($this->identity->leaseId);
            }
            $this->verifyMarker($marker, $payload);
            $this->verifyDocument($document, false);
            $state = $this->materializationState($document);
            if ($cacheKey !== null && function_exists('apcu_enabled') && apcu_enabled()) {
                apcu_store($cacheKey, $document, 3_600);
            }

            return $state;
        } catch (\Throwable) {
            return RuntimeMaterializationState::unavailable($this->identity->leaseId);
        }
    }

    public function localMarkerFresh(int $maximumAgeSeconds): bool
    {
        if ($maximumAgeSeconds < 1) {
            throw new InvalidArgumentException('The runtime readiness age must be positive.');
        }
        $payload = file_get_contents($this->mapFile . '.ready');
        if (!is_string($payload)) {
            return false;
        }
        try {
            $marker = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($marker) || array_is_list($marker)) {
                return false;
            }
            $base = [
                'format' => $marker['format'] ?? null,
                'generation' => $marker['generation'] ?? null,
                'publication_sha256' => $marker['publication_sha256'] ?? null,
                'signing_key_id' => $marker['signing_key_id'] ?? null,
                'verified_at' => $marker['verified_at'] ?? null,
            ];
            if (
                $base['format'] !== 'kumwe-runtime-readiness-v1'
                || !is_int($base['generation'])
                || $base['generation'] < 1
                || !is_string($base['verified_at'])
            ) {
                return false;
            }
            $this->keys->assertSignature(
                $this->requiredString($marker, 'signing_key_id'),
                'readiness:' . RuntimeCanonicalJson::encode($base),
                $this->requiredString($marker, 'trust_hmac'),
            );
            $local = $this->inspectLocal();
            if (
                !$local->trusted
                || ($base['generation'] ?? null) !== $local->generation
                || !is_string($base['publication_sha256'])
                || !hash_equals($base['publication_sha256'], $local->publicationChecksum)
            ) {
                return false;
            }
            $verifiedAt = $base['verified_at'] ?? null;
            if (!is_string($verifiedAt)) {
                return false;
            }
            $verified = new \DateTimeImmutable($verifiedAt);

            return $verified >= $this->clock->now()->sub(new DateInterval('PT' . $maximumAgeSeconds . 'S'));
        } catch (\Throwable) {
            return false;
        }
    }

    public function isCurrent(RuntimeMaterializationState $loaded): bool
    {
        if (
            !$loaded->trusted
            || $loaded->replicaId !== $this->identity->leaseId
            || $loaded->publication === null
        ) {
            return false;
        }

        try {
            $this->verifyDocument($loaded->publication->document);
        } catch (\Throwable) {
            return false;
        }
        if (!$this->matchesAuthority($loaded)) {
            return false;
        }
        $this->heartbeat($loaded);

        return true;
    }

    public function matchesAuthority(RuntimeMaterializationState $loaded): bool
    {
        if (
            !$loaded->trusted
            || $loaded->replicaId !== $this->identity->leaseId
            || $loaded->publication === null
        ) {
            return false;
        }
        try {
            $this->verifyDocument($loaded->publication->document);
        } catch (\Throwable) {
            return false;
        }
        $local = $this->inspectLocal();
        if (
            !$local->trusted
            || $local->generation !== $loaded->generation
            || !hash_equals($local->publicationChecksum, $loaded->publicationChecksum)
            || !hash_equals($local->trustHmac, $loaded->trustHmac)
        ) {
            return false;
        }

        $generation = $this->currentGeneration();
        $publication = $this->publication($generation);
        if ($publication !== null) {
            try {
                $this->verifiedDocument($publication);
            } catch (\Throwable) {
                return false;
            }
        }
        if (
            $publication === null
            || $generation !== $loaded->generation
            || !hash_equals(
                $this->requiredString($publication, 'publication_sha256'),
                $loaded->publicationChecksum,
            )
            || !hash_equals($this->requiredString($publication, 'trust_hmac'), $loaded->trustHmac)
        ) {
            return false;
        }

        return hash_equals(
            $this->requiredString($publication, 'state_sha256'),
            $this->stateChecksum($this->runtimeState()),
        );
    }

    public function assertLoadedGenerationCurrent(RuntimeMaterializationState $loaded): void
    {
        if (!$this->isCurrent($loaded)) {
            throw new RuntimeException('This process loaded a stale or untrusted extension runtime generation.');
        }
    }

    public function scheduleRetirement(string $runtimePath, int $generation): void
    {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('Runtime retirement must be staged inside the registry transaction.');
        }
        if ($generation < 1 || !$this->safeRelativeRuntime($runtimePath)) {
            throw new InvalidArgumentException('The retired extension runtime is invalid.');
        }

        $runtimeSha256 = hash('sha256', $runtimePath);
        $existing = $this->database->fetchAssociative(sprintf(
            'SELECT runtime_path FROM %s WHERE runtime_sha256 = ?',
            $this->tables->quoted('extension_runtime_retirements'),
        ), [$runtimeSha256]);
        if ($existing !== false) {
            if (($existing['runtime_path'] ?? null) !== $runtimePath) {
                throw new RuntimeException('A runtime retirement path hash collision was detected.');
            }

            return;
        }

        $this->database->insert($this->tables->raw('extension_runtime_retirements'), [
            'id' => Uuid::uuid7()->toString(),
            'runtime_path' => $runtimePath,
            'runtime_sha256' => $runtimeSha256,
            'retire_after_generation' => $generation,
            'retain_until' => $this->clock->now()->add(new DateInterval('PT' . $this->retentionSeconds . 'S')),
            'cleaned_at' => null,
        ], ['retain_until' => Types::DATETIME_IMMUTABLE]);
    }

    public function cancelRetirement(string $runtimePath): void
    {
        if (!$this->database->isTransactionActive() || !$this->safeRelativeRuntime($runtimePath)) {
            throw new RuntimeException('Runtime retirement cancellation requires a registry transaction.');
        }
        $this->database->delete($this->tables->raw('extension_runtime_retirements'), [
            'runtime_sha256' => hash('sha256', $runtimePath),
            'runtime_path' => $runtimePath,
        ]);
    }

    /** @param list<array<string, mixed>> $extensions */
    private function stageDocument(
        string $action,
        array $extensions,
        bool $requireCurrent = true,
        bool $currentSignatureOnly = false,
    ): int {
        if ($action === '' || strlen($action) > 127) {
            throw new InvalidArgumentException('A runtime publication action is required.');
        }

        $current = $this->currentGeneration();
        if ($current > 0) {
            $currentPublication = $this->publication($current);
            if ($currentPublication === null && $requireCurrent) {
                throw new RuntimeException('The current runtime publication is missing; reconcile before mutation.');
            }
            if ($currentPublication !== null) {
                $this->verifiedDocument($currentPublication, !$currentSignatureOnly);
            }
        }
        $generation = $current + 1;
        $stateSha256 = $this->stateChecksum($extensions);
        $base = [
            'format' => 'kumwe-extension-map-v3',
            'generation' => $generation,
            'state_sha256' => $stateSha256,
            'action' => $action,
            'signing_key_id' => $this->keys->activeKeyId,
            'extensions' => $extensions,
        ];
        $publicationSha256 = hash('sha256', $this->json($base));
        $trustHmac = $this->keys->sign($generation . ':' . $publicationSha256);
        $document = $base + [
            'publication_sha256' => $publicationSha256,
            'trust_hmac' => $trustHmac,
        ];

        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET generation = ?, rebuilt_at = ? WHERE singleton_key = 1 AND generation = ?',
            $this->tables->quoted('extension_runtime_generation'),
        ), [$generation, $this->clock->now(), $current], [
            Types::BIGINT,
            Types::DATETIME_IMMUTABLE,
            Types::BIGINT,
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('The runtime registry changed concurrently; retry the complete mutation.');
        }
        $this->database->insert($this->tables->raw('extension_runtime_publications'), [
            'generation' => $generation,
            'state_sha256' => $stateSha256,
            'publication_sha256' => $publicationSha256,
            'trust_hmac' => $trustHmac,
            'signing_key_id' => $this->keys->activeKeyId,
            'action' => $action,
            'payload' => $document,
            'created_at' => $this->clock->now(),
        ], ['payload' => Types::JSON, 'created_at' => Types::DATETIME_IMMUTABLE]);
        if (!hash_equals($stateSha256, $this->stateChecksum($this->runtimeState()))) {
            throw new RuntimeException('The runtime registry changed while publication was being staged.');
        }

        return $generation;
    }

    /** @return list<array<string, mixed>> */
    private function runtimeState(): array
    {
        $themes = $this->themeAssignments();
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, e.installed_version, e.service_provider, e.extension_type, e.runtime_path, '
            . 'r.manifest, r.package_sha256, r.signing_key_id, r.artifact_sha256, r.deployed_tree_sha256 '
            . 'FROM %s e INNER JOIN %s r ON r.extension_id = e.id AND r.version = e.installed_version '
            . "WHERE e.status = 'active' ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ));
        $extensions = [];

        foreach ($rows as $row) {
            $identifier = $row['identifier'] ?? null;
            $provider = $row['service_provider'] ?? null;
            $runtimePath = $row['runtime_path'] ?? null;
            $type = $row['extension_type'] ?? null;
            $manifestJson = $row['manifest'] ?? null;
            $version = $row['installed_version'] ?? null;
            $packageSha256 = $row['package_sha256'] ?? null;
            $signingKeyId = $row['signing_key_id'] ?? null;
            $artifactSha256 = $row['artifact_sha256'] ?? null;
            $deployedTreeSha256 = $row['deployed_tree_sha256'] ?? null;
            if (
                !is_string($identifier)
                || !is_string($provider)
                || !is_string($runtimePath)
                || !is_string($type)
                || !is_string($version)
                || !is_string($packageSha256)
                || preg_match('/^[a-f0-9]{64}$/D', $packageSha256) !== 1
                || ($signingKeyId !== null && !is_string($signingKeyId))
                || !is_string($artifactSha256)
                || preg_match('/^[a-f0-9]{64}$/D', $artifactSha256) !== 1
                || !is_string($deployedTreeSha256)
                || preg_match('/^[a-f0-9]{64}$/D', $deployedTreeSha256) !== 1
                || !$this->safeRelativeRuntime($runtimePath)
            ) {
                throw new RuntimeException('An active extension has incomplete runtime metadata.');
            }

            $manifest = ExtensionManifest::fromJson(is_string($manifestJson)
                ? $manifestJson
                : json_encode($manifestJson, JSON_THROW_ON_ERROR));
            $extensions[] = [
                'identifier' => $identifier,
                'version' => $version,
                'provider' => $provider,
                'type' => $type,
                'root' => $runtimePath,
                'package_sha256' => $packageSha256,
                'signing_key_id' => $signingKeyId,
                'artifact_sha256' => $artifactSha256,
                'deployed_tree_sha256' => $deployedTreeSha256,
                'runtime_tree_sha256' => $this->artifacts->digestRelative(
                    $this->extensionRoot,
                    $runtimePath,
                    excludeRetainedPackage: true,
                ),
                'asset_tree_sha256' => $this->artifacts->digestRelative(
                    $this->publicAssetRoot,
                    $runtimePath,
                    optional: true,
                ),
                'autoload' => $manifest->autoload(),
                'theme_surfaces' => $themes[$identifier]['surfaces'] ?? [],
                'theme_sites' => $themes[$identifier]['sites'] ?? [],
            ];
        }

        return $extensions;
    }

    /** @return array<string, array{surfaces: list<string>, sites: list<string>}> */
    private function themeAssignments(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, a.surface FROM %s a INNER JOIN %s e ON e.id = a.extension_id '
            . 'ORDER BY e.identifier, a.surface',
            $this->tables->quoted('theme_activations'),
            $this->tables->quoted('extensions'),
        ));
        $surfaces = [];

        foreach ($rows as $row) {
            $identifier = $row['identifier'] ?? null;
            $surface = $row['surface'] ?? null;
            if (!is_string($identifier) || !is_string($surface)) {
                throw new RuntimeException('A persisted theme activation is invalid.');
            }
            $surfaces[$identifier] ??= ['surfaces' => [], 'sites' => []];
            $surfaces[$identifier]['surfaces'][] = $surface;
        }

        $sites = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, a.site_identifier FROM %s a INNER JOIN %s e ON e.id = a.extension_id '
            . 'ORDER BY e.identifier, a.site_identifier',
            $this->tables->quoted('site_theme_activations'),
            $this->tables->quoted('extensions'),
        ));
        foreach ($sites as $row) {
            $identifier = $row['identifier'] ?? null;
            $site = $row['site_identifier'] ?? null;
            if (!is_string($identifier) || !is_string($site)) {
                throw new RuntimeException('A persisted site theme activation is invalid.');
            }
            $surfaces[$identifier] ??= ['surfaces' => [], 'sites' => []];
            $surfaces[$identifier]['sites'][] = $site;
            if (!in_array('site', $surfaces[$identifier]['surfaces'], true)) {
                $surfaces[$identifier]['surfaces'][] = 'site';
            }
        }

        return $surfaces;
    }

    private function currentGeneration(): int
    {
        $result = $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        ));
        if (!is_int($result) && (!is_string($result) || preg_match('/^[0-9]+$/D', $result) !== 1)) {
            throw new RuntimeException('The extension runtime generation is invalid.');
        }

        return (int) $result;
    }

    private function lockGeneration(): int
    {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('The runtime generation may be locked only inside a transaction.');
        }
        $suffix = $this->database->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractSQLitePlatform
            ? ''
            : ' FOR UPDATE';
        $result = $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1%s',
            $this->tables->quoted('extension_runtime_generation'),
            $suffix,
        ));
        if (!is_numeric($result) || (int) $result < 0) {
            throw new RuntimeException('The extension runtime generation is invalid.');
        }

        return (int) $result;
    }

    /** @return array<string, mixed>|null */
    private function publication(int $generation): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT generation, state_sha256, publication_sha256, trust_hmac, signing_key_id, action, payload '
            . 'FROM %s WHERE generation = ?',
            $this->tables->quoted('extension_runtime_publications'),
        ), [$generation]);

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $publication @return array<string, mixed> */
    private function verifiedDocument(array $publication, bool $assertArtifacts = true): array
    {
        $payload = $publication['payload'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('The authoritative runtime publication payload is invalid.');
        }
        $this->verifyDocument($payload, $assertArtifacts);
        if (
            (int) ($publication['generation'] ?? -1) !== ($payload['generation'] ?? null)
            || ($publication['state_sha256'] ?? null) !== ($payload['state_sha256'] ?? null)
            || ($publication['action'] ?? null) !== ($payload['action'] ?? null)
            || ($publication['signing_key_id'] ?? null) !== ($payload['signing_key_id'] ?? null)
            ||
            !hash_equals(
                $this->requiredString($publication, 'publication_sha256'),
                $this->requiredString($payload, 'publication_sha256'),
            )
            || !hash_equals(
                $this->requiredString($publication, 'trust_hmac'),
                $this->requiredString($payload, 'trust_hmac'),
            )
        ) {
            throw new RuntimeException('The authoritative runtime publication metadata is inconsistent.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $document */
    private function verifyDocument(array $document, bool $assertArtifacts = true): void
    {
        $generation = $document['generation'] ?? null;
        $extensions = $document['extensions'] ?? null;
        if (
            ($document['format'] ?? null) !== 'kumwe-extension-map-v3'
            || !is_int($generation)
            || $generation < 1
            || !is_array($extensions)
            || !array_is_list($extensions)
        ) {
            throw new RuntimeException('The runtime publication document is invalid.');
        }
        if ($assertArtifacts) {
            $this->assertArtifacts($extensions);
        }

        $base = [
            'format' => 'kumwe-extension-map-v3',
            'generation' => $generation,
            'state_sha256' => $this->requiredString($document, 'state_sha256'),
            'action' => $this->requiredString($document, 'action'),
            'signing_key_id' => $this->requiredString($document, 'signing_key_id'),
            'extensions' => $extensions,
        ];
        $checksum = hash('sha256', $this->json($base));
        $trust = $this->requiredString($document, 'trust_hmac');
        $this->keys->assertSignature(
            $this->requiredString($document, 'signing_key_id'),
            $generation . ':' . $checksum,
            $trust,
        );
        if (
            !hash_equals($checksum, $this->requiredString($document, 'publication_sha256'))
            || !hash_equals($this->stateChecksum($extensions), $this->requiredString($document, 'state_sha256'))
        ) {
            throw new RuntimeException('The runtime publication trust verification failed.');
        }
    }

    /** @param list<mixed> $extensions */
    private function assertArtifacts(array $extensions): void
    {
        foreach ($extensions as $extension) {
            if (!is_array($extension) || array_is_list($extension)) {
                throw new RuntimeException('A runtime publication extension is invalid.');
            }
            $root = $this->requiredString($extension, 'root');
            if (
                !$this->safeRelativeRuntime($root)
                || !hash_equals(
                    $this->requiredString($extension, 'runtime_tree_sha256'),
                    $this->artifacts->digestRelative(
                        $this->extensionRoot,
                        $root,
                        excludeRetainedPackage: true,
                    ),
                )
                || !hash_equals(
                    $this->requiredString($extension, 'asset_tree_sha256'),
                    $this->artifacts->digestRelative($this->publicAssetRoot, $root, optional: true),
                )
            ) {
                throw new RuntimeException('A runtime publication artifact digest does not match deployed bytes.');
            }
        }
    }

    private function acknowledge(RuntimeMaterializationState $state): void
    {
        $now = $this->clock->now();
        $affected = $this->database->update($this->tables->raw('extension_runtime_materializations'), [
            'deployment_id' => $this->identity->deploymentId,
            'replica_name' => $this->identity->replicaId,
            'process_id' => $this->identity->processId,
            'generation' => $state->generation,
            'publication_sha256' => $state->publicationChecksum,
            'trust_hmac' => $state->trustHmac,
            'materialized_at' => $now,
            'last_seen_at' => $now,
            'lease_until' => $now->add(new DateInterval('PT' . $this->replicaLeaseSeconds . 'S')),
        ], ['replica_id' => $state->replicaId], [
            'generation' => Types::BIGINT,
            'materialized_at' => Types::DATETIME_IMMUTABLE,
            'last_seen_at' => Types::DATETIME_IMMUTABLE,
            'lease_until' => Types::DATETIME_IMMUTABLE,
        ]);
        if ($affected === 0) {
            try {
                $this->database->insert($this->tables->raw('extension_runtime_materializations'), [
                    'replica_id' => $state->replicaId,
                    'deployment_id' => $this->identity->deploymentId,
                    'replica_name' => $this->identity->replicaId,
                    'process_id' => $this->identity->processId,
                    'generation' => $state->generation,
                    'publication_sha256' => $state->publicationChecksum,
                    'trust_hmac' => $state->trustHmac,
                    'materialized_at' => $now,
                    'last_seen_at' => $now,
                    'lease_until' => $now->add(new DateInterval('PT' . $this->replicaLeaseSeconds . 'S')),
                ], [
                    'generation' => Types::BIGINT,
                    'materialized_at' => Types::DATETIME_IMMUTABLE,
                    'last_seen_at' => Types::DATETIME_IMMUTABLE,
                    'lease_until' => Types::DATETIME_IMMUTABLE,
                ]);
            } catch (UniqueConstraintViolationException) {
                $this->database->update($this->tables->raw('extension_runtime_materializations'), [
                    'generation' => $state->generation,
                    'publication_sha256' => $state->publicationChecksum,
                    'trust_hmac' => $state->trustHmac,
                    'materialized_at' => $now,
                    'last_seen_at' => $now,
                    'lease_until' => $now->add(new DateInterval('PT' . $this->replicaLeaseSeconds . 'S')),
                ], ['replica_id' => $state->replicaId], [
                    'generation' => Types::BIGINT,
                    'materialized_at' => Types::DATETIME_IMMUTABLE,
                    'last_seen_at' => Types::DATETIME_IMMUTABLE,
                    'lease_until' => Types::DATETIME_IMMUTABLE,
                ]);
            }
        }
    }

    private function heartbeat(RuntimeMaterializationState $state): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET last_seen_at = ?, lease_until = ? WHERE replica_id = ? AND generation = ? '
            . 'AND publication_sha256 = ? AND trust_hmac = ?',
            $this->tables->quoted('extension_runtime_materializations'),
        ), [
            $this->clock->now(),
            $this->clock->now()->add(new DateInterval('PT' . $this->replicaLeaseSeconds . 'S')),
            $state->replicaId,
            $state->generation,
            $state->publicationChecksum,
            $state->trustHmac,
        ], [
            Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING,
            Types::BIGINT, Types::STRING, Types::STRING,
        ]);
        if ($affected !== 1) {
            $this->acknowledge($state);
        }
    }

    private function collectRetiredRuntimes(): void
    {
        $now = $this->clock->now();
        $this->reopenReappearedRetirements();
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, runtime_path, retire_after_generation FROM %s '
            . 'WHERE cleaned_at IS NULL AND retain_until <= ? '
            . 'AND (claim_until IS NULL OR claim_until < ?) ORDER BY retain_until, id LIMIT 100',
            $this->tables->quoted('extension_runtime_retirements'),
        ), [$now, $now], [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE]);

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $runtimePath = $row['runtime_path'] ?? null;
            $generation = $row['retire_after_generation'] ?? null;
            if (!is_string($id) || !is_string($runtimePath) || !is_numeric($generation)) {
                throw new RuntimeException('A runtime retirement record is invalid.');
            }
            $claim = bin2hex(random_bytes(32));
            $claimed = $this->database->executeStatement(sprintf(
                'UPDATE %s SET claim_token = ?, claim_until = ? WHERE id = ? AND cleaned_at IS NULL '
                . 'AND (claim_until IS NULL OR claim_until < ?)',
                $this->tables->quoted('extension_runtime_retirements'),
            ), [$claim, $now->add(new DateInterval('PT60S')), $id, $now], [
                Types::STRING, Types::DATETIME_IMMUTABLE, Types::GUID, Types::DATETIME_IMMUTABLE,
            ]);
            if ($claimed !== 1) {
                continue;
            }
            $referenced = $this->database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE runtime_path = ?',
                $this->tables->quoted('extensions'),
            ), [$runtimePath]);
            $pending = $this->database->fetchOne(sprintf(
                "SELECT COUNT(*) FROM %s WHERE runtime_path = ? AND transaction_outcome = 'unknown'",
                $this->tables->quoted('extension_install_operations'),
            ), [$runtimePath]);
            if ((int) $referenced !== 0 || (int) $pending !== 0) {
                $this->database->delete($this->tables->raw('extension_runtime_retirements'), [
                    'id' => $id,
                    'claim_token' => $claim,
                ]);
                continue;
            }
            $blockers = $this->database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE lease_until >= ? AND generation < ?',
                $this->tables->quoted('extension_runtime_materializations'),
            ), [$now, (int) $generation], [Types::DATETIME_IMMUTABLE, Types::BIGINT]);
            if ((int) $blockers !== 0) {
                $this->releaseRetirementClaim($id, $claim);
                continue;
            }

            try {
                $runtime = $this->extensionRoot . '/' . $runtimePath;
                $assets = $this->publicAssetRoot . '/' . $runtimePath;
                $this->removeTree($runtime, $this->extensionRoot);
                $this->removeTree($assets, $this->publicAssetRoot);
                if (file_exists($runtime) || is_link($runtime) || file_exists($assets) || is_link($assets)) {
                    throw new RuntimeException('A retired runtime reappeared during verified deletion.');
                }
                $cleaned = $this->database->executeStatement(sprintf(
                    'UPDATE %s SET cleaned_at = ?, claim_token = NULL, claim_until = NULL '
                    . 'WHERE id = ? AND claim_token = ?',
                    $this->tables->quoted('extension_runtime_retirements'),
                ), [$this->clock->now(), $id, $claim], [
                    Types::DATETIME_IMMUTABLE, Types::GUID, Types::STRING,
                ]);
                if ($cleaned !== 1) {
                    throw new RuntimeException('The runtime retirement claim was lost during deletion.');
                }
            } catch (\Throwable $exception) {
                $this->releaseRetirementClaim($id, $claim);
                throw $exception;
            }
        }
    }

    private function releaseRetirementClaim(string $id, string $claim): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET claim_token = NULL, claim_until = NULL WHERE id = ? AND claim_token = ?',
            $this->tables->quoted('extension_runtime_retirements'),
        ), [$id, $claim], [Types::GUID, Types::STRING]);
    }

    private function reopenReappearedRetirements(): void
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT id, runtime_path FROM %s WHERE cleaned_at IS NOT NULL ORDER BY cleaned_at LIMIT 100',
            $this->tables->quoted('extension_runtime_retirements'),
        ));
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $path = $row['runtime_path'] ?? null;
            if (!is_string($id) || !is_string($path)) {
                continue;
            }
            if (
                file_exists($this->extensionRoot . '/' . $path)
                || is_link($this->extensionRoot . '/' . $path)
                || file_exists($this->publicAssetRoot . '/' . $path)
                || is_link($this->publicAssetRoot . '/' . $path)
            ) {
                $this->database->update($this->tables->raw('extension_runtime_retirements'), [
                    'cleaned_at' => null,
                    'retain_until' => $this->clock->now(),
                ], ['id' => $id], ['retain_until' => Types::DATETIME_IMMUTABLE]);
            }
        }
    }

    private function reconcileOrphanedRuntimes(): void
    {
        if (!is_dir($this->extensionRoot)) {
            return;
        }
        $current = $this->stringSet(
            $this->database->fetchFirstColumn(sprintf(
                'SELECT runtime_path FROM %s WHERE runtime_path IS NOT NULL',
                $this->tables->quoted('extensions'),
            )),
        );
        $retired = $this->stringSet(
            $this->database->fetchFirstColumn(sprintf(
                'SELECT runtime_path FROM %s WHERE cleaned_at IS NULL',
                $this->tables->quoted('extension_runtime_retirements'),
            )),
        );
        $pending = $this->stringSet(
            $this->database->fetchFirstColumn(sprintf(
                "SELECT runtime_path FROM %s WHERE transaction_outcome = 'unknown'",
                $this->tables->quoted('extension_install_operations'),
            )),
        );
        $cutoff = $this->clock->now()->getTimestamp() - $this->retentionSeconds;
        $candidates = [];
        $inspected = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->extensionRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $iterator->setMaxDepth(2);
        foreach ($iterator as $item) {
            if (++$inspected > 1_000) {
                break;
            }
            if ($iterator->getDepth() !== 2 || !$item->isDir() || $item->isLink()) {
                continue;
            }
            $path = $item->getPathname();
            $relative = substr($path, strlen(rtrim($this->extensionRoot, '/')) + 1);
            $modified = filemtime($path);
            if (
                $this->safeRelativeRuntime($relative)
                && !isset($current[$relative], $retired[$relative], $pending[$relative])
                && is_int($modified)
                && $modified < $cutoff
            ) {
                $candidates[] = $relative;
                if (count($candidates) === 100) {
                    break;
                }
            }
        }
        if ($candidates === [] || $this->currentGeneration() < 1) {
            return;
        }
        $this->database->transactional(function () use ($candidates): void {
            $generation = $this->currentGeneration();
            foreach ($candidates as $runtimePath) {
                $this->scheduleRetirement($runtimePath, $generation);
            }
        });
    }

    private function purgeRuntimeHistory(): void
    {
        $now = $this->clock->now();
        $stale = $this->database->fetchFirstColumn(sprintf(
            'SELECT replica_id FROM %s WHERE lease_until IS NULL OR lease_until < ? '
            . 'ORDER BY lease_until LIMIT 100',
            $this->tables->quoted('extension_runtime_materializations'),
        ), [$now], [Types::DATETIME_IMMUTABLE]);
        foreach ($stale as $replicaId) {
            if (is_string($replicaId)) {
                $this->database->delete(
                    $this->tables->raw('extension_runtime_materializations'),
                    ['replica_id' => $replicaId],
                );
            }
        }

        $minimumLive = $this->database->fetchOne(sprintf(
            'SELECT MIN(generation) FROM %s WHERE lease_until >= ?',
            $this->tables->quoted('extension_runtime_materializations'),
        ), [$now], [Types::DATETIME_IMMUTABLE]);
        $minimumRetained = is_numeric($minimumLive) ? (int) $minimumLive : $this->currentGeneration();
        $oldPublications = $this->database->fetchFirstColumn(sprintf(
            'SELECT generation FROM %s WHERE generation < ? ORDER BY generation LIMIT 100',
            $this->tables->quoted('extension_runtime_publications'),
        ), [$minimumRetained], [Types::BIGINT]);
        foreach ($oldPublications as $generation) {
            if (is_numeric($generation)) {
                $this->database->delete(
                    $this->tables->raw('extension_runtime_publications'),
                    ['generation' => (int) $generation],
                    ['generation' => Types::BIGINT],
                );
            }
        }

        $retirementCutoff = $now->sub(new DateInterval('P7D'));
        $retirements = $this->database->fetchFirstColumn(sprintf(
            'SELECT id FROM %s WHERE cleaned_at IS NOT NULL AND cleaned_at < ? ORDER BY cleaned_at LIMIT 100',
            $this->tables->quoted('extension_runtime_retirements'),
        ), [$retirementCutoff], [Types::DATETIME_IMMUTABLE]);
        foreach ($retirements as $id) {
            if (is_string($id)) {
                $this->database->delete($this->tables->raw('extension_runtime_retirements'), ['id' => $id]);
            }
        }
    }

    private function removeTree(string $directory, string $allowedRoot): void
    {
        if (!file_exists($directory) && !is_link($directory)) {
            return;
        }
        $lexicalRoot = rtrim($allowedRoot, '/');
        if (!str_starts_with($directory, $lexicalRoot . '/')) {
            throw new RuntimeException('Refusing to remove a retired path outside extension storage.');
        }
        $component = $lexicalRoot;
        foreach (explode('/', substr($directory, strlen($lexicalRoot) + 1)) as $segment) {
            $component .= '/' . $segment;
            if (is_link($component)) {
                throw new RuntimeException('Refusing to traverse a symlink while retiring an extension.');
            }
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Refusing to remove an invalid retired extension directory.');
        }
        $resolvedRoot = realpath($allowedRoot);
        $resolved = realpath($directory);
        if (
            !is_string($resolvedRoot)
            || !is_string($resolved)
            || !str_starts_with($resolved . '/', $resolvedRoot . '/')
        ) {
            throw new RuntimeException('Refusing to remove a retired path outside extension storage.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            }
        }
        rmdir($resolved);
    }

    /** @param resource $handle */
    private function assertOpenLockFile($handle, string $path): void
    {
        $opened = fstat($handle);
        $named = lstat($path);
        if (
            !is_array($opened)
            || !is_array($named)
            || is_link($path)
            || (($opened['mode'] ?? 0) & 0170000) !== 0100000
            || ($opened['nlink'] ?? 0) !== 1
            || ($opened['dev'] ?? null) !== ($named['dev'] ?? null)
            || ($opened['ino'] ?? null) !== ($named['ino'] ?? null)
        ) {
            throw new RuntimeException('The extension runtime map lock changed during acquisition.');
        }
    }

    /** @param array<mixed> $extensions */
    private function stateChecksum(array $extensions): string
    {
        return hash('sha256', $this->json($extensions));
    }

    /** @param array<mixed> $value */
    private function json(array $value): string
    {
        return RuntimeCanonicalJson::encode($value);
    }

    /** @param array<string, mixed> $document */
    private function markerPayload(array $document, string $mapPayload): string
    {
        $base = [
            'format' => 'kumwe-runtime-verification-v1',
            'generation' => $document['generation'] ?? null,
            'publication_sha256' => $document['publication_sha256'] ?? null,
            'map_sha256' => hash('sha256', $mapPayload),
            'signing_key_id' => $this->keys->activeKeyId,
        ];
        $marker = $base + ['trust_hmac' => $this->keys->sign('marker:' . RuntimeCanonicalJson::encode($base))];

        return json_encode($marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $marker */
    private function verifyMarker(array $marker, string $mapPayload): void
    {
        $base = [
            'format' => $marker['format'] ?? null,
            'generation' => $marker['generation'] ?? null,
            'publication_sha256' => $marker['publication_sha256'] ?? null,
            'map_sha256' => $marker['map_sha256'] ?? null,
            'signing_key_id' => $marker['signing_key_id'] ?? null,
        ];
        if (
            $base['format'] !== 'kumwe-runtime-verification-v1'
            || !is_int($base['generation'])
            || !is_string($base['publication_sha256'])
            || !is_string($base['map_sha256'])
            || !hash_equals($base['map_sha256'], hash('sha256', $mapPayload))
        ) {
            throw new RuntimeException('The local runtime verification marker is invalid.');
        }
        $this->keys->assertSignature(
            $this->requiredString($marker, 'signing_key_id'),
            'marker:' . RuntimeCanonicalJson::encode($base),
            $this->requiredString($marker, 'trust_hmac'),
        );
    }

    /** @param array<string, mixed> $document */
    private function readinessPayload(array $document): string
    {
        $base = [
            'format' => 'kumwe-runtime-readiness-v1',
            'generation' => $document['generation'] ?? null,
            'publication_sha256' => $document['publication_sha256'] ?? null,
            'signing_key_id' => $this->keys->activeKeyId,
            'verified_at' => $this->clock->now()->format(DATE_ATOM),
        ];
        $readiness = $base + [
            'trust_hmac' => $this->keys->sign('readiness:' . RuntimeCanonicalJson::encode($base)),
        ];

        return json_encode($readiness, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function writeAtomicFile(string $path, string $payload, bool $immutable = true): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('The runtime verification marker target is unsafe.');
        }
        if ($immutable && is_file($path)) {
            $existing = file_get_contents($path);
            if (is_string($existing) && hash_equals(hash('sha256', $existing), hash('sha256', $payload))) {
                return;
            }
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new RuntimeException('The runtime verification marker could not be written completely.');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $path)) {
                throw new RuntimeException('The runtime verification marker could not be activated atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param array<string, mixed> $document */
    private function materializationState(array $document): RuntimeMaterializationState
    {
        $generation = $document['generation'] ?? null;
        if (!is_int($generation) || $generation < 1) {
            throw new RuntimeException('The local runtime publication generation is invalid.');
        }

        return new RuntimeMaterializationState(
            $this->identity->leaseId,
            $generation,
            $this->requiredString($document, 'publication_sha256'),
            $this->requiredString($document, 'trust_hmac'),
            true,
            new VerifiedRuntimePublication($document),
        );
    }

    private function localPublicationCacheKey(): ?string
    {
        $map = lstat($this->mapFile);
        $marker = lstat($this->mapFile . '.verified');
        if (!is_array($map) || !is_array($marker)) {
            return null;
        }
        $identity = [$this->mapFile, $this->keys->cacheIdentity()];
        foreach ([$map, $marker] as $metadata) {
            foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
                $identity[] = $metadata[$field] ?? null;
            }
        }

        return 'kumwe.runtime.publication.' . hash('sha256', RuntimeCanonicalJson::encode($identity));
    }

    /** @param array<string, mixed> $prior @param list<array<string, mixed>> $next */
    private function assertAdministratorRecoveryTransition(array $prior, array $next, string $identifier): void
    {
        $before = $this->extensionsByIdentifier($prior['extensions'] ?? null);
        $after = $this->extensionsByIdentifier($next);
        foreach ($before as $name => $entry) {
            if ($name !== $identifier) {
                if (
                    !isset($after[$name])
                    || RuntimeCanonicalJson::encode($after[$name]) !== RuntimeCanonicalJson::encode($entry)
                ) {
                    throw new RuntimeException('Administrator recovery may not change unrelated runtime entries.');
                }
                continue;
            }
            $oldSurfaces = $entry['theme_surfaces'] ?? null;
            if (!is_array($oldSurfaces) || !in_array('administrator', $oldSurfaces, true)) {
                throw new RuntimeException('The recovered administrator theme is absent from the signed publication.');
            }
            $expected = $entry;
            $expected['theme_surfaces'] = array_values(array_filter(
                $oldSurfaces,
                static fn (mixed $surface): bool => $surface !== 'administrator',
            ));
            if ($expected['theme_surfaces'] === []) {
                if (isset($after[$name])) {
                    throw new RuntimeException('Administrator-only recovery must disable the damaged runtime entry.');
                }
            } elseif (
                !isset($after[$name])
                || RuntimeCanonicalJson::encode($after[$name]) !== RuntimeCanonicalJson::encode($expected)
            ) {
                throw new RuntimeException('Administrator recovery may remove only the administrator surface.');
            }
        }
        foreach ($after as $name => $_entry) {
            if (!isset($before[$name])) {
                throw new RuntimeException('Administrator recovery may not add runtime entries.');
            }
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function extensionsByIdentifier(mixed $extensions): array
    {
        if (!is_array($extensions) || !array_is_list($extensions)) {
            throw new RuntimeException('The signed runtime extension list is invalid.');
        }
        $indexed = [];
        foreach ($extensions as $extension) {
            if (!is_array($extension) || array_is_list($extension)) {
                throw new RuntimeException('The signed runtime extension entry is invalid.');
            }
            $identifier = $this->requiredString($extension, 'identifier');
            if (isset($indexed[$identifier])) {
                throw new RuntimeException('The signed runtime extension list contains duplicates.');
            }
            $indexed[$identifier] = $extension;
        }

        return $indexed;
    }

    private function safeRelativeRuntime(string $runtimePath): bool
    {
        return preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+$#D', $runtimePath) === 1
            && !str_contains($runtimePath, '..');
    }

    /** @param list<mixed> $values @return array<string, true> */
    private function stringSet(array $values): array
    {
        $set = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $set[$value] = true;
            }
        }

        return $set;
    }

    /** @param array<string, mixed> $row */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Runtime publication field %s is invalid.', $field));
        }

        return $value;
    }
}
