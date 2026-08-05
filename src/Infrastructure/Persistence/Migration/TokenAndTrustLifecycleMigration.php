<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Extension\Application\Package\ArchiveEntryType;
use Kumwe\CMS\Extension\Application\Package\PackageSafetyPolicy;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\PackageSignature;
use Kumwe\CMS\Extension\Infrastructure\Package\ZipArchiveReader;
use Kumwe\CMS\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\CMS\Extension\Infrastructure\Trust\SodiumTrustKeySignatureVerifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Adds revocable token epochs and a managed, constrained extension trust store. */
final readonly class TokenAndTrustLifecycleMigration implements Migration
{
    public const ID = '20260805040000_token_and_trust_lifecycles';

    public function __construct(private TableNames $tables, private string $extensionRoot)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The token and trust lifecycle migration checksum could not be calculated.');
        }
        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $schema = $database->createSchemaManager();
        $platform = $database->getDatabasePlatform();
        $users = $schema->introspectTable($this->tables->raw('users'));
        if (!$users->hasColumn('security_epoch')) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD security_epoch BIGINT NOT NULL DEFAULT 1',
                $this->tables->quoted('users'),
            ));
        }

        $tokens = $schema->introspectTable($this->tables->raw('api_tokens'));
        $this->add(
            $database,
            $tokens->hasColumn('security_epoch'),
            'api_tokens',
            'security_epoch BIGINT NOT NULL DEFAULT 1',
        );
        $this->add(
            $database,
            $tokens->hasColumn('audience'),
            'api_tokens',
            "audience VARCHAR(127) NOT NULL DEFAULT 'kumwe-http'",
        );
        $this->add(
            $database,
            $tokens->hasColumn('purpose'),
            'api_tokens',
            "purpose VARCHAR(127) NOT NULL DEFAULT 'api'",
        );
        $this->add(
            $database,
            $tokens->hasColumn('site_identifier'),
            'api_tokens',
            "site_identifier VARCHAR(191) NOT NULL DEFAULT 'default'",
        );
        $this->add(
            $database,
            $tokens->hasColumn('rotated_from'),
            'api_tokens',
            'rotated_from VARCHAR(36) DEFAULT NULL',
        );
        $this->add(
            $database,
            $tokens->hasColumn('revocation_reason'),
            'api_tokens',
            'revocation_reason VARCHAR(500) DEFAULT NULL',
        );
        $tokens = $schema->introspectTable($this->tables->raw('api_tokens'));
        $tokenInventoryIndex = $this->tables->raw('idx_api_token_inventory');
        if (!$tokens->hasIndex($tokenInventoryIndex)) {
            $database->executeStatement(sprintf(
                'CREATE INDEX %s ON %s '
                . '(subject_id, site_identifier, audience, purpose, revoked_at, expires_at)',
                $database->quoteSingleIdentifier($tokenInventoryIndex),
                $this->tables->quoted('api_tokens'),
            ));
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $database->executeStatement(sprintf(
            'UPDATE %s SET expires_at = ? WHERE expires_at IS NULL',
            $this->tables->quoted('api_tokens'),
        ), [$now->modify('+30 days')], [Types::DATETIME_IMMUTABLE]);
        $this->requireExpiry($database, 'api_tokens');
        $database->executeStatement(sprintf(
            'UPDATE %s SET security_epoch = (SELECT u.security_epoch FROM %s u WHERE u.id = %s.subject_id)',
            $this->tables->quoted('api_tokens'),
            $this->tables->quoted('users'),
            $this->tables->quoted('api_tokens'),
        ));

        $keys = $schema->introspectTable($this->tables->raw('extension_trust_keys'));
        $this->add(
            $database,
            $keys->hasColumn('vendor_namespace'),
            'extension_trust_keys',
            "vendor_namespace VARCHAR(63) NOT NULL DEFAULT '*'",
        );
        $this->add(
            $database,
            $keys->hasColumn('extension_pattern'),
            'extension_trust_keys',
            "extension_pattern VARCHAR(127) NOT NULL DEFAULT '*'",
        );
        $this->add(
            $database,
            $keys->hasColumn('expires_at'),
            'extension_trust_keys',
            $platform->getDateTimeTypeDeclarationSQL(['notnull' => false]) . ' DEFAULT NULL',
            'expires_at',
        );
        $this->add(
            $database,
            $keys->hasColumn('rotated_from'),
            'extension_trust_keys',
            'VARCHAR(127) DEFAULT NULL',
            'rotated_from',
        );
        $this->add(
            $database,
            $keys->hasColumn('added_by'),
            'extension_trust_keys',
            'VARCHAR(191) DEFAULT NULL',
            'added_by',
        );
        $this->add(
            $database,
            $keys->hasColumn('revoked_by'),
            'extension_trust_keys',
            'VARCHAR(191) DEFAULT NULL',
            'revoked_by',
        );
        $this->add(
            $database,
            $keys->hasColumn('revocation_reason'),
            'extension_trust_keys',
            'VARCHAR(500) DEFAULT NULL',
            'revocation_reason',
        );
        $database->executeStatement(sprintf(
            'UPDATE %s SET expires_at = ? WHERE expires_at IS NULL',
            $this->tables->quoted('extension_trust_keys'),
        ), [$now->modify('+1 year')], [Types::DATETIME_IMMUTABLE]);
        $this->requireExpiry($database, 'extension_trust_keys');

        $releases = $schema->introspectTable($this->tables->raw('extension_releases'));
        $this->add(
            $database,
            $releases->hasColumn('artifact_sha256'),
            'extension_releases',
            'artifact_sha256 VARCHAR(64) DEFAULT NULL',
        );
        $this->add(
            $database,
            $releases->hasColumn('deployed_tree_sha256'),
            'extension_releases',
            'deployed_tree_sha256 VARCHAR(64) DEFAULT NULL',
        );
        $this->add(
            $database,
            $releases->hasColumn('trust_state'),
            'extension_releases',
            "trust_state VARCHAR(32) NOT NULL DEFAULT 'needs_reverification'",
        );

        $idempotency = $schema->introspectTable($this->tables->raw('idempotency'));
        $this->add(
            $database,
            $idempotency->hasColumn('owner_token'),
            'idempotency',
            'owner_token VARCHAR(64) DEFAULT NULL',
        );
        $this->add(
            $database,
            $idempotency->hasColumn('lease_expires_at'),
            'idempotency',
            $platform->getDateTimeTypeDeclarationSQL(['notnull' => false]) . ' DEFAULT NULL',
            'lease_expires_at',
        );
        $this->add(
            $database,
            $idempotency->hasColumn('attempt'),
            'idempotency',
            'attempt INTEGER NOT NULL DEFAULT 0',
        );
        $database->executeStatement(sprintf(
            "UPDATE %s SET lease_expires_at = ? WHERE state = 'in_progress' AND lease_expires_at IS NULL",
            $this->tables->quoted('idempotency'),
        ), [$now], [Types::DATETIME_IMMUTABLE]);
        $this->scrubLegacyTokenIdempotencySecrets(
            $database,
            $idempotency->hasColumn('result_body_digest'),
        );

        $trustGeneration = $this->tables->raw('extension_trust_generation');
        if (!$schema->tablesExist([$trustGeneration])) {
            $generationSchema = new Schema();
            $table = $generationSchema->createTable($trustGeneration);
            $table->addColumn('singleton_key', Types::SMALLINT);
            $table->addColumn('generation', Types::BIGINT, ['default' => 0]);
            $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('lifecycle_state', Types::STRING, ['length' => 32, 'default' => 'migrating']);
            $table->setPrimaryKey(['singleton_key']);
            foreach ($generationSchema->toSql($platform) as $statement) {
                $database->executeStatement($statement);
            }
        }
        $trustGenerationSchema = $database->createSchemaManager()->introspectTable($trustGeneration);
        $this->add(
            $database,
            $trustGenerationSchema->hasColumn('lifecycle_state'),
            'extension_trust_generation',
            "lifecycle_state VARCHAR(32) NOT NULL DEFAULT 'migrating'",
        );
        $this->ensureTrustGenerationSingleton($database, $now);
        $database->executeStatement(sprintf(
            "UPDATE %s SET lifecycle_state = 'migrating', updated_at = ? WHERE singleton_key = 1",
            $this->tables->quoted('extension_trust_generation'),
        ), [$now], [Types::DATETIME_IMMUTABLE]);
        $this->ensureTrustGenerationSingleton($database, $now);
        $this->createRuntimeOutbox($database, $platform, $now);
        $this->transitionLegacyReleases($database, $now);
        $database->executeStatement(sprintf(
            "UPDATE %s SET lifecycle_state = 'ready', updated_at = ? WHERE singleton_key = 1",
            $this->tables->quoted('extension_trust_generation'),
        ), [$now], [Types::DATETIME_IMMUTABLE]);
        $this->ensureTrustGenerationSingleton($database, $now);
    }

    private function ensureTrustGenerationSingleton(Connection $database, DateTimeImmutable $now): void
    {
        $table = $this->tables->quoted('extension_trust_generation');
        $count = $database->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
        $singleton = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE singleton_key = 1',
            $table,
        ));
        $count = $this->nonNegativeInteger($count, 'trust lifecycle row count');
        $singleton = $this->nonNegativeInteger($singleton, 'trust lifecycle singleton count');
        if ($count === 0) {
            $database->insert($this->tables->raw('extension_trust_generation'), [
                'singleton_key' => 1,
                'generation' => 0,
                'updated_at' => $now,
                'lifecycle_state' => 'migrating',
            ], ['updated_at' => Types::DATETIME_IMMUTABLE]);
            $count = 1;
            $singleton = 1;
        }
        if ($count !== 1 || $singleton !== 1) {
            throw new RuntimeException(
                'The extension trust lifecycle table must contain exactly the singleton row with key 1.',
            );
        }
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (!is_int($value) && (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new RuntimeException(sprintf('The %s is invalid.', $label));
        }
        return (int) $value;
    }

    private function createRuntimeOutbox(
        Connection $database,
        AbstractPlatform $platform,
        DateTimeImmutable $now,
    ): void {
        $name = $this->tables->raw('extension_runtime_outbox');
        if (!$database->createSchemaManager()->tablesExist([$name])) {
            $schema = new Schema();
            $table = $schema->createTable($name);
            $table->addColumn('id', Types::GUID);
            $table->addColumn('generation', Types::BIGINT);
            $table->addColumn('event_type', Types::STRING, ['length' => 127]);
            $table->addColumn('extension_identifier', Types::STRING, ['length' => 127, 'notnull' => false]);
            $table->addColumn('state', Types::STRING, ['length' => 32]);
            $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $table->addColumn('materialized_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['state', 'generation'], $this->tables->raw('idx_extension_runtime_outbox'));
            foreach ($schema->toSql($platform) as $statement) {
                $database->executeStatement($statement);
            }
        }
        $upgradeExists = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE event_type = ?',
            $this->tables->quoted('extension_runtime_outbox'),
        ), ['extension.runtime.upgrade']);
        if ($upgradeExists !== false) {
            return;
        }
        // Advance even when every legacy release is valid: the publication schema itself changed.
        $database->executeStatement(sprintf(
            'UPDATE %s SET generation = generation + 1, rebuilt_at = ? WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        ), [$now], [Types::DATETIME_IMMUTABLE]);
        $generation = $database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $this->tables->quoted('extension_runtime_generation'),
        ));
        if (is_int($generation) || (is_string($generation) && ctype_digit($generation))) {
            $database->insert($name, [
                'id' => Uuid::uuid7()->toString(),
                'generation' => (int) $generation,
                'event_type' => 'extension.runtime.upgrade',
                'extension_identifier' => null,
                'state' => 'pending',
                'created_at' => $now,
                'materialized_at' => null,
            ], ['created_at' => Types::DATETIME_IMMUTABLE, 'materialized_at' => Types::DATETIME_IMMUTABLE]);
        }
    }

    private function scrubLegacyTokenIdempotencySecrets(Connection $database, bool $hasBodyDigest): void
    {
        $rows = $database->fetchAllAssociative(sprintf(
            "SELECT id, result_body FROM %s WHERE state = 'completed' "
            . "AND (operation = 'POST /api/v1/tokens' OR operation LIKE 'POST /api/v1/tokens/%%/rotate')",
            $this->tables->quoted('idempotency'),
        ));
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $body = $row['result_body'] ?? null;
            if (!is_string($id) || !is_string($body)) {
                continue;
            }
            try {
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (!is_array($decoded) || array_is_list($decoded) || !array_key_exists('token', $decoded)) {
                continue;
            }
            unset($decoded['token']);
            $decoded['secret_returned'] = false;
            $redacted = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $values = ['result_body' => $redacted];
            if ($hasBodyDigest) {
                $values['result_body_digest'] = hash('sha256', $redacted);
            }
            $database->update($this->tables->raw('idempotency'), $values, ['id' => $id]);
        }
    }

    private function transitionLegacyReleases(Connection $database, DateTimeImmutable $now): void
    {
        $postgres = $database->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $releaseExtensionId = $postgres ? 'CAST(r.extension_id AS VARCHAR)' : 'r.extension_id';
        $extensionId = $postgres ? 'CAST(e.id AS VARCHAR)' : 'e.id';
        $rows = $database->fetchAllAssociative(sprintf(
            'SELECT r.id, r.version, r.package_sha256, r.signature_algorithm, r.signing_key_id, '
            . 'r.signature_base64, e.identifier, e.extension_type, e.service_provider, e.runtime_path, '
            . 'e.status FROM %s e '
            . 'INNER JOIN %s r ON %s = %s AND r.version = e.installed_version '
            . "WHERE r.trust_state = 'needs_reverification' ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
            $releaseExtensionId,
            $extensionId,
        ));
        $runtimeChanged = false;
        foreach ($rows as $row) {
            $releaseId = $row['id'] ?? null;
            $runtimePath = $row['runtime_path'] ?? null;
            $packageDigest = $row['package_sha256'] ?? null;
            $identifier = $row['identifier'] ?? null;
            $verified = false;
            $treeDigest = null;
            $artifactMatches = false;
            if (is_string($runtimePath) && is_string($packageDigest)) {
                try {
                    $root = $this->legacyRuntimeRoot($runtimePath);
                    $artifact = $root . '/' . FilesystemExtensionArtifactVerifier::ARTIFACT;
                    $artifactDigest = is_file($artifact) && !is_link($artifact)
                        ? hash_file('sha256', $artifact)
                        : false;
                    $treeDigest = FilesystemExtensionArtifactVerifier::treeDigest($root);
                    $artifactMatches = is_string($artifactDigest) && hash_equals($packageDigest, $artifactDigest);
                    if ($artifactMatches && is_string($identifier) && is_string($row['version'] ?? null)) {
                        $archive = $this->legacyArchive($artifact);
                        $manifest = $archive['manifest'];
                        $verified = hash_equals($treeDigest, $archive['tree_digest'])
                            && $manifest->identifier()->value() === $identifier
                            && (string) $manifest->version() === $row['version']
                            && $manifest->type()->value === ($row['extension_type'] ?? null)
                            && $manifest->serviceProvider() === ($row['service_provider'] ?? null)
                            && $this->legacySignatureIsTrusted($database, $row, $identifier, $packageDigest, $now);
                    }
                } catch (Throwable) {
                    $verified = false;
                }
            }
            if (is_string($releaseId)) {
                $database->update($this->tables->raw('extension_releases'), [
                    'artifact_sha256' => $artifactMatches ? $packageDigest : null,
                    'deployed_tree_sha256' => $treeDigest,
                    'trust_state' => $verified ? 'verified' : 'needs_reverification',
                ], ['id' => $releaseId]);
            }
            if (!$verified && ($row['status'] ?? null) === 'active' && is_string($identifier)) {
                $database->executeStatement(sprintf(
                    "UPDATE %s SET status = 'needs_reverification', registry_version = registry_version + 1, "
                    . 'updated_at = ? WHERE identifier = ?',
                    $this->tables->quoted('extensions'),
                ), [$now, $identifier], [Types::DATETIME_IMMUTABLE, Types::STRING]);
                $runtimeChanged = true;
            }
        }
        if ($runtimeChanged) {
            $database->executeStatement(sprintf(
                'UPDATE %s SET generation = generation + 1, rebuilt_at = ? WHERE singleton_key = 1',
                $this->tables->quoted('extension_runtime_generation'),
            ), [$now], [Types::DATETIME_IMMUTABLE]);
            $generation = $database->fetchOne(sprintf(
                'SELECT generation FROM %s WHERE singleton_key = 1',
                $this->tables->quoted('extension_runtime_generation'),
            ));
            if (is_int($generation) || (is_string($generation) && ctype_digit($generation))) {
                $database->insert($this->tables->raw('extension_runtime_outbox'), [
                    'id' => Uuid::uuid7()->toString(),
                    'generation' => (int) $generation,
                    'event_type' => 'extension.runtime.legacy-transition',
                    'extension_identifier' => null,
                    'state' => 'pending',
                    'created_at' => $now,
                    'materialized_at' => null,
                ], ['created_at' => Types::DATETIME_IMMUTABLE, 'materialized_at' => Types::DATETIME_IMMUTABLE]);
            }
        }
    }

    /** @return array{tree_digest: string, manifest: ExtensionManifest} */
    private function legacyArchive(string $artifact): array
    {
        $package = (new ZipArchiveReader())->inspect($artifact);
        (new PackageSafetyPolicy())->assertSafe($package);
        $zip = new ZipArchive();
        if ($zip->open($artifact, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('A retained legacy package cannot be opened.');
        }
        try {
            $digests = [];
            foreach ($package->entries() as $index => $entry) {
                if ($entry->type() === ArchiveEntryType::Directory) {
                    continue;
                }
                $path = $entry->path()->value();
                if ($path === FilesystemExtensionArtifactVerifier::ARTIFACT) {
                    continue;
                }
                $bytes = $zip->getFromIndex($index, 67_108_865, ZipArchive::FL_UNCHANGED);
                if (!is_string($bytes) || strlen($bytes) > 67_108_864) {
                    throw new RuntimeException('A retained legacy package entry cannot be verified.');
                }
                $digests[$path] = hash('sha256', $bytes);
            }
            ksort($digests, SORT_STRING);
            $manifestJson = $zip->getFromName('kumwe.json', 1_048_577, ZipArchive::FL_UNCHANGED);
            if (!is_string($manifestJson) || strlen($manifestJson) > 1_048_576) {
                throw new RuntimeException('A retained legacy package manifest cannot be verified.');
            }
            return [
                'tree_digest' => hash('sha256', json_encode(
                    $digests,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )),
                'manifest' => ExtensionManifest::fromJson($manifestJson),
            ];
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $release */
    private function legacySignatureIsTrusted(
        Connection $database,
        array $release,
        string $identifier,
        string $packageDigest,
        DateTimeImmutable $now,
    ): bool {
        $keyId = $release['signing_key_id'] ?? null;
        $signature = $release['signature_base64'] ?? null;
        if (
            ($release['signature_algorithm'] ?? null) !== 'ed25519'
            || !is_string($keyId) || !is_string($signature)
        ) {
            return false;
        }
        $key = $database->fetchAssociative(sprintf(
            'SELECT algorithm, public_key_base64, enabled, vendor_namespace, extension_pattern, expires_at, '
            . 'revoked_at FROM %s WHERE key_id = ?',
            $this->tables->quoted('extension_trust_keys'),
        ), [$keyId]);
        if (
            $key === false || ($key['algorithm'] ?? null) !== 'ed25519'
            || !in_array($key['enabled'] ?? null, [true, 1, '1', 't', 'true'], true)
            || ($key['revoked_at'] ?? null) !== null || !is_string($key['public_key_base64'] ?? null)
        ) {
            return false;
        }
        $expiresAt = $key['expires_at'] ?? null;
        try {
            $expiresAt = $expiresAt instanceof DateTimeImmutable
                ? $expiresAt
                : new DateTimeImmutable((string) $expiresAt);
        } catch (Throwable) {
            return false;
        }
        [$vendor, $name] = explode('/', $identifier, 2);
        $keyVendor = $key['vendor_namespace'] ?? null;
        $pattern = $key['extension_pattern'] ?? null;
        if (
            $expiresAt <= $now || !is_string($keyVendor) || !is_string($pattern)
            || ($keyVendor !== '*' && $keyVendor !== $vendor)
            || ($pattern !== '*' && $pattern !== $name)
        ) {
            return false;
        }
        try {
            return (new SodiumTrustKeySignatureVerifier())->verify(
                $key['public_key_base64'],
                PackageChecksum::sha256($packageDigest),
                PackageSignature::ed25519($keyId, $signature),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function legacyRuntimeRoot(string $runtimePath): string
    {
        if ($runtimePath === '' || str_starts_with($runtimePath, '/') || str_contains($runtimePath, '..')) {
            throw new RuntimeException('A legacy extension runtime path is unsafe.');
        }
        $base = realpath($this->extensionRoot);
        $root = realpath($this->extensionRoot . '/' . $runtimePath);
        if (!is_string($base) || !is_string($root) || !str_starts_with($root . '/', $base . '/')) {
            throw new RuntimeException('A legacy extension runtime path is missing or unsafe.');
        }
        return $root;
    }

    private function add(
        Connection $database,
        bool $exists,
        string $table,
        string $declaration,
        ?string $column = null,
    ): void {
        if ($exists) {
            return;
        }
        $sql = $column === null ? $declaration : $column . ' ' . $declaration;
        $database->executeStatement(sprintf(
            'ALTER TABLE %s ADD %s',
            $this->tables->quoted($table),
            $sql,
        ));
    }

    private function requireExpiry(Connection $database, string $table): void
    {
        $column = $database->createSchemaManager()
            ->introspectTable($this->tables->raw($table))
            ->getColumn('expires_at');
        if ($column->getNotnull()) {
            return;
        }
        $platform = $database->getDatabasePlatform();
        if ($platform instanceof SQLitePlatform) {
            return;
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s MODIFY %s %s NOT NULL',
                $this->tables->quoted($table),
                $database->quoteSingleIdentifier('expires_at'),
                $platform->getDateTimeTypeDeclarationSQL([]),
            ));
            return;
        }
        if ($platform instanceof PostgreSQLPlatform) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s SET NOT NULL',
                $this->tables->quoted($table),
                $database->quoteSingleIdentifier('expires_at'),
            ));
            return;
        }
        throw new RuntimeException('The database platform cannot enforce mandatory security expiries.');
    }
}
