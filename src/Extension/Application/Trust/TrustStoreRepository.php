<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use DateTimeImmutable;

interface TrustStoreRepository
{
    /** @template T @param callable(): T $operation @return T */
    public function synchronizedLifecycle(callable $operation): mixed;

    public function lifecycleReady(): bool;

    /** @return list<array<string, mixed>> */
    public function all(): array;

    /** @param array<string, mixed> $key */
    public function add(array $key): void;

    public function revoke(string $keyId, string $actorId, string $reason, DateTimeImmutable $at): void;

    /** Locks and returns the trust generation for the current transaction. */
    public function lockGeneration(): int;

    public function advanceGeneration(DateTimeImmutable $at): void;

    /** @return array<string, mixed>|null */
    public function usable(string $keyId, string $extensionIdentifier, DateTimeImmutable $at): ?array;

    /** @return array<string, mixed>|null */
    public function installedRelease(string $extensionIdentifier): ?array;

    /** @return list<string> */
    public function activeExtensions(): array;

    /** @return list<string> */
    public function activeExtensionsForKey(string $keyId): array;

    /** @return list<string> Current releases that must be upgraded or quarantined before final revocation. */
    public function extensionsRequiringKey(string $keyId): array;

    /** @return list<string> */
    public function quarantineExtensionsForKey(string $keyId, DateTimeImmutable $at): array;

    public function quarantineExtension(string $extensionIdentifier, DateTimeImmutable $at): bool;
}
