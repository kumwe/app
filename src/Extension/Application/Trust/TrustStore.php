<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Audited, serialized application boundary for extension signing-key trust. */
final readonly class TrustStore
{
    public function __construct(
        private TrustStoreRepository $repository,
        private TrustKeySignatureVerifier $verifier,
        private ExtensionArtifactVerifier $artifacts,
        private TrustRuntimeInvalidator $runtime,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
        private bool $allowUnsignedLocalPackages = false,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function keys(ExecutionContext $context): array
    {
        $this->authorize($context, AuthorizationResource::collection('extension_trust_key'));
        return array_map(function (array $key): array {
            $keyId = $key['key_id'] ?? null;
            $key['affected_extensions'] = is_string($keyId)
                ? $this->repository->extensionsRequiringKey($keyId)
                : [];
            return $key;
        }, $this->repository->all());
    }

    public function lifecycleReady(): bool
    {
        return $this->repository->lifecycleReady();
    }

    public function synchronizeRuntimeMaterialization(): int
    {
        return $this->runtime->materialize();
    }

    public function repairRuntimeMaterialization(): int
    {
        $this->runtime->discardLocal();
        return $this->runtime->materialize();
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function synchronizedLifecycle(callable $operation): mixed
    {
        return $this->repository->synchronizedLifecycle($operation);
    }

    /** @param array<string, mixed> $release */
    public function assertArtifactIntegrity(array $release): void
    {
        $this->artifacts->assertMatches($release);
    }

    /** Acquires the common trust-generation fence inside the caller's transaction. */
    public function acquireLifecycleFence(): int
    {
        return $this->repository->lockGeneration();
    }

    /** @return list<string> */
    public function activeRuntimeIdentifiers(): array
    {
        return $this->transactions->transactional(function (): array {
            $this->repository->lockGeneration();
            $identifiers = $this->repository->activeExtensions();
            sort($identifiers, SORT_STRING);
            return $identifiers;
        });
    }

    public function add(
        ExecutionContext $context,
        string $keyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        DateTimeImmutable $expiresAt,
        ?string $rotatedFrom = null,
    ): void {
        $this->authorize($context, AuthorizationResource::collection('extension_trust_key'));
        $values = $this->keyValues(
            $context,
            $keyId,
            $publicKeyBase64,
            $vendorNamespace,
            $extensionPattern,
            $expiresAt,
            $rotatedFrom,
        );
        $this->repository->synchronizedLifecycle(function () use ($context, $values): void {
            $this->transactions->transactional(function () use ($context, $values): void {
                $this->repository->lockGeneration();
                $this->repository->add($values);
                $this->repository->advanceGeneration($this->clock->now());
                $this->record($context, 'extension.trust_key.add', $values['key_id'], [
                    'vendor_namespace' => $values['vendor_namespace'],
                    'extension_pattern' => $values['extension_pattern'],
                    'expires_at' => $values['expires_at']->format(DATE_ATOM),
                    'rotated_from' => $values['rotated_from'],
                ]);
            });
        });
    }

    /** Begins a two-phase rotation. The old key remains trusted during overlap. */
    public function rotate(
        ExecutionContext $context,
        string $oldKeyId,
        string $newKeyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        DateTimeImmutable $expiresAt,
    ): void {
        $this->authorize($context, AuthorizationResource::item('extension_trust_key', $oldKeyId));
        $oldKeyId = $this->keyId($oldKeyId);
        $values = $this->keyValues(
            $context,
            $newKeyId,
            $publicKeyBase64,
            $vendorNamespace,
            $extensionPattern,
            $expiresAt,
            $oldKeyId,
        );
        $this->repository->synchronizedLifecycle(function () use ($context, $oldKeyId, $values): void {
            $this->transactions->transactional(function () use ($context, $oldKeyId, $values): void {
                $this->repository->lockGeneration();
                $old = $this->activeKey($oldKeyId);
                if ($old === null) {
                    throw new InvalidArgumentException('The active trust key to rotate does not exist.');
                }
                if (
                    ($old['vendor_namespace'] ?? null) !== $values['vendor_namespace']
                    || ($old['extension_pattern'] ?? null) !== $values['extension_pattern']
                ) {
                    throw new InvalidArgumentException(
                        'A replacement key must preserve the old key namespace constraints.',
                    );
                }
                $this->repository->add($values);
                $this->repository->advanceGeneration($this->clock->now());
                $this->record($context, 'extension.trust_key.rotate.begin', $oldKeyId, [
                    'replacement_key_id' => $values['key_id'],
                    'affected_extensions' => $this->repository->extensionsRequiringKey($oldKeyId),
                ]);
            });
        });
    }

    public function finalizeRotation(ExecutionContext $context, string $oldKeyId, string $reason): void
    {
        $this->revoke($context, $oldKeyId, $reason, 'extension.trust_key.rotate.finalize');
    }

    public function revoke(
        ExecutionContext $context,
        string $keyId,
        string $reason,
        string $auditAction = 'extension.trust_key.revoke',
    ): void {
        $this->authorize($context, AuthorizationResource::item('extension_trust_key', $keyId));
        $keyId = $this->keyId($keyId);
        $reason = $this->reason($reason);
        $this->repository->synchronizedLifecycle(function () use ($context, $keyId, $reason, $auditAction): void {
            $this->transactions->transactional(function () use ($context, $keyId, $reason, $auditAction): void {
                $this->repository->lockGeneration();
                $affected = $this->repository->extensionsRequiringKey($keyId);
                if ($affected !== []) {
                    throw new InvalidArgumentException(
                        'The trust key cannot be revoked until affected releases are upgraded or quarantined: '
                        . implode(', ', $affected),
                    );
                }
                $now = $this->clock->now();
                $this->repository->revoke($keyId, $context->actorId(), $reason, $now);
                $this->repository->advanceGeneration($now);
                $this->runtime->advance($auditAction, $keyId);
                $this->record($context, $auditAction, $keyId, ['reason' => $reason]);
                $this->transactions->afterCommit(function (): void {
                    $this->materializeBestEffort();
                });
            });
        });
    }

    /**
     * Immediately revokes a key and quarantines every active release signed by it.
     * @return list<string>
     */
    public function emergencyRevoke(ExecutionContext $context, string $keyId, string $reason): array
    {
        $this->authorize($context, AuthorizationResource::item('extension_trust_key', $keyId));
        $keyId = $this->keyId($keyId);
        $reason = $this->reason($reason);
        $quarantined = $this->repository->synchronizedLifecycle(function () use ($context, $keyId, $reason): array {
            return $this->transactions->transactional(function () use ($context, $keyId, $reason): array {
                $this->repository->lockGeneration();
                $now = $this->clock->now();
                $this->repository->revoke($keyId, $context->actorId(), $reason, $now);
                $quarantined = $this->repository->quarantineExtensionsForKey($keyId, $now);
                $this->repository->advanceGeneration($now);
                $this->runtime->advance('extension.trust_key.revoke.emergency', $keyId);
                $this->record($context, 'extension.trust_key.revoke.emergency', $keyId, [
                    'reason' => $reason,
                    'quarantined_extensions' => $quarantined,
                ]);
                $this->transactions->afterCommit(function (): void {
                    $this->materializeBestEffort();
                });
                return $quarantined;
            });
        });
        return $quarantined;
    }

    public function assertTrusted(
        PackageChecksum $checksum,
        ?PackageSignature $signature,
        ExtensionIdentifier $extension,
        bool $serialize = false,
    ): void {
        if ($serialize) {
            $this->repository->lockGeneration();
        }
        if ($signature === null) {
            if ($this->allowUnsignedLocalPackages) {
                return;
            }
            throw new UntrustedPackage('Unsigned extension packages are disabled for this installation.');
        }
        $key = $this->repository->usable($signature->keyId(), $extension->value(), $this->clock->now());
        $publicKey = $key['public_key_base64'] ?? null;
        if (!is_string($publicKey)) {
            throw new UntrustedPackage('The extension signing key is revoked, expired, or outside its namespace.');
        }
        if (!$this->verifier->verify($publicKey, $checksum, $signature)) {
            throw new UntrustedPackage('The extension package signature is invalid.');
        }
    }

    public function assertInstalledReleaseTrusted(string $extensionIdentifier, bool $serialize = false): void
    {
        $extension = ExtensionIdentifier::fromString($extensionIdentifier);
        if ($serialize) {
            $this->repository->lockGeneration();
        }
        $this->verifiedInstalledRelease($extension);
    }

    /**
     * Enforces deployed-byte trust and equality with the already-decoded runtime publication entry.
     * @param array<string, mixed> $entry
     */
    public function enforceRuntimeEntryTrust(array $entry): void
    {
        $identifier = $entry['identifier'] ?? null;
        if (!is_string($identifier)) {
            throw new UntrustedPackage('The compiled extension entry has no identifier.');
        }
        $this->enforceRuntimeTrust($identifier, $entry);
    }

    /**
     * Enforces trust for authoritative active inventory records that may be absent from the publication.
     * @param array<string, mixed>|null $entry
     */
    public function enforceRuntimeTrust(string $extensionIdentifier, ?array $entry = null): void
    {
        try {
            $this->transactions->transactional(function () use ($extensionIdentifier, $entry): void {
                $extension = ExtensionIdentifier::fromString($extensionIdentifier);
                $this->repository->lockGeneration();
                if (!in_array($extensionIdentifier, $this->repository->activeExtensions(), true)) {
                    if ($entry !== null) {
                        throw new RuntimePublicationMismatch('The compiled extension is no longer active.');
                    }
                    throw new UntrustedPackage('The extension is no longer active.');
                }
                $release = $this->verifiedInstalledRelease($extension);
                if ($entry !== null) {
                    $this->assertRuntimeEntryMatches($entry, $release, $extension);
                }
            });
        } catch (RuntimePublicationMismatch $exception) {
            throw $exception;
        } catch (UntrustedPackage | InvalidArgumentException $exception) {
            $this->quarantine($extensionIdentifier);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function verifiedInstalledRelease(ExtensionIdentifier $extension): array
    {
        $extensionIdentifier = $extension->value();
        $release = $this->repository->installedRelease($extensionIdentifier);
        if ($release === null || !is_string($release['package_sha256'] ?? null)) {
            throw new UntrustedPackage('The installed extension release trust record is missing.');
        }
        if (($release['trust_state'] ?? null) !== 'verified') {
            throw new UntrustedPackage('The installed extension release must be reinstalled to restore trust.');
        }
        $keyId = $release['signing_key_id'] ?? null;
        $signature = $release['signature_base64'] ?? null;
        $packageSignature = is_string($keyId) && is_string($signature)
            ? PackageSignature::ed25519($keyId, $signature)
            : null;
        $this->assertTrusted(PackageChecksum::sha256($release['package_sha256']), $packageSignature, $extension);
        $this->artifacts->assertMatches($release);
        return $release;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $release
     */
    private function assertRuntimeEntryMatches(
        array $entry,
        array $release,
        ExtensionIdentifier $extension,
    ): void {
        $manifestValue = $release['manifest'] ?? null;
        $manifest = ExtensionManifest::fromJson(is_string($manifestValue)
            ? $manifestValue
            : json_encode($manifestValue, JSON_THROW_ON_ERROR));
        if (
            !$manifest->identifier()->equals($extension)
            || (string) $manifest->version() !== ($release['installed_version'] ?? null)
            || $manifest->serviceProvider() !== ($release['service_provider'] ?? null)
            || $manifest->type()->value !== ($release['extension_type'] ?? null)
        ) {
            throw new UntrustedPackage('The installed extension manifest does not match its runtime inventory.');
        }
        $expected = [
            'identifier' => $extension->value(),
            'version' => (string) $manifest->version(),
            'provider' => $manifest->serviceProvider(),
            'type' => $manifest->type()->value,
            'root' => $release['runtime_path'] ?? null,
            'signing_key_id' => $release['signing_key_id'] ?? null,
            'artifact_sha256' => $release['artifact_sha256'] ?? null,
            'deployed_tree_sha256' => $release['deployed_tree_sha256'] ?? null,
        ];
        foreach ($expected as $field => $value) {
            if (($entry[$field] ?? null) !== $value) {
                throw new RuntimePublicationMismatch(sprintf(
                    'The compiled extension %s does not match authoritative release metadata.',
                    $field,
                ));
            }
        }
        if (($entry['autoload'] ?? null) !== $manifest->autoload()) {
            throw new RuntimePublicationMismatch('The compiled extension autoload map is not authoritative.');
        }
    }

    /** Validates the authoritative active inventory, including records absent from the compiled map. */
    public function enforceActiveRuntimeTrust(): void
    {
        foreach ($this->activeRuntimeIdentifiers() as $identifier) {
            $this->enforceRuntimeTrust($identifier);
        }
    }

    public function ready(): bool
    {
        if (!$this->lifecycleReady()) {
            return false;
        }
        try {
            $this->runtime->materialize();
        } catch (Throwable) {
            return false;
        }
        $ready = true;
        foreach ($this->activeRuntimeIdentifiers() as $identifier) {
            try {
                $this->enforceRuntimeTrust($identifier);
            } catch (UntrustedPackage | InvalidArgumentException) {
                $ready = false;
            }
        }
        return $ready;
    }

    private function quarantine(string $identifier): void
    {
        $this->transactions->transactional(function () use ($identifier): void {
            $this->repository->lockGeneration();
            $now = $this->clock->now();
            if ($this->repository->quarantineExtension($identifier, $now)) {
                $this->repository->advanceGeneration($now);
                $this->runtime->advance('extension.trust.quarantine', $identifier);
                $this->transactions->afterCommit(function (): void {
                    $this->materializeBestEffort();
                });
            }
        });
    }

    private function materializeBestEffort(): void
    {
        try {
            $this->runtime->materialize();
        } catch (Throwable) {
            // Authoritative database state is committed; bootstrap retries materialization and fails closed.
        }
    }

    private function authorize(ExecutionContext $context, AuthorizationResource $resource): void
    {
        $capability = Capability::fromString('extensions.manage');
        $this->authorization->assertAllowed($context, $capability, $resource);
    }

    /**
     * @return array{
     *   key_id: string, algorithm: string, public_key_base64: string, enabled: bool,
     *   vendor_namespace: string, extension_pattern: string, expires_at: DateTimeImmutable,
     *   rotated_from: ?string, added_by: string, added_at: DateTimeImmutable,
     *   revoked_at: null, revoked_by: null, revocation_reason: null
     * }
     */
    private function keyValues(
        ExecutionContext $context,
        string $keyId,
        string $publicKeyBase64,
        string $vendorNamespace,
        string $extensionPattern,
        DateTimeImmutable $expiresAt,
        ?string $rotatedFrom,
    ): array {
        $keyId = $this->keyId($keyId);
        $publicKeyBase64 = $this->publicKey($publicKeyBase64);
        [$vendorNamespace, $extensionPattern] = $this->constraint($vendorNamespace, $extensionPattern);
        $now = $this->clock->now();
        if ($expiresAt <= $now || $expiresAt > $now->modify('+3 years')) {
            throw new InvalidArgumentException('A trust key must expire in the future and within three years.');
        }
        return [
            'key_id' => $keyId,
            'algorithm' => 'ed25519',
            'public_key_base64' => $publicKeyBase64,
            'enabled' => true,
            'vendor_namespace' => $vendorNamespace,
            'extension_pattern' => $extensionPattern,
            'expires_at' => $expiresAt,
            'rotated_from' => $rotatedFrom === null ? null : $this->keyId($rotatedFrom),
            'added_by' => $context->actorId(),
            'added_at' => $now,
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function activeKey(string $keyId): ?array
    {
        foreach ($this->repository->all() as $key) {
            if (
                ($key['key_id'] ?? null) !== $keyId || !(bool) ($key['enabled'] ?? false)
                || ($key['revoked_at'] ?? null) !== null
            ) {
                continue;
            }
            $expiresAt = $key['expires_at'] ?? null;
            if ($expiresAt instanceof DateTimeImmutable && $expiresAt > $this->clock->now()) {
                return $key;
            }
            if (is_string($expiresAt)) {
                try {
                    if (new DateTimeImmutable($expiresAt) > $this->clock->now()) {
                        return $key;
                    }
                } catch (\Exception) {
                    return null;
                }
            }
        }
        return null;
    }

    private function keyId(string $keyId): string
    {
        $keyId = strtolower(trim($keyId));
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1) {
            throw new InvalidArgumentException('A trust key ID must be a stable lowercase identifier.');
        }
        return $keyId;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A trust-key revocation reason of 1 to 500 characters is required.');
        }
        return $reason;
    }

    private function publicKey(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('An Ed25519 public key must be canonical base64 encoding of 32 bytes.');
        }
        return base64_encode($bytes);
    }

    /** @return array{string, string} */
    private function constraint(string $vendor, string $pattern): array
    {
        $vendor = strtolower(trim($vendor));
        $pattern = strtolower(trim($pattern));
        $segment = '[a-z0-9](?:[a-z0-9._-]{0,62})';
        if ($vendor !== '*' && preg_match('/^' . $segment . '$/D', $vendor) !== 1) {
            throw new InvalidArgumentException('The trust-key vendor namespace is invalid.');
        }
        if ($pattern !== '*' && preg_match('/^' . $segment . '$/D', $pattern) !== 1) {
            throw new InvalidArgumentException('The trust-key extension pattern is invalid.');
        }
        return [$vendor, $pattern];
    }

    /** @param array<string, mixed> $metadata */
    private function record(ExecutionContext $context, string $action, string $keyId, array $metadata): void
    {
        $this->audit->record(new AuditEvent(
            Uuid::uuid7()->toString(),
            $this->clock->now(),
            $context->actorId(),
            $action,
            'extension_trust_key',
            $keyId,
            'success',
            $metadata,
        ));
    }
}
