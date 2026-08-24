<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds durable, scoped Studio media upload-session persistence.
 *
 * @since  2.0.0
 */
final readonly class StudioMediaUploadMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity for Studio media upload custody.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260824030000_studio_media_uploads';

    /**
     * Bind DDL to prefix-aware installation table names.
     *
     * @param  TableNames  $tables  Installation table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only identifier recorded by the migration repository.
     *
     * @return  string  Stable Studio media upload migration identity.
     *
     * @since  2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind the migration record to this implementation's exact source bytes.
     *
     * @return  string  Stable migration identifier and source digest.
     *
     * @since  2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio media migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Add the scoped upload-session table and its lookup and expiry indexes idempotently.
     *
     * The scope index deliberately stops before the 200-character session generation. Its three
     * indexed identifiers occupy at most 622 characters, or 2488 bytes under utf8mb4, while adding
     * the generation would exceed InnoDB's portable 3072-byte key limit. Repository lookups still
     * compare the generation as an exact residual predicate.
     *
     * @param   Connection  $database  Installation connection to migrate.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $name = $this->tables->raw('studio_media_uploads');
        $table = $after->hasTable($name) ? $after->getTable($name) : $after->createTable($name);
        $columns = [
            'id' => [Types::STRING, ['length' => 240]],
            'actor_id' => [Types::STRING, ['length' => 191]],
            'site_identifier' => [Types::STRING, ['length' => 191]],
            'resource_context_key' => [Types::STRING, ['length' => 240]],
            'session_generation' => [Types::STRING, ['length' => 200]],
            'request' => [Types::JSON, []],
            'plan' => [Types::JSON, []],
            'state' => [Types::STRING, ['length' => 20]],
            'transferred_bytes' => [Types::BIGINT, []],
            'token_digest' => [Types::STRING, ['length' => 64, 'fixed' => true]],
            'expires_at' => [Types::DATETIME_IMMUTABLE, []],
            'asset_id' => [Types::STRING, ['length' => 240, 'notnull' => false]],
            'asset_revision' => [Types::STRING, ['length' => 200, 'notnull' => false]],
            'asset_state' => [Types::STRING, ['length' => 20, 'notnull' => false]],
            'failure_code' => [Types::STRING, ['length' => 191, 'notnull' => false]],
            'version' => [Types::INTEGER, []],
        ];
        foreach ($columns as $column => [$type, $options]) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, $type, $options);
            }
        }
        if ($table->getPrimaryKeyConstraint() === null) {
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
            );
        }
        $scope = ConstraintNameIsolationMigration::isolatedName($name, 'idx_studio_media_upload_scope');
        if (!$table->hasIndex($scope)) {
            $table->addIndex(
                ['actor_id', 'site_identifier', 'resource_context_key'],
                $scope,
            );
        }
        $expiry = ConstraintNameIsolationMigration::isolatedName($name, 'idx_studio_media_upload_expiry');
        if (!$table->hasIndex($expiry)) {
            $table->addIndex(['expires_at'], $expiry);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
