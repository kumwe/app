<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/** Adds revocable token epochs and a managed, constrained extension trust store. */
final readonly class TokenAndTrustLifecycleMigration implements Migration
{
    public const ID = '20260805040000_token_and_trust_lifecycles';

    public function __construct(private TableNames $tables)
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
        $users = $schema->introspectTableByUnquotedName($this->tables->raw('users'));
        if (!$users->hasColumn('security_epoch')) {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD security_epoch BIGINT NOT NULL DEFAULT 1',
                $this->tables->quoted('users'),
            ));
        }

        $tokens = $schema->introspectTableByUnquotedName($this->tables->raw('api_tokens'));
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
        $tokens = $schema->introspectTableByUnquotedName($this->tables->raw('api_tokens'));
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

        $keys = $schema->introspectTableByUnquotedName($this->tables->raw('extension_trust_keys'));
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

        $releases = $schema->introspectTableByUnquotedName($this->tables->raw('extension_releases'));
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

        $idempotency = $schema->introspectTableByUnquotedName($this->tables->raw('idempotency'));
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
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('singleton_key')->create(),
            );
            foreach ($generationSchema->toSql($platform) as $statement) {
                $database->executeStatement($statement);
            }
        }
        $trustGenerationSchema = $database->createSchemaManager()->introspectTableByUnquotedName($trustGeneration);
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
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
            );
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
        // Advance even when every existing release is valid: the publication schema itself changed.
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
            ->introspectTableByUnquotedName($this->tables->raw($table))
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
