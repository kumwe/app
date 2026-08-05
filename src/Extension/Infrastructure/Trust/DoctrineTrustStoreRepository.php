<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class DoctrineTrustStoreRepository implements TrustStoreRepository
{
    private const LIFECYCLE_LOCK = 'extension-lifecycle';

    private ReentrantLifecycleLock $localLifecycleLock;

    public function __construct(private Connection $database, private TableNames $tables)
    {
        $this->localLifecycleLock = new ReentrantLifecycleLock();
    }

    public function lifecycleReady(): bool
    {
        $schema = $this->database->createSchemaManager();
        $required = [
            $this->tables->raw('extension_trust_generation'),
            $this->tables->raw('extension_trust_keys'),
            $this->tables->raw('extension_releases'),
            $this->tables->raw('extension_runtime_outbox'),
        ];
        if (!$schema->tablesExist($required)) {
            return false;
        }
        $releases = $schema->introspectTableByUnquotedName($this->tables->raw('extension_releases'));
        $trustGeneration = $schema->introspectTableByUnquotedName($this->tables->raw('extension_trust_generation'));
        if (
            !$trustGeneration->hasColumn('lifecycle_state')
            || $this->database->fetchOne(sprintf(
                'SELECT lifecycle_state FROM %s WHERE singleton_key = 1',
                $this->tables->quoted('extension_trust_generation'),
            )) !== 'ready'
        ) {
            return false;
        }
        return $releases->hasColumn('artifact_sha256')
            && $releases->hasColumn('deployed_tree_sha256')
            && $releases->hasColumn('trust_state');
    }

    public function synchronizedLifecycle(callable $operation): mixed
    {
        $platform = $this->database->getDatabasePlatform();
        $lockName = 'kumwe:' . $this->tables->raw('extension_lifecycle');
        if ($platform instanceof AbstractMySQLPlatform) {
            $acquired = $this->database->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]);
            if (!in_array($acquired, [1, '1', true], true)) {
                throw new RuntimeException('Another extension lifecycle operation is already in progress.');
            }
            try {
                return $operation();
            } finally {
                $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $acquired = $this->database->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', [$lockName]);
            if (!in_array($acquired, [1, '1', true, 't', 'true'], true)) {
                throw new RuntimeException('Another extension lifecycle operation is already in progress.');
            }
            try {
                return $operation();
            } finally {
                $this->database->fetchOne('SELECT pg_advisory_unlock(hashtext(?))', [$lockName]);
            }
        }

        if ($this->localLifecycleLock->held()) {
            $this->localLifecycleLock->enter();
            try {
                return $operation();
            } finally {
                $this->localLifecycleLock->leave();
            }
        }

        $owner = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $table = $this->tables->quoted('migration_locks');
        $this->database->executeStatement(sprintf(
            'DELETE FROM %s WHERE lock_name = ? AND expires_at <= ?',
            $table,
        ), [self::LIFECYCLE_LOCK, $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);
        try {
            $this->database->insert($this->tables->raw('migration_locks'), [
                'lock_name' => self::LIFECYCLE_LOCK,
                'owner_token' => $owner,
                'acquired_at' => $now,
                'expires_at' => $now->modify('+30 minutes'),
            ], [
                'acquired_at' => Types::DATETIME_IMMUTABLE,
                'expires_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('Another extension lifecycle operation is already in progress.', 0, $exception);
        }
        $this->localLifecycleLock->enter();
        try {
            return $operation();
        } finally {
            $this->localLifecycleLock->leave();
            $this->database->delete($this->tables->raw('migration_locks'), [
                'lock_name' => self::LIFECYCLE_LOCK,
                'owner_token' => $owner,
            ]);
        }
    }

    public function all(): array
    {
        return $this->database->fetchAllAssociative(sprintf(
            'SELECT key_id, algorithm, enabled, vendor_namespace, extension_pattern, expires_at, rotated_from, '
            . 'added_by, added_at, revoked_at, revoked_by, revocation_reason FROM %s ORDER BY added_at DESC',
            $this->tables->quoted('extension_trust_keys'),
        ));
    }

    public function add(array $key): void
    {
        $this->database->insert($this->tables->raw('extension_trust_keys'), $key, [
            'enabled' => Types::BOOLEAN,
            'expires_at' => Types::DATETIME_IMMUTABLE,
            'added_at' => Types::DATETIME_IMMUTABLE,
            'revoked_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    public function revoke(string $keyId, string $actorId, string $reason, DateTimeImmutable $at): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET enabled = ?, revoked_at = ?, revoked_by = ?, revocation_reason = ? '
            . 'WHERE key_id = ? AND enabled = ? AND revoked_at IS NULL',
            $this->tables->quoted('extension_trust_keys'),
        ), [false, $at, $actorId, $reason, $keyId, true], [
            Types::BOOLEAN,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
            Types::STRING,
            Types::STRING,
            Types::BOOLEAN,
        ]);
        if ($affected !== 1) {
            throw new InvalidArgumentException('The active trust key does not exist.');
        }
    }

    public function lockGeneration(): int
    {
        $value = $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1 FOR UPDATE',
            $this->tables->quoted('extension_trust_generation'),
        ));
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException('The extension trust generation is unavailable.');
        }
        return (int) $value;
    }

    public function advanceGeneration(DateTimeImmutable $at): void
    {
        $affected = $this->database->executeStatement(sprintf(
            'UPDATE %s SET generation = generation + 1, updated_at = ? WHERE singleton_key = 1',
            $this->tables->quoted('extension_trust_generation'),
        ), [$at], [Types::DATETIME_IMMUTABLE]);
        if ($affected !== 1) {
            throw new RuntimeException('The extension trust generation could not be advanced.');
        }
    }

    public function usable(string $keyId, string $extensionIdentifier, DateTimeImmutable $at): ?array
    {
        $identifier = ExtensionIdentifier::fromString($extensionIdentifier)->value();
        [$vendor, $name] = explode('/', $identifier, 2);
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT key_id, public_key_base64, vendor_namespace, extension_pattern, expires_at FROM %s '
            . 'WHERE key_id = ? AND algorithm = ? AND enabled = ? AND revoked_at IS NULL '
            . 'AND expires_at > ?',
            $this->tables->quoted('extension_trust_keys'),
        ), [$keyId, 'ed25519', true, $at], [
            Types::STRING, Types::STRING, Types::BOOLEAN, Types::DATETIME_IMMUTABLE,
        ]);
        if ($row === false) {
            return null;
        }
        $keyVendor = $row['vendor_namespace'] ?? null;
        $pattern = $row['extension_pattern'] ?? null;
        if (!is_string($keyVendor) || !is_string($pattern)) {
            return null;
        }
        if (($keyVendor !== '*' && $keyVendor !== $vendor) || ($pattern !== '*' && $pattern !== $name)) {
            return null;
        }
        return $row;
    }

    public function installedRelease(string $extensionIdentifier): ?array
    {
        $row = $this->database->fetchAssociative(sprintf(
            'SELECT e.identifier, e.installed_version, e.service_provider, e.extension_type, e.runtime_path, '
            . 'r.manifest, r.package_sha256, r.signing_key_id, r.signature_base64, r.artifact_sha256, '
            . 'r.deployed_tree_sha256, r.trust_state FROM %s e INNER JOIN %s r '
            . 'ON r.extension_id = e.id AND r.version = e.installed_version WHERE e.identifier = ?',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$extensionIdentifier]);
        return $row === false ? null : $row;
    }

    public function activeExtensions(): array
    {
        return array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            "SELECT e.identifier FROM %s e WHERE e.status = 'active' ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
        )), 'is_string'));
    }

    public function activeExtensionsForKey(string $keyId): array
    {
        return array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            'SELECT e.identifier FROM %s e INNER JOIN %s r ON r.extension_id = e.id '
            . "AND r.version = e.installed_version WHERE e.status = 'active' AND r.signing_key_id = ? "
            . 'ORDER BY e.identifier',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$keyId]), 'is_string'));
    }

    public function extensionsRequiringKey(string $keyId): array
    {
        return array_values(array_filter($this->database->fetchFirstColumn(sprintf(
            'SELECT e.identifier FROM %s e INNER JOIN %s r ON r.extension_id = e.id '
            . 'AND r.version = e.installed_version WHERE r.signing_key_id = ? '
            . "AND e.status NOT IN ('quarantined', 'needs_reverification') ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$keyId]), 'is_string'));
    }

    public function quarantineExtensionsForKey(string $keyId, DateTimeImmutable $at): array
    {
        $identifiers = $this->activeExtensionsForKey($keyId);
        foreach ($identifiers as $identifier) {
            $this->quarantineExtension($identifier, $at);
        }
        return $identifiers;
    }

    public function quarantineExtension(string $extensionIdentifier, DateTimeImmutable $at): bool
    {
        return $this->database->executeStatement(sprintf(
            "UPDATE %s SET status = 'quarantined', registry_version = registry_version + 1, updated_at = ? "
            . "WHERE identifier = ? AND status = 'active'",
            $this->tables->quoted('extensions'),
        ), [$at, $extensionIdentifier], [Types::DATETIME_IMMUTABLE, Types::STRING]) === 1;
    }
}
