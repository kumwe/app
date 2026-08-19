<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds the capability that authorizes record-secret re-encryption to an already-installed permission set.
 *
 * The key ring itself needs no schema: an envelope has recorded the identifier of the key that sealed it
 * since the first release, which is exactly what a ring resolves against, so rotation reads and writes
 * columns that already exist. What an existing installation is missing is the vocabulary — a fresh
 * install picks `business.record.rekey` up from `CoreExtensionContributions`, an upgraded one has a
 * permission table that was populated before the capability existed — and that is all this migration
 * supplies, to the catalog and to every administrator role.
 *
 * Deliberately no DDL, no trigger, and no privileged operation. It runs identically on MariaDB, MySQL,
 * PostgreSQL and SQLite, and needs nothing beyond the INSERT rights the application already holds, so a
 * managed MySQL service with binary logging and no `SUPER` applies it like any other. Every write is
 * guarded by a read of its own natural key and the seeded identifiers are derived from that key, so a
 * replay after an interrupted attempt inserts nothing twice.
 *
 * @since  2.0.0
 */
final readonly class RecordEncryptionKeyRingMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260813020000_record_encryption_key_ring';

    /**
     * Capabilities this migration adds to the permission vocabulary, with their operator wording.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array CAPABILITIES = [
        'business.record.rekey' => 'Re-encrypt stored business-record secrets under the active key.',
    ];

    /**
     * Bind the migration to the prefixed table map.
     *
     * @param  TableNames  $tables  Resolver applying the configured prefix to table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Name the identity recorded for this migration in the schema ledger.
     *
     * @return  string  The stable migration identifier.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Derive the ledger checksum from this file's bytes so any edit is detected.
     *
     * @return  string  Stable digest binding the recorded version to this exact implementation.
     *
     * @throws  RuntimeException  When the file digest cannot be calculated.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The record encryption key-ring migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Seed the re-keying capability and prove it is present before the migration is recorded.
     *
     * @param   Connection  $database  Connection the seed runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stored administrator role identity is invalid, or the capability
     *          is absent after seeding.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects a statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $this->seedCapabilities($database);
        $this->assertApplied($database);
    }

    /**
     * Insert each capability the installation is missing and grant it to every administrator role.
     *
     * The optional columns are written only when the installed catalog carries them, so this applies
     * equally to a site that has already taken the extension-contribution catalog migration and to one
     * that has not.
     *
     * @param   Connection  $database  Connection the seed runs on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a stored administrator role identity is invalid.
     *
     * @since   2.0.0
     */
    private function seedCapabilities(Connection $database): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $columns = $database->createSchemaManager()
            ->introspectTableByUnquotedName($this->tables->raw('capabilities'));
        foreach (self::CAPABILITIES as $code => $description) {
            $exists = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if ($exists === false) {
                $values = ['code' => $code, 'description' => $description];
                $types = [];
                if ($columns->hasColumn('owner_kind')) {
                    $values['owner_kind'] = 'core';
                    $values['owner_identifier'] = 'core';
                    $values['allowed_scopes'] = json_encode(
                        ['business_record', 'global', 'site'],
                        JSON_THROW_ON_ERROR,
                    );
                    $values['delegable'] = true;
                    $values['high_impact'] = true;
                    $values['definition_version'] = 1;
                    $values['definition_checksum'] = hash('sha256', 'kumwe-core-capability-catalog-v1:' . $code);
                    $values['lifecycle_state'] = 'active';
                    $types = ['delegable' => Types::BOOLEAN, 'high_impact' => Types::BOOLEAN];
                }
                $database->insert($this->tables->raw('capabilities'), $values, $types);
            }
            $roles = $database->fetchFirstColumn(sprintf(
                'SELECT id FROM %s WHERE code = ? ORDER BY id',
                $this->tables->quoted('roles'),
            ), ['administrator']);
            foreach ($roles as $roleId) {
                if (!is_string($roleId) || $roleId === '') {
                    throw new RuntimeException('A stored administrator role identity is invalid.');
                }
                $granted = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? AND scope_type = ?',
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $code, 'global']);
                if ($granted !== false) {
                    continue;
                }
                $database->insert($this->tables->raw('role_capability_grants'), [
                    'id' => $this->identifierFor('grant', $roleId . '|' . $code),
                    'role_id' => $roleId,
                    'capability_code' => $code,
                    'scope_type' => 'global',
                    'scope_identifier' => null,
                    'granted_at' => $now,
                    'granted_by' => null,
                ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
            }
        }
    }

    /**
     * Prove the capability this migration exists to add is present before it is recorded as applied.
     *
     * @param   Connection  $database  Connection the checks run on.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a capability is still absent from the catalog.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the read.
     *
     * @since   2.0.0
     */
    private function assertApplied(Connection $database): void
    {
        foreach (array_keys(self::CAPABILITIES) as $code) {
            $stored = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$code]);
            if ($stored !== $code) {
                throw new RuntimeException('The record re-keying capability is missing after seeding.');
            }
        }
    }

    /**
     * Derive a stable UUIDv7-shaped identifier for a seeded row from its natural key.
     *
     * A deterministic identifier keeps a replayed migration from inserting a second grant row for the
     * same role and capability on a platform where the guarding read and the insert are not atomic.
     *
     * @param   string  $namespace  Row kind the identifier belongs to, such as `grant`.
     * @param   string  $key        Natural key of the row within that kind.
     *
     * @return  string  Canonical UUID carrying version 7 and variant bits.
     *
     * @since   2.0.0
     */
    private function identifierFor(string $namespace, string $key): string
    {
        $digest = hash('sha256', self::ID . ':' . $namespace . ':' . $key);

        return sprintf(
            '%s-%s-7%s-%x%s-%s',
            substr($digest, 0, 8),
            substr($digest, 8, 4),
            substr($digest, 13, 3),
            8 + (hexdec($digest[16]) % 4),
            substr($digest, 17, 3),
            substr($digest, 20, 12),
        );
    }
}
