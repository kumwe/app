<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Authoritative publisher of the signed extension runtime map, and the replica-side materializer for it.
 *
 * The request path never queries the extension registry: it reads one immutable, HMAC-signed JSON
 * document from disk. This class is the only writer of that document. A registry mutation calls
 * `stage()` from inside its own transaction to publish the next generation, and every replica later
 * calls `reconcileAndMaterialize()`, which trusts a publication only after its signature, its state
 * checksum, and the SHA-256 of the bytes actually deployed under the extension and public asset roots
 * all agree. Because a generation stays in service until the last replica moves off it, this class
 * also owns the lease bookkeeping that pins a retired extension tree on disk and the janitorial passes
 * that eventually delete it, reclaim orphaned trees and trim publication history.
 *
 * @since  2.0.0
 */
final readonly class ExtensionRuntimeMapCompiler implements TrustRuntimeInvalidator
{
    /**
     * Writer that claims and renews this replica's lease row without fighting its own peers.
     *
     * @var    RuntimeLeaseWriter
     * @since  2.0.0
     */
    private RuntimeLeaseWriter $leases;

    /**
     * Wire the compiler to the registry, the local cache location and the signing key ring.
     *
     * @param   Connection                 $database             Registry connection; publication and
     *          materialization both run through it.
     * @param   TableNames                 $tables               Prefixed physical names of the registry tables.
     * @param   string                     $mapFile              Absolute path of the local runtime map; the
     *          `.verified`, `.ready`, `.lock` and temporary
     *          sidecars live beside it.
     * @param   string                     $extensionRoot        Absolute root deployed extension trees live under.
     * @param   string                     $publicAssetRoot      Absolute root published extension assets live under.
     * @param   ClockInterface             $clock                Clock for lease, retention and readiness stamps.
     * @param   RuntimeIdentity            $identity             Identity of this process, supplying its lease key.
     * @param   RuntimePublicationKeyRing  $keys                 Key ring that signs new publications and verifies
     *          those signed with a still-accepted retired key.
     * @param   RuntimeArtifactDigester    $artifacts            Digester that reduces a deployed tree to a checksum.
     * @param   int                        $retentionSeconds     How long a retired tree is kept before collection,
     *          and how old an unknown tree must be to count as
     *          orphaned.
     * @param   int                        $replicaLeaseSeconds  How long a replica's claim on a generation stays
     *          valid without a heartbeat.
     * @param   ?LoggerInterface           $logger               Log the lease writer reports absorbed peer
     *          conflicts on; null leaves them unreported.
     *
     * @throws  InvalidArgumentException  When either the retention or the replica lease window is below one second.
     *
     * @since   2.0.0
     */
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
        private ?LoggerInterface $logger = null,
    ) {
        if (min($retentionSeconds, $replicaLeaseSeconds) < 1) {
            throw new InvalidArgumentException('Runtime publication trust and retention settings are invalid.');
        }
        $this->leases = new RuntimeLeaseWriter($database, $tables, $logger);
    }

    /**
     * Persist an immutable runtime publication inside the caller's registry transaction.
     *
     * The caller owns the transaction so that the registry change and the publication that describes it
     * commit together. Locking the generation row first serializes competing publishers, which is what
     * lets `stageDocument()` claim the next generation with a compare-and-set.
     *
     * @param   string  $action  Short label recorded with the publication saying what caused it, such as
     *          `extension.install`; at most 127 characters.
     *
     * @return  int  The generation just published.
     *
     * @throws  RuntimeException  When no transaction is active, the current publication is missing
     *          or fails verification, or the registry moved under the staging.
     * @throws  InvalidArgumentException  When the action label is empty or too long.
     *
     * @since   2.0.0
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
     *
     * Recovery runs precisely when the deployed administrator theme can no longer be trusted, so the
     * usual artifact check on the outgoing publication would refuse to proceed. Instead the prior
     * document is verified by signature alone and the *shape* of the change is constrained: only the
     * named theme's administrator surface may disappear. The incoming entries are still artifact
     * checked, so recovery cannot be used to publish over an unrelated tampered tree.
     *
     * @param   string  $action      Label recorded with the publication, such as `theme.administrator.recover`.
     * @param   string  $identifier  Identifier of the administrator theme being withdrawn.
     *
     * @return  int  The generation just published.
     *
     * @throws  RuntimeException  When no recovery transaction is active, the signed publication
     *          needed as a baseline is missing, the transition touches anything
     *          beyond that theme's administrator surface, or the incoming
     *          artifacts do not match the deployed bytes.
     * @throws  InvalidArgumentException  When the action label is empty or too long.
     *
     * @since   2.0.0
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
     *
     * A publisher that died between advancing the generation and writing its document, a migration that
     * changed the registry without publishing, or a completed key rotation all leave the stored
     * publication disagreeing with the registry. This republishes in those cases and only in those
     * cases — a publication that verifies and still matches the state checksum and active key is left
     * alone rather than re-signed. A publication that exists but fails verification is security drift,
     * so it is raised rather than quietly overwritten. Losing the compare-and-set race is retried twice
     * before the failure is propagated.
     *
     * @param   bool  $acknowledgeLoaded  Whether to claim this replica's lease on the generation it ends
     *          up serving; false for callers that only converge local disk.
     * @param   bool  $publishReadiness   Whether to refresh the `.ready` marker the HTTP readiness probe
     *          serves; false when the caller publishes readiness itself after
     *          a further authority check.
     *
     * @return  RuntimeMaterializationState  The generation now on local disk, carrying its verified
     *          publication document.
     *
     * @throws  RuntimeException  When reconciliation still loses the generation race after three
     *          attempts, or the authoritative publication cannot be verified or
     *          materialized.
     *
     * @since   2.0.0
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

    /**
     * Bring local disk back in step with database authority without claiming a lease on the result.
     *
     * Suited to maintenance callers that want the map on disk refreshed but are not themselves going to
     * serve requests from it, so no materialization row should be held open on their behalf.
     *
     * @return  int  The generation now on local disk.
     *
     * @throws  RuntimeException  When reconciliation or materialization fails.
     *
     * @since   2.0.0
     */
    public function rebuild(): int
    {
        return $this->reconcileAndMaterialize(false)->generation;
    }

    /**
     * Publish a new generation because something invalidated the current runtime.
     *
     * This is the `TrustRuntimeInvalidator` entry point trust and lifecycle code calls. It joins the
     * caller's transaction when there is one so the invalidation commits with the change that caused it,
     * and opens its own transaction otherwise.
     *
     * @param   string   $reason               Why the runtime is being invalidated, such as `trust.revoke`.
     * @param   ?string  $extensionIdentifier  Extension the invalidation is attributed to, appended to the
     *          reason as `reason:identifier`; null for a registry-wide change.
     *
     * @return  int  The generation just published.
     *
     * @throws  RuntimeException  When the publication cannot be staged.
     * @throws  InvalidArgumentException  When the combined action label is empty or too long.
     *
     * @since   2.0.0
     */
    public function advance(string $reason, ?string $extensionIdentifier = null): int
    {
        $action = $extensionIdentifier === null ? $reason : $reason . ':' . $extensionIdentifier;
        if ($this->database->isTransactionActive()) {
            return $this->stage($action);
        }

        return $this->database->transactional(fn (): int => $this->stage($action));
    }

    /**
     * Make the authoritative generation the one this replica serves.
     *
     * Inside a transaction the local write is deliberately skipped: the staged generation is not
     * durable yet, and writing it to disk before the commit would leave the replica serving a
     * publication that a rollback erases. The caller is expected to call again after committing.
     *
     * @return  int  The generation in force; only materialized locally when called outside a transaction.
     *
     * @throws  RuntimeException  When the generation counter is unreadable, or materialization fails.
     *
     * @since   2.0.0
     */
    public function materialize(): int
    {
        if ($this->database->isTransactionActive()) {
            return $this->currentGeneration();
        }

        return $this->reconcileAndMaterialize()->generation;
    }

    /**
     * Throw away this replica's copy of the runtime map, its verification marker and its readiness marker.
     *
     * Leaves the database untouched, so the next materialization rebuilds local state from authority.
     * This is the repair path for a replica whose local files are corrupt or were written by a build
     * that is no longer trusted.
     *
     * @return  void
     *
     * @throws  RuntimeException  When one of the three paths is a symbolic link or not a regular file, or
     *          cannot be removed.
     *
     * @since   2.0.0
     */
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

    /**
     * Verify the authoritative publication and write it to this replica's disk under an exclusive lock.
     *
     * The write is guarded on both sides. The lock file is re-checked by inode after opening, after the
     * lock is taken and after its mode is tightened, so a swapped or hard-linked lock cannot redirect
     * the writer. Local state is then compared with authority: a newer local generation is never
     * replaced, and a local generation equal to authority but with a different publication checksum is
     * a conflict rather than something to overwrite. The map itself is written to a randomly named
     * temporary file and renamed into place, so a reader never sees a half-written map.
     *
     * @param   bool  $acknowledgeLoaded  Whether to record this replica's lease on the loaded generation.
     * @param   bool  $publishReadiness   Whether to refresh the `.ready` marker as part of the write.
     *
     * @return  RuntimeMaterializationState  Trusted state describing the generation now on local disk.
     *
     * @throws  RuntimeException  When the authoritative publication is missing or fails verification,
     *          the cache directory, lock file or map path is unsafe, local disk holds
     *          a newer or conflicting generation, or the map cannot be written and
     *          renamed into place.
     *
     * @since   2.0.0
     */
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
            if (!chmod($lockPath, 0600)) {
                throw new RuntimeException('The extension runtime map lock permissions could not be secured.');
            }
            $this->assertOpenLockFile($lock, $lockPath);
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

    /**
     * Refresh the signed readiness marker for the generation this process actually loaded.
     *
     * The marker is what `LocalRuntimeReadinessProbe` serves to the load balancer, so it is written only
     * after the supplied state is confirmed to still match the map on local disk. That is what stops a
     * watcher from advertising a replica as ready for a generation it has since drifted away from.
     *
     * @param   RuntimeMaterializationState  $state  Generation this process verified and is serving.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the state is untrusted, carries no publication, or disagrees with
     *          the generation or publication checksum found on local disk.
     *
     * @since   2.0.0
     */
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

    /**
     * Read and verify the runtime publication currently on this replica's disk.
     *
     * Every failure — absent files, unreadable JSON, a marker that does not sign the map bytes that were
     * read, a signature from an unavailable key — is reported as an unavailable state rather than an
     * exception, because callers use this to decide whether local state needs rewriting. Artifact
     * digests are deliberately not re-checked here; that cost belongs to materialization. The verified
     * document is memoised in APCu under a key derived from the map and marker inode metadata and the
     * key ring identity, so replacing either file or rotating keys misses the cache instead of serving a
     * stale verification.
     *
     * @return  RuntimeMaterializationState  Trusted state with the verified publication, or the
     *          unavailable state when local disk holds nothing usable.
     *
     * @since   2.0.0
     */
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
                    /** @var array<string, mixed> $cached */
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
            /** @var array<string, mixed> $document */
            /** @var array<string, mixed> $marker */
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

    /**
     * Report whether the signed readiness marker is recent and describes the loaded local generation.
     *
     * This is the cheap readiness answer: it touches only the two local files and never the registry, so
     * it can be polled at load-balancer frequency. Freshness is what makes it meaningful — a replica
     * whose watcher stopped converging keeps a valid but ageing marker and drops out of rotation once it
     * passes the window.
     *
     * @param   int  $maximumAgeSeconds  How long after the marker was written it still counts as fresh.
     *
     * @return  bool  True only when the marker verifies against the key ring, names the generation and
     *          publication checksum found on local disk, and is within the age window.
     *
     * @throws  InvalidArgumentException  When the maximum age is not positive.
     *
     * @since   2.0.0
     */
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
            /** @var array<string, mixed> $marker */
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

    /**
     * Decide whether the generation this process loaded is still authoritative, renewing its lease if so.
     *
     * Unlike `matchesAuthority()` this has a side effect: a positive answer heartbeats the replica's
     * materialization row. That is deliberate, because the callers are long-lived workers whose lease
     * would otherwise expire and let a retirement pass delete the tree they are still running from.
     *
     * @param   RuntimeMaterializationState  $loaded  State captured when this process loaded the map.
     *
     * @return  bool  True when the loaded generation still agrees with local disk and the registry.
     *
     * @throws  RuntimeException  When the registry cannot be read while establishing the comparison.
     *
     * @since   2.0.0
     */
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

    /**
     * Compare the loaded generation against local disk and against the registry, without touching leases.
     *
     * Four things have to line up: the loaded document verifies on its own, local disk carries the same
     * generation and checksums, the stored publication for the current generation matches it, and the
     * registry still hashes to that publication's state checksum. The last check is what catches a
     * registry mutation that was never published, which the first three would happily agree about.
     *
     * @param   RuntimeMaterializationState  $loaded  State captured when this process loaded the map.
     *
     * @return  bool  True only when all four agree; a signature or checksum that fails to verify reads as
     *          false, so callers can treat drift as a condition rather than an error.
     *
     * @throws  RuntimeException  When the registry itself cannot be read — an unreadable generation
     *          counter, malformed publication metadata, or incomplete extension rows.
     *
     * @since   2.0.0
     */
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

    /**
     * Stop a long-running process that is still serving a superseded or untrusted runtime generation.
     *
     * Queue workers and the scheduler call this between units of work: a worker started before an
     * extension was revoked would otherwise keep executing its code for the rest of its lifetime. The
     * process is expected to exit and be restarted onto the current generation.
     *
     * @param   RuntimeMaterializationState  $loaded  State captured when this process loaded the map.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the loaded generation is stale, untrusted, or no longer matches
     *          local disk and the registry.
     *
     * @since   2.0.0
     */
    public function assertLoadedGenerationCurrent(RuntimeMaterializationState $loaded): void
    {
        if (!$this->isCurrent($loaded)) {
            throw new RuntimeException('This process loaded a stale or untrusted extension runtime generation.');
        }
    }

    /**
     * Record that a deployed extension tree may be deleted once no replica still serves an older generation.
     *
     * Deletion cannot happen at uninstall time, because replicas that loaded an earlier generation are
     * still executing code from that directory. The retirement row is what holds the tree until the
     * retention window has passed and every lease below the given generation has expired. Staging is
     * idempotent on the path digest, and a digest that resolves to a different stored path is treated as
     * a collision rather than silently reused.
     *
     * @param   string  $runtimePath  Storage-relative `vendor/name/version` path of the tree being retired.
     * @param   int     $generation   Generation from which the tree is unreferenced; a live lease below it
     *          blocks collection.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no registry transaction is active, or a path digest
     *          collision is detected.
     * @throws  InvalidArgumentException  When the generation is below one or the path is not a safe
     *          three-segment relative runtime path.
     *
     * @since   2.0.0
     */
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

    /**
     * Withdraw a pending retirement because the same tree is in use again.
     *
     * Reinstalling the version that was just uninstalled reuses the identical runtime path, so the
     * queued retirement has to be dropped in the same transaction or the collector would later delete a
     * live deployment.
     *
     * @param   string  $runtimePath  Storage-relative `vendor/name/version` path whose retirement is dropped.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no registry transaction is active, or the path is not a safe
     *          three-segment relative runtime path.
     *
     * @since   2.0.0
     */
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

    /**
     * Sign the next publication document and insert it together with the generation it claims.
     *
     * The generation row is advanced with a compare-and-set against the value that was read, so two
     * publishers cannot both mint the same generation — the loser sees no affected row and is told to
     * retry its whole mutation. After the insert the registry is hashed again and compared with the
     * checksum that was signed, which catches a registry write that slipped in while the document was
     * being assembled.
     *
     * @param   string                      $action                Label recorded with the publication.
     * @param   list<array<string, mixed>>  $extensions            Compiled runtime entries to sign into
     *          the document.
     * @param   bool                        $requireCurrent        Whether a missing current publication
     *          is a failure rather than something to
     *          publish over; false during reconciliation,
     *          which exists to repair that case.
     * @param   bool                        $currentSignatureOnly  Whether to verify the outgoing publication
     *          by signature alone, skipping its deployed
     *          artifact digests; set by administrator
     *          recovery, where those bytes are known bad.
     *
     * @return  int  The generation just published.
     *
     * @throws  InvalidArgumentException  When the action label is empty or longer than 127 characters.
     * @throws  RuntimeException  When the current publication is missing or inconsistent, another
     *          publisher advanced the generation first, or the registry changed
     *          while the document was being staged.
     *
     * @since   2.0.0
     */
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

    /**
     * Compile the active extension set into the ordered entries a publication is signed over.
     *
     * Rows are read in identifier order and every entry re-digests the deployed extension tree and the
     * published asset tree, so the state checksum taken from this is a statement about bytes on disk and
     * not only about registry contents. An active extension whose metadata is incomplete or whose runtime
     * path is unsafe stops compilation rather than being skipped, because a silently shortened list would
     * still sign cleanly.
     *
     * @return  list<array<string, mixed>>  One entry per active extension, in identifier order.
     *
     * @throws  RuntimeException  When an active extension carries incomplete or unsafe runtime metadata,
     *          a persisted theme activation is invalid, or a deployed tree cannot be digested.
     * @throws  InvalidArgumentException  When the manifest stored for an active extension is not a valid
     *          extension manifest.
     *
     * @since   2.0.0
     */
    private function runtimeState(): array
    {
        $themes = $this->themeAssignments();
        $releaseJoin = $this->database->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'CAST(r.extension_id AS VARCHAR) = CAST(e.id AS VARCHAR)'
            : 'r.extension_id = e.id';
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, e.installed_version, e.service_provider, e.extension_type, e.runtime_path, '
            . 'r.manifest, r.package_sha256, r.signing_key_id, r.artifact_sha256, r.deployed_tree_sha256 '
            . 'FROM %s e INNER JOIN %s r ON %s AND r.version = e.installed_version '
            . "WHERE e.status = 'active' ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
            $releaseJoin,
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
                'manifest_schema' => $manifest->schemaVersion(),
                'contributions' => $manifest->contributions()->toArray(),
                'theme_surfaces' => $themes[$identifier]['surfaces'] ?? [],
                'theme_sites' => $themes[$identifier]['sites'] ?? [],
            ];
        }

        return $extensions;
    }

    /**
     * Index theme activations by extension identifier, per administrative surface and per site.
     *
     * Both activation tables are read in a fixed order so the compiled entries encode identically on
     * every host. A per-site activation also implies the `site` surface, which is added once, so a theme
     * activated only for individual sites still compiles with a surface the request path matches on.
     *
     * @return  array<string, array{surfaces: list<string>, sites: list<string>}>  Keyed by extension
     *          identifier; extensions with no activation are absent.
     *
     * @throws  RuntimeException  When a persisted theme or site theme activation does not carry an
     *          identifier and a surface as strings.
     *
     * @since   2.0.0
     */
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

    /**
     * Read the generation counter that names the authoritative publication.
     *
     * This is the unlocked read used outside publication; `lockGeneration()` is the one that serializes
     * competing publishers.
     *
     * @return  int  The generation in force, or zero before anything has been published.
     *
     * @throws  RuntimeException  When the singleton counter row is missing or does not hold a
     *          non-negative integer.
     *
     * @since   2.0.0
     */
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

    /**
     * Take the row lock on the generation counter that serializes competing publishers.
     *
     * The row is selected `FOR UPDATE` everywhere except SQLite, whose write lock already excludes a
     * second publisher. Holding the lock for the rest of the caller's transaction is what turns the
     * compare-and-set in `stageDocument()` into a decision rather than a race.
     *
     * @return  int  The generation in force at the moment the lock was taken.
     *
     * @throws  RuntimeException  When no transaction is active, or the counter row is missing, not an
     *          integer, or negative.
     *
     * @since   2.0.0
     */
    private function lockGeneration(): int
    {
        if (!$this->database->isTransactionActive()) {
            throw new RuntimeException('The runtime generation may be locked only inside a transaction.');
        }
        $suffix = $this->database->getDatabasePlatform() instanceof SQLitePlatform
            ? ''
            : ' FOR UPDATE';
        $result = $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1%s',
            $this->tables->quoted('extension_runtime_generation'),
            $suffix,
        ));
        $generation = $this->databaseInteger($result, 'extension runtime generation');
        if ($generation < 0) {
            throw new RuntimeException('The extension runtime generation is invalid.');
        }

        return $generation;
    }

    /**
     * Load the stored publication row for one generation.
     *
     * @param   int  $generation  Generation whose signed payload and indexed metadata are wanted.
     *
     * @return  array<string, mixed>|null  The publication row, or null when that generation was never
     *          written or has since been trimmed from history.
     *
     * @since   2.0.0
     */
    private function publication(int $generation): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT generation, state_sha256, publication_sha256, trust_hmac, signing_key_id, action, payload '
            . 'FROM %s WHERE generation = ?',
            $this->tables->quoted('extension_runtime_publications'),
        ), [$generation]);

        return $row === false ? null : $row;
    }

    /**
     * Verify a stored publication and return the signed document it carries.
     *
     * Two things have to hold: the payload verifies on its own terms, and the columns the registry
     * indexes on — generation, state checksum, action, signing key, publication checksum and HMAC — still
     * agree with the document they were derived from. A row edited in place therefore fails here even
     * when the payload it still carries would verify by itself.
     *
     * @param   array<string, mixed>  $publication      Publication row as fetched from the registry.
     * @param   bool                  $assertArtifacts  Whether the deployed bytes behind each entry must
     *          still match their recorded digests; false only where those bytes are known to be damaged,
     *          as in administrator recovery.
     *
     * @return  array<string, mixed>  The verified publication document.
     *
     * @throws  RuntimeException  When the payload is not a JSON object, fails signature or checksum
     *          verification, or disagrees with the row's own columns.
     * @throws  \JsonException  When the stored payload column holds text that is not valid JSON.
     *
     * @since   2.0.0
     */
    private function verifiedDocument(array $publication, bool $assertArtifacts = true): array
    {
        $payload = $publication['payload'] ?? null;
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('The authoritative runtime publication payload is invalid.');
        }
        /** @var array<string, mixed> $payload */
        $this->verifyDocument($payload, $assertArtifacts);
        $publicationGeneration = $this->databaseInteger(
            $publication['generation'] ?? null,
            'runtime publication generation',
        );
        if (
            $publicationGeneration !== ($payload['generation'] ?? null)
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

    /**
     * Verify a publication document against the key ring, its own checksums and the deployed bytes.
     *
     * The signature is recomputed over the canonical encoding of the document's own fields rather than
     * over the bytes it arrived in, so a re-encoded copy still verifies while an altered one does not.
     * Recomputing the state checksum from the entries is what ties the document to the registry snapshot
     * it claims to describe.
     *
     * @param   array<string, mixed>  $document         Decoded publication document to verify.
     * @param   bool                  $assertArtifacts  Whether each entry's runtime and asset digests
     *          must still match the trees deployed on this host.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the document is not a well-formed `kumwe-extension-map-v3`
     *          publication, a required field is missing or empty, the signature is wrong or names a key
     *          the ring does not hold, or a checksum disagrees.
     *
     * @since   2.0.0
     */
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

    /**
     * Require that every entry's recorded digests still match the trees deployed on this host.
     *
     * This is what extends publication trust from the signed document to the filesystem: an extension
     * tree edited after publication, or a published asset tree that was tampered with, fails here even
     * though the document's own signature is intact.
     *
     * @param   list<mixed>  $extensions  Entries taken from a publication document, before their shape
     *          has been narrowed.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an entry is not a keyed object, its root is not a safe relative
     *          runtime path, or a runtime or asset digest disagrees with the deployed bytes.
     *
     * @since   2.0.0
     */
    private function assertArtifacts(array $extensions): void
    {
        foreach ($extensions as $extension) {
            if (!is_array($extension) || array_is_list($extension)) {
                throw new RuntimeException('A runtime publication extension is invalid.');
            }
            /** @var array<string, mixed> $extension */
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

    /**
     * Claim or renew this replica's lease row for the generation it has just materialized.
     *
     * One upsert does the whole claim. The row is keyed by lease identity rather than by process, so the
     * peers that share this identity — the other php-fpm children in this container, its health check and
     * any operator command run inside it — write the same row; an update followed by an insert followed
     * by an update handed each of them two windows to land between the statements. The lease written
     * here is what pins a retired extension tree on disk while this replica may still be executing code
     * from it, so `RuntimeLeaseWriter` gives up only to a peer that has just written the row itself.
     *
     * @param   RuntimeMaterializationState  $state  Generation this replica now holds, with the
     *          checksums it verified.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function acknowledge(RuntimeMaterializationState $state): void
    {
        $now = $this->clock->now();
        $this->leases->renew(
            $state->replicaId,
            $this->identity,
            $state->generation,
            $state->publicationChecksum,
            $state->trustHmac,
            $now,
            $now->add(new DateInterval('PT' . $this->replicaLeaseSeconds . 'S')),
        );
    }

    /**
     * Extend this replica's lease, but only while its row still describes the generation it loaded.
     *
     * The update is conditional on the generation and both checksums, so a row that has moved on is not
     * kept alive by a process serving something else. When nothing matched, `acknowledge()` rewrites the
     * row to what this process is actually holding.
     *
     * A caller's open transaction suppresses the write entirely. Two things are wrong with renewing a
     * lease inside one: the renewal would be rolled back with whatever the caller was doing, leaving a
     * lease that reads as renewed to this process but was never committed; and the write would carry the
     * caller's read view, which under MariaDB's snapshot isolation fails outright against a row a peer
     * committed after that view opened, turning a peer's lease renewal into the caller's error. The
     * verification the caller asked for still runs in full — only the bookkeeping write is left to the
     * calls this same process makes outside a transaction, which every worker, dispatcher and probe does
     * before it opens one.
     *
     * @param   RuntimeMaterializationState  $state  Generation this process loaded and is still serving.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function heartbeat(RuntimeMaterializationState $state): void
    {
        if ($this->database->isTransactionActive()) {
            return;
        }
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

    /**
     * Delete retired extension trees whose retention window has passed and that nothing can still run.
     *
     * Each candidate is claimed for sixty seconds under a random token so two replicas never delete the
     * same tree at once, and the outcome differs by reason: a tree that is referenced again, or that has
     * an install operation with an unknown outcome against it, has its retirement withdrawn outright,
     * while one still pinned by a live lease on an older generation only has its claim released so a
     * later pass reconsiders it. Deletion is verified afterwards, and any failure releases the claim
     * before propagating, so a crashed pass leaves work rather than a permanently claimed row. At most a
     * hundred retirements are handled per pass.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a retirement row is malformed, a tree reappears during verified
     *          deletion, the claim is lost mid-deletion, or a tree cannot be removed safely.
     *
     * @since   2.0.0
     */
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
            if (!is_string($id) || !is_string($runtimePath)) {
                throw new RuntimeException('A runtime retirement record is invalid.');
            }
            $retireAfterGeneration = $this->databaseInteger($generation, 'retirement generation');
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
            $referenced = $this->databaseInteger($this->database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE runtime_path = ?',
                $this->tables->quoted('extensions'),
            ), [$runtimePath]), 'runtime reference count');
            $pending = $this->databaseInteger($this->database->fetchOne(sprintf(
                "SELECT COUNT(*) FROM %s WHERE runtime_path = ? AND transaction_outcome = 'unknown'",
                $this->tables->quoted('extension_install_operations'),
            ), [$runtimePath]), 'pending runtime operation count');
            if ($referenced !== 0 || $pending !== 0) {
                $this->database->delete($this->tables->raw('extension_runtime_retirements'), [
                    'id' => $id,
                    'claim_token' => $claim,
                ]);
                continue;
            }
            $blockers = $this->databaseInteger($this->database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE lease_until >= ? AND generation < ?',
                $this->tables->quoted('extension_runtime_materializations'),
            ), [$now, $retireAfterGeneration], [Types::DATETIME_IMMUTABLE, Types::BIGINT]), 'runtime lease count');
            if ($blockers !== 0) {
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

    /**
     * Give a retirement claim back so a later collection pass can reconsider the row.
     *
     * The update is conditional on the token this pass wrote, so a claim that has already expired and
     * been taken by another replica is left where it is.
     *
     * @param   string  $id     Identifier of the retirement row to unclaim.
     * @param   string  $claim  Claim token this pass wrote when it took the row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function releaseRetirementClaim(string $id, string $claim): void
    {
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET claim_token = NULL, claim_until = NULL WHERE id = ? AND claim_token = ?',
            $this->tables->quoted('extension_runtime_retirements'),
        ), [$id, $claim], [Types::GUID, Types::STRING]);
    }

    /**
     * Re-queue retirements whose tree is on disk again after having been collected.
     *
     * A tree can come back from a restore, from a partially rolled back install, or from a replica
     * redeploying out of stale storage. Clearing `cleaned_at` and expiring the retention window
     * immediately puts it back in front of the collector instead of leaving unreferenced code deployed.
     * Only the hundred oldest cleaned rows are examined per pass.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Schedule retirement for deployed trees the registry no longer accounts for.
     *
     * An install that died after writing files but before committing leaves a `vendor/name/version`
     * directory nothing references. The walk is deliberately bounded — depth two, a thousand entries
     * inspected and a hundred candidates per pass — and skips a directory listed alike by the extension,
     * retirement and pending-operation tables, or modified inside the retention window. Nothing is
     * deleted here: collection re-checks references under a claim before any tree is removed, which is
     * what keeps a live deployment safe.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the generation counter cannot be read, or a retirement cannot be
     *          staged because its path digest collides with a different stored path.
     *
     * @since   2.0.0
     */
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
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
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

    /**
     * Trim runtime bookkeeping: expired leases, superseded publications and long-cleaned retirements.
     *
     * Publications are only removed below the oldest generation a live lease still names, so the history
     * a lagging replica may yet have to verify against survives; with no live lease the current
     * generation is that floor. Retirement records are kept for a week after collection as an audit
     * trail. Each of the three passes is capped at a hundred rows so a materialization never turns into
     * a long delete.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the generation counter cannot be read while deciding how far back
     *          publications may be trimmed.
     *
     * @since   2.0.0
     */
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

    /**
     * Remove a deployed tree, refusing any path that could reach outside the storage root it belongs to.
     *
     * The path is checked lexically against the root, then segment by segment for symbolic links, then
     * once more after `realpath()` resolution, so neither a crafted relative path nor a link swapped in
     * between those steps turns a retirement into a delete somewhere else. Files and links are unlinked
     * and directories removed depth first; an absent path is a no-op so a half-collected retirement can
     * be retried.
     *
     * @param   string  $directory    Absolute path of the tree to remove.
     * @param   string  $allowedRoot  Absolute storage root the tree must resolve beneath.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the path lies outside the allowed root, a path segment is a
     *          symbolic link, or the target is not a plain directory.
     *
     * @since   2.0.0
     */
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
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());
            } elseif ($item->isDir()) {
                rmdir($item->getPathname());
            }
        }
        rmdir($resolved);
    }

    /**
     * Require that an open lock handle still refers to the plain file the path names.
     *
     * Materialization calls this after opening the lock, after taking it and after tightening its mode.
     * Comparing device and inode between the open handle and the path, and insisting on a single link to
     * a regular file, is what stops a lock replaced or hard linked between those steps from letting a
     * second writer through.
     *
     * @param   resource  $handle  Open handle on the lock file.
     * @param   string    $path    Path the handle was opened from.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the handle is not a singly linked regular file, or no longer names
     *          the same inode as the path.
     *
     * @since   2.0.0
     */
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

    /**
     * Digest compiled runtime entries into the checksum a publication records the registry state under.
     *
     * Comparing this against a stored publication is how an unpublished registry mutation is detected,
     * so it has to be reproducible: the canonical encoding makes the same state hash identically wherever
     * it is compiled.
     *
     * @param   array<mixed>  $extensions  Compiled runtime entries to reduce.
     *
     * @return  string  Lowercase SHA-256 hex digest of the canonical encoding.
     *
     * @since   2.0.0
     */
    private function stateChecksum(array $extensions): string
    {
        return hash('sha256', $this->json($extensions));
    }

    /**
     * Encode a structure the one way every runtime digest and signature in this class is taken over.
     *
     * Signing and verification both route through here; encoding the same state any other way produces
     * different bytes and reads as tampering.
     *
     * @param   array<mixed>  $value  Structure to encode.
     *
     * @return  string  Canonical JSON, with string keys sorted and slashes and unicode left unescaped.
     *
     * @throws  InvalidArgumentException  When the structure holds something JSON cannot represent, such
     *          as malformed UTF-8 or a non-finite float.
     *
     * @since   2.0.0
     */
    private function json(array $value): string
    {
        return RuntimeCanonicalJson::encode($value);
    }

    /**
     * Build the signed `.verified` marker that binds a publication to the map bytes written beside it.
     *
     * The marker carries the digest of the exact payload written to disk, so a map file swapped under an
     * otherwise valid marker no longer matches it and `inspectLocal()` reports local state as unusable
     * instead of serving the substitute.
     *
     * @param   array<string, mixed>  $document    Verified publication document being materialized.
     * @param   string                $mapPayload  Exact bytes written to the runtime map file.
     *
     * @return  string  Pretty-printed marker JSON, signed with the active key.
     *
     * @since   2.0.0
     */
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

    /**
     * Verify a `.verified` marker against the key ring and against the map bytes it claims to cover.
     *
     * The digest comparison happens before the signature is checked, so a marker that verifies but
     * describes different bytes is still refused.
     *
     * @param   array<string, mixed>  $marker      Decoded marker read from beside the runtime map.
     * @param   string                $mapPayload  Bytes read from the runtime map file in the same pass.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the marker is not a well-formed verification marker, does not
     *          digest the bytes supplied, or carries a signature the ring cannot verify.
     *
     * @since   2.0.0
     */
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

    /**
     * Build the signed `.ready` marker the local readiness probe serves to the load balancer.
     *
     * Unlike the verification marker this one is stamped with the moment it was written, which is what
     * makes freshness checkable: a replica whose watcher stopped converging keeps a marker that still
     * verifies but ages out of the probe's window instead of advertising a generation it no longer
     * follows.
     *
     * @param   array<string, mixed>  $document  Verified publication document this replica is serving.
     *
     * @return  string  Pretty-printed readiness JSON, signed with the active key.
     *
     * @since   2.0.0
     */
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

    /**
     * Write a runtime sidecar through a temporary file and a rename, so a reader never sees it partial.
     *
     * An immutable write returns early when the file already holds these exact bytes, which keeps
     * repeated materializations from churning the verification marker; readiness passes false because its
     * timestamp has to advance on every pass.
     *
     * @param   string  $path       Absolute path of the sidecar to write.
     * @param   string  $payload    Complete contents to place there.
     * @param   bool    $immutable  Whether an existing file with identical contents may be left alone.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the target is a symbolic link or not a regular file, or the
     *          payload cannot be written completely and renamed into place.
     *
     * @since   2.0.0
     */
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

    /**
     * Wrap an already verified publication document as the state describing what this replica holds.
     *
     * The document is not re-verified here; callers reach this only after signature, marker and checksum
     * checks have passed, and the wrapped publication can prove itself again at the point of use.
     *
     * @param   array<string, mixed>  $document  Publication document that has already been verified.
     *
     * @return  RuntimeMaterializationState  Trusted state carrying this process's lease identity.
     *
     * @throws  RuntimeException  When the document carries no usable generation, publication checksum or
     *          trust HMAC.
     *
     * @since   2.0.0
     */
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

    /**
     * Derive the APCu key a verified local publication may be memoised under.
     *
     * The key mixes the map path, the key ring's identity and the device, inode, size and timestamps of
     * both local files, so replacing either file or rotating keys misses the cache rather than serving a
     * verification that no longer holds.
     *
     * @return  ?string  The cache key, or null when either local file cannot be stat'ed and memoising
     *          would therefore not be invalidated by a replacement.
     *
     * @since   2.0.0
     */
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

    /**
     * Require that a recovery publication withdraws one administrator surface and changes nothing else.
     *
     * Recovery is the one path that publishes without trusting the bytes of the outgoing publication, so
     * the shape of the transition carries the safety instead: every unrelated entry has to be identical
     * under canonical encoding, no entry may be added, and the named theme may only lose its
     * `administrator` surface — disappearing altogether when that was the only surface it served.
     *
     * @param   array<string, mixed>        $prior       Verified document the recovery starts from.
     * @param   list<array<string, mixed>>  $next        Compiled entries the recovery proposes to
     *          publish.
     * @param   string                      $identifier  Administrator theme being withdrawn.
     *
     * @return  void
     *
     * @throws  RuntimeException  When either entry list is malformed, the prior entry for the named
     *          theme carries no administrator surface, or the transition adds, removes or edits anything
     *          beyond that theme's administrator surface.
     *
     * @since   2.0.0
     */
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

    /**
     * Index a publication's entry list by extension identifier so two publications can be compared.
     *
     * Duplicate identifiers are refused rather than collapsed, because a comparison over a silently
     * shortened index would let a second entry for the same extension pass unexamined.
     *
     * @param   mixed  $extensions  Value taken from a publication document, expected to be a list of
     *          entry objects.
     *
     * @return  array<string, array<string, mixed>>  Entries keyed by extension identifier.
     *
     * @throws  RuntimeException  When the value is not a list of keyed entries, an entry carries no
     *          identifier, or two entries share one.
     *
     * @since   2.0.0
     */
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
            /** @var array<string, mixed> $extension */
            $identifier = $this->requiredString($extension, 'identifier');
            if (isset($indexed[$identifier])) {
                throw new RuntimeException('The signed runtime extension list contains duplicates.');
            }
            $indexed[$identifier] = $extension;
        }

        return $indexed;
    }

    /**
     * Decide whether a path is a storage-relative `vendor/name/version` runtime path.
     *
     * Every path this class hands to the filesystem — retirement, collection, artifact digesting — is
     * screened here first, so the accepted shape is the boundary that keeps absolute paths and parent
     * traversal out of extension storage.
     *
     * @param   string  $runtimePath  Candidate path, relative to the extension or asset storage root.
     *
     * @return  bool  True when the path is exactly three safe segments and contains no parent reference.
     *
     * @since   2.0.0
     */
    private function safeRelativeRuntime(string $runtimePath): bool
    {
        return preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+$#D', $runtimePath) === 1
            && !str_contains($runtimePath, '..');
    }

    /**
     * Fold a fetched column into a set the orphan scan can test membership against.
     *
     * Non-string values are dropped rather than coerced, so a null `runtime_path` cannot become a key
     * that a directory name happens to match.
     *
     * @param   list<mixed>  $values  Column values as returned by the driver.
     *
     * @return  array<string, true>  Every string value as a key.
     *
     * @since   2.0.0
     */
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

    /**
     * Narrow a value read from the registry to an integer, refusing anything that is not one.
     *
     * Drivers return counters and aggregates as either integers or digit strings depending on platform
     * and column type, so both are accepted; anything else is treated as corrupt registry data rather
     * than cast to zero and acted on.
     *
     * @param   mixed   $value        Value as returned by the driver.
     * @param   string  $description  What the value is, interpolated into the failure message.
     *
     * @return  int  The value as an integer.
     *
     * @throws  RuntimeException  When the value is neither an integer nor a string of digits.
     *
     * @since   2.0.0
     */
    private function databaseInteger(mixed $value, string $description): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('The ' . $description . ' is invalid.');
        }

        return (int) $value;
    }

    /**
     * Read a field a publication row, document or entry must carry as a non-empty string.
     *
     * The checksum, HMAC and key identifier fields this class compares are read through here, so a field
     * that is absent or blank fails loudly instead of being compared as an empty string against another
     * empty string.
     *
     * @param   array<string, mixed>  $row    Publication row, document or entry to read from.
     * @param   string                $field  Key whose value is required.
     *
     * @return  string  The field's value.
     *
     * @throws  RuntimeException  When the field is absent, is not a string, or is empty.
     *
     * @since   2.0.0
     */
    private function requiredString(array $row, string $field): string
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Runtime publication field %s is invalid.', $field));
        }

        return $value;
    }
}
