<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Application\Trust;

use DateTimeImmutable;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustKeySignatureVerifier;
use Kumwe\CMS\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\CMS\Extension\Application\Trust\RuntimePublicationMismatch;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\CMS\Extension\Application\Trust\UntrustedPackage;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Runtime\ExtensionContainer;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\CMS\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\CMS\Extension\Runtime\RuntimePublicationKeyRing;
use Kumwe\CMS\Extension\Runtime\VerifiedRuntimePublication;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(TrustStore::class)]
#[UsesClass(ExtensionRuntimeLoader::class)]
final class TrustStoreTest extends TestCase
{
    public function testManagementRequiresApplicationLayerCapability(): void
    {
        $store = $this->store(new MemoryTrustStoreRepository());
        $this->expectException(AuthorizationDenied::class);
        $store->keys(AuthorizationContext::human(['content.read']));
    }

    public function testSiteScopedGrantCannotManageInstallWideTrust(): void
    {
        $store = $this->store(new MemoryTrustStoreRepository());
        $this->expectException(AuthorizationDenied::class);
        $store->keys(AuthorizationContext::siteScoped('extensions.manage'));
    }

    public function testSignedEmptyRuntimePublicationDoesNotRequireTrustDatabaseAtBootstrap(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $repository->lifecycle = false;
        $store = $this->store($repository);
        $keys = $this->runtimeKeys();
        $active = (new ExtensionRuntimeLoader(
            $this->publication([], $keys),
            sys_get_temp_dir(),
            $keys,
            $store,
        ))->load([]);
        self::assertSame(0, $active->count());
    }

