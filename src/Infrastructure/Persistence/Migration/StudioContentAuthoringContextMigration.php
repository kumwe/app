<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds immutable opaque bindings for contextual Content authoring targets.
 *
 * The table stores no cookie, CSRF value, raw capability map, endpoint, or Studio configuration. A row
 * retains only one-way authority/session bindings, authenticated scope, and the exact App-native target
 * needed to re-resolve and re-authorize every future operation.
 *
 * @since  2.0.0
 */
final readonly class StudioContentAuthoringContextMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260827010000_studio_content_authoring_contexts';

    /**
     * Bind DDL to prefix-aware table names.
     *
     * @param  TableNames  $tables  Installation table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the immutable ledger identity.
     *
     * @return  string  Stable migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind applied history to this exact migration source.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When the source digest cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio Content authoring context migration checksum is unavailable.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Create or reconcile the immutable contextual authoring binding store.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When schema persistence fails.
     * @throws  RuntimeException  When a partial table has an incompatible primary key.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $name = $this->tables->raw('studio_content_authoring_contexts');
        $table = $after->hasTable($name) ? $after->getTable($name) : $after->createTable($name);

        foreach (
            [
                'context_key' => 240,
                'actor_id' => 191,
                'site_identifier' => 191,
                'surface' => 63,
                'session_binding' => 64,
                'authority_binding' => 64,
                'intent' => 16,
                'return_path' => 500,
            ] as $column => $length
        ) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, Types::STRING, ['length' => $length]);
            }
        }
        foreach (['created_at', 'expires_at'] as $column) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, Types::DATETIME_IMMUTABLE);
            }
        }
        foreach (
            [
                'organization_identifier' => 191,
                'workspace_identifier' => 191,
                'model_identifier' => 240,
                'model_version' => 80,
                'model_revision' => 200,
                'entry_identifier' => 240,
                'entry_revision' => 200,
            ] as $column => $length
        ) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, Types::STRING, ['length' => $length, 'notnull' => false]);
            }
        }
        $primary = $table->getPrimaryKeyConstraint();
        if ($primary === null) {
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('context_key')->create(),
            );
        } else {
            $columns = array_map(
                static fn (Name $column): string => $column->toString(),
                $primary->getColumnNames(),
            );
            if ($columns !== ['context_key']) {
                throw new RuntimeException(
                    'A partial Studio Content authoring context table has an incompatible primary key.',
                );
            }
        }
        $expiry = ConstraintNameIsolationMigration::isolatedName(
            $name,
            'idx_studio_content_authoring_context_expiry',
        );
        if (!$table->hasIndex($expiry)) {
            $table->addIndex(['expires_at'], $expiry);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
