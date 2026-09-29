<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Preserve exact authored field identities and the presentation with which a session started.
 *
 * Existing Content bindings keep their established prefixed field mapping. Previously opened sessions
 * have no recorded initial presentation and must be reopened; guessing it would corrupt reconciliation.
 *
 * @since  2.0.0
 */
final readonly class StudioAuthoringIdentityMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260928090000_studio_authoring_identity';

    /**
     * Bind the migration to the installation's prefix-aware table names.
     *
     * @param   TableNames  $tables  Physical table-name compiler.
     *
     * @since   2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the immutable schema-ledger identity.
     *
     * @return  string  Stable migration version.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind applied history to these exact migration bytes.
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
            throw new RuntimeException('The Studio authoring identity migration checksum is unavailable.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Add the optional immutable field map and initial session presentation.
     *
     * @param   Connection  $database  Installation database holding the authoring context table.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the authoring context table is missing.
     * @throws  \Doctrine\DBAL\Exception  When the schema cannot be introspected or altered.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $name = $this->tables->raw('studio_content_authoring_contexts');
        if (!$after->hasTable($name)) {
            throw new RuntimeException('The Studio Content authoring context table must exist before its start.');
        }
        $table = $after->getTable($name);
        if (!$table->hasColumn('initial_presentation')) {
            $table->addColumn('initial_presentation', Types::STRING, ['length' => 16, 'notnull' => false]);
        }
        $bindings = $after->getTable($this->tables->raw('studio_content_blueprint_bindings'));
        if (!$bindings->hasColumn('field_ids')) {
            $bindings->addColumn('field_ids', Types::TEXT, ['notnull' => false]);
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