    public function testNonEmptyTrustedRuntimeLoadsFromAResolvedNonSymlinkRoot(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $store = $this->store($repository);
        $context = AuthorizationContext::human(['extensions.manage']);
        $store->add($context, 'vendor.loader', $this->publicKey(), 'acme', '*', $this->expiry());
        $root = sys_get_temp_dir() . '/kumwe-loader-' . bin2hex(random_bytes(8));
        mkdir($root . '/acme/catalog/1.0.0', 0700, true);
        $manifest = json_encode([
            'schema' => 1,
            'name' => 'acme/catalog',
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => NonEmptyTestProvider::class,
            'autoload' => ['psr-4' => []],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'dependencies' => [],
            'migrations' => [],
            'configuration' => new \stdClass(),
            'permissions' => [],
            'routes' => [],
            'events' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $repository->active['acme/catalog'] = 'vendor.loader';
        $repository->releases['acme/catalog'] = [
            'identifier' => 'acme/catalog',
            'installed_version' => '1.0.0',
            'service_provider' => NonEmptyTestProvider::class,
            'extension_type' => 'plugin',
            'runtime_path' => 'acme/catalog/1.0.0',
            'manifest' => $manifest,
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'signing_key_id' => 'vendor.loader',
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'trust_state' => 'verified',
        ];
        $keys = $this->runtimeKeys();
        $active = (new ExtensionRuntimeLoader(
            $this->publication([[
                'identifier' => 'acme/catalog',
                'version' => '1.0.0',
                'provider' => NonEmptyTestProvider::class,
                'type' => 'plugin',
                'root' => 'acme/catalog/1.0.0',
                'autoload' => [],
                'signing_key_id' => 'vendor.loader',
                'artifact_sha256' => str_repeat('a', 64),
                'deployed_tree_sha256' => str_repeat('b', 64),
                'theme_surfaces' => [],
            ]], $keys),
            $root,
            $keys,
            $store,
        ))->load([]);
        self::assertSame(1, $active->count());
    }

    public function testRotationOverlapsAndRefusesFinalRevokeWhileReleaseIsActive(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $store = $this->store($repository);
        $context = AuthorizationContext::human(['extensions.manage']);
        $store->add($context, 'vendor.old', $this->publicKey(), 'acme', '*', $this->expiry());
        $repository->active['acme/catalog'] = 'vendor.old';
        $store->rotate(
            $context,
            'vendor.old',
            'vendor.new',
            $this->publicKey(),
            'acme',
            '*',
            $this->expiry(),
        );

        self::assertNotNull($repository->usable('vendor.old', 'acme/catalog', $this->now()));
        self::assertNotNull($repository->usable('vendor.new', 'acme/catalog', $this->now()));
        try {
            $store->finalizeRotation($context, 'vendor.old', 'rotation completed');
            self::fail('Final revocation must be refused while an active release still uses the old key.');
        } catch (\InvalidArgumentException) {
        }
        $repository->active['acme/catalog'] = 'vendor.new';
        $store->finalizeRotation($context, 'vendor.old', 'all releases upgraded');
        self::assertNull($repository->usable('vendor.old', 'acme/catalog', $this->now()));
        self::assertNotNull($repository->usable('vendor.new', 'acme/catalog', $this->now()));
    }

    public function testRotationCannotSilentlyChangeNamespaceConstraints(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $store = $this->store($repository);
        $context = AuthorizationContext::human(['extensions.manage']);
        $store->add($context, 'vendor.old', $this->publicKey(), 'acme', '*', $this->expiry());

        $this->expectException(\InvalidArgumentException::class);
        $store->rotate(
            $context,
            'vendor.old',
            'vendor.new',
            $this->publicKey(),
            'different-vendor',
            '*',
            $this->expiry(),
        );
    }

    public function testEmergencyRevokeQuarantinesAffectedRuntimeAndRebuildsMap(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $runtime = new MemoryRuntimeInvalidator();
        $store = $this->store($repository, $runtime);
        $context = AuthorizationContext::human(['extensions.manage']);
        $store->add($context, 'vendor.primary', $this->publicKey(), 'acme', '*', $this->expiry());
        $repository->active['acme/catalog'] = 'vendor.primary';

        self::assertSame(
            ['acme/catalog'],
            $store->emergencyRevoke($context, 'vendor.primary', 'confirmed compromise'),
        );
        self::assertSame([], $repository->active);
        self::assertSame(['acme/catalog'], $repository->quarantined);
        self::assertSame(1, $runtime->rebuilds);
    }

    public function testMaterializationFailureCannotRollBackEmergencyRevocation(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $runtime = new MemoryRuntimeInvalidator();
        $runtime->failMaterialization = true;
        $store = $this->store($repository, $runtime);
        $context = AuthorizationContext::human(['extensions.manage']);
        $store->add($context, 'vendor.failure', $this->publicKey(), 'acme', '*', $this->expiry());
        $repository->active['acme/catalog'] = 'vendor.failure';

        self::assertSame(
            ['acme/catalog'],
            $store->emergencyRevoke($context, 'vendor.failure', 'replica storage is unavailable'),
        );
        self::assertNull($repository->usable('vendor.failure', 'acme/catalog', $this->now()));
        self::assertSame(['acme/catalog'], $repository->quarantined);
        self::assertFalse($store->ready());
    }

    public function testTamperedLocalPublicationIsRejectedWithoutQuarantiningAuthoritativeRelease(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $runtime = new MemoryRuntimeInvalidator();
        $store = $this->store($repository, $runtime);
        $context = AuthorizationContext::human(['extensions.manage']);
        $store->add($context, 'vendor.runtime', $this->publicKey(), 'acme', '*', $this->expiry());
        $manifest = json_encode([
            'schema' => 1,
            'name' => 'acme/catalog',
            'type' => 'plugin',
            'version' => '1.0.0',
            'provider' => 'Acme\\Catalog\\Provider',
            'autoload' => ['psr-4' => ['Acme\\Catalog\\' => 'src/']],
            'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            'dependencies' => [],
            'migrations' => [],
            'configuration' => new \stdClass(),
            'permissions' => [],
            'routes' => [],
            'events' => [],
            'assets' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $repository->active['acme/catalog'] = 'vendor.runtime';
        $repository->releases['acme/catalog'] = [
            'identifier' => 'acme/catalog',
            'installed_version' => '1.0.0',
            'service_provider' => 'Acme\\Catalog\\Provider',
            'extension_type' => 'plugin',
            'runtime_path' => 'acme/catalog/1.0.0',
            'manifest' => $manifest,
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'signing_key_id' => 'vendor.runtime',
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'trust_state' => 'verified',
        ];

        try {
            $store->enforceRuntimeEntryTrust([
                'identifier' => 'acme/catalog',
                'version' => '1.0.0',
                'provider' => 'Attacker\\Provider',
                'type' => 'plugin',
                'root' => 'acme/catalog/1.0.0',
                'autoload' => ['Acme\\Catalog\\' => 'src/'],
                'signing_key_id' => 'vendor.runtime',
                'artifact_sha256' => str_repeat('a', 64),
                'deployed_tree_sha256' => str_repeat('b', 64),
            ]);
            self::fail('A modified provider mapping must never execute.');
        } catch (RuntimePublicationMismatch) {
        }
        self::assertSame([], $repository->quarantined);
        self::assertSame(0, $runtime->rebuilds);
    }

    public function testExpiredReleaseIsQuarantinedBeforeRuntimeExecution(): void
    {
        $repository = new MemoryTrustStoreRepository();
        $runtime = new MemoryRuntimeInvalidator();
        $store = $this->store($repository, $runtime);
        $context = AuthorizationContext::human(['extensions.manage']);
        $store->add(
            $context,
            'vendor.expiring',
            $this->publicKey(),
            'acme',
            '*',
            new DateTimeImmutable('2026-08-05T08:30:00+00:00'),
        );
        $repository->active['acme/catalog'] = 'vendor.expiring';
        $repository->releases['acme/catalog'] = [
            'package_sha256' => str_repeat('a', 64),
            'signing_key_id' => 'vendor.expiring',
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'trust_state' => 'verified',
        ];
        $repository->now = new DateTimeImmutable('2026-08-05T09:00:00+00:00');

        $this->expectException(UntrustedPackage::class);
        try {
            $store->enforceRuntimeTrust('acme/catalog');
        } finally {
            self::assertSame(['acme/catalog'], $repository->quarantined);
            self::assertSame(1, $runtime->rebuilds);
        }
    }

    private function store(
        TrustStoreRepository $repository,
        ?MemoryRuntimeInvalidator $runtime = null,
    ): TrustStore {
        $verifier = new class implements TrustKeySignatureVerifier {
            public function verify(string $key, PackageChecksum $checksum, PackageSignature $signature): bool
            {
                return true;
            }
        };
        $artifacts = new class implements ExtensionArtifactVerifier {
            public function assertMatches(array $release): void
            {
            }
        };
        $transactions = new class implements TransactionManager {
            public function transactional(callable $operation): mixed
            {
                return $operation();
            }

            public function afterCommit(callable $operation): void
            {
                $operation();
            }

            public function afterRollback(callable $operation): void
            {
            }
        };
        $audit = $this->createStub(AuditRecorder::class);
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(
            fn (): DateTimeImmutable => $repository instanceof MemoryTrustStoreRepository
                ? $repository->now
                : $this->now(),
        );
        return new TrustStore(
            $repository,
            $verifier,
            $artifacts,
            $runtime ?? new MemoryRuntimeInvalidator(),
            $transactions,
            $audit,
            $clock,
            AuthorizationContext::gateway(),
        );
    }

    private function runtimeKeys(): RuntimePublicationKeyRing
    {
        return new RuntimePublicationKeyRing('runtime-v1', str_repeat('runtime-secret', 4));
    }

    /** @param list<array<string, mixed>> $extensions */
    private function publication(
        array $extensions,
        RuntimePublicationKeyRing $keys,
    ): VerifiedRuntimePublication {
        $base = [
            'format' => 'kumwe-extension-map-v3',
            'generation' => 1,
            'state_sha256' => hash('sha256', RuntimeCanonicalJson::encode($extensions)),
            'action' => 'test.publication',
            'signing_key_id' => $keys->activeKeyId,
            'extensions' => $extensions,
        ];
        $checksum = hash('sha256', RuntimeCanonicalJson::encode($base));

        return new VerifiedRuntimePublication($base + [
            'publication_sha256' => $checksum,
            'trust_hmac' => $keys->sign('1:' . $checksum),
        ]);
    }

    private function publicKey(): string
    {
        return base64_encode(str_repeat('p', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-05T08:00:00+00:00');
    }

    private function expiry(): DateTimeImmutable
    {
        return new DateTimeImmutable('2027-01-01T00:00:00+00:00');
    }
}

/** @internal */
final class NonEmptyTestProvider implements ExtensionServiceProvider
{
    public function register(ExtensionContainer $container): void
    {
    }
}

/** @internal */
final class MemoryRuntimeInvalidator implements TrustRuntimeInvalidator
{
    public int $rebuilds = 0;
    public bool $failMaterialization = false;

    public function advance(string $reason, ?string $extensionIdentifier = null): int
    {
        return ++$this->rebuilds;
    }

    public function materialize(): int
    {
        if ($this->failMaterialization) {
            throw new \RuntimeException('replica-local materialization failed');
        }
        return $this->rebuilds;
    }

    public function discardLocal(): void
    {
    }
}

/** @internal */
final class MemoryTrustStoreRepository implements TrustStoreRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $keys = [];
    /** @var array<string, string> */
    public array $active = [];
    /** @var array<string, array<string, mixed>> */
    public array $releases = [];
    /** @var list<string> */
    public array $quarantined = [];
    public DateTimeImmutable $now;
    private int $generation = 0;
    public bool $lifecycle = true;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-08-05T08:00:00+00:00');
    }

    public function synchronizedLifecycle(callable $operation): mixed
    {
        return $operation();
    }

    public function lifecycleReady(): bool
    {
        return $this->lifecycle;
    }

    public function all(): array
    {
        return array_values($this->keys);
    }

    public function add(array $key): void
    {
        $this->keys[(string) $key['key_id']] = $key;
    }

    public function revoke(string $keyId, string $actorId, string $reason, DateTimeImmutable $at): void
    {
        if (($this->keys[$keyId]['enabled'] ?? false) !== true) {
            throw new \InvalidArgumentException('The active trust key does not exist.');
        }
        $this->keys[$keyId]['enabled'] = false;
        $this->keys[$keyId]['revoked_at'] = $at;
    }

    public function lockGeneration(): int
    {
        return $this->generation;
    }

    public function advanceGeneration(DateTimeImmutable $at): void
    {
        ++$this->generation;
    }

    public function usable(string $keyId, string $extensionIdentifier, DateTimeImmutable $at): ?array
    {
        $key = $this->keys[$keyId] ?? null;
        if ($key === null || $key['enabled'] !== true || $key['revoked_at'] !== null || $key['expires_at'] <= $at) {
            return null;
        }
        [$vendor, $name] = explode('/', $extensionIdentifier, 2);
        if (
            ($key['vendor_namespace'] !== '*' && $key['vendor_namespace'] !== $vendor)
            || ($key['extension_pattern'] !== '*' && $key['extension_pattern'] !== $name)
        ) {
            return null;
        }
        return $key;
    }

    public function installedRelease(string $extensionIdentifier): ?array
    {
        return $this->releases[$extensionIdentifier] ?? null;
    }

    public function activeExtensions(): array
    {
        return array_keys($this->active);
    }

    public function activeExtensionsForKey(string $keyId): array
    {
        return array_keys(array_filter($this->active, static fn (string $value): bool => $value === $keyId));
    }

    public function extensionsRequiringKey(string $keyId): array
    {
        return $this->activeExtensionsForKey($keyId);
    }

    public function quarantineExtensionsForKey(string $keyId, DateTimeImmutable $at): array
    {
        $affected = $this->activeExtensionsForKey($keyId);
        foreach ($affected as $identifier) {
            $this->quarantineExtension($identifier, $at);
        }
        return $affected;
    }

    public function quarantineExtension(string $extensionIdentifier, DateTimeImmutable $at): bool
    {
        if (!isset($this->active[$extensionIdentifier])) {
            return false;
        }
        unset($this->active[$extensionIdentifier]);
        $this->quarantined[] = $extensionIdentifier;
        return true;
    }
}
