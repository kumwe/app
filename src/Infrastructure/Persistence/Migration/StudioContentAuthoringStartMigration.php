<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Records the start source a contextual Content authoring session chose.
 *
 * Studio reconciles every save result against the start the session opened with, so a session that
 * started blank or from one reusable type must keep reporting that exact start after it saves a new
 * type or creates its item. The start is chosen once, inside the session, after the opaque context was
 * minted; this append-only migration adds the nullable column that records it without changing the
 * bytes, and therefore the checksum, of the migration that created the context table.
 *
 * @since  2.0.0
 */
final readonly class StudioContentAuthoringStartMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260924060000_studio_content_authoring_start';

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
            throw new RuntimeException('The Studio Content authoring start migration checksum is unavailable.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Add the nullable canonical start-source column when it is absent.
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
        if (!$table->hasColumn('start_source')) {
            $table->addColumn('start_source', Types::STRING, ['length' => 1000, 'notnull' => false]);
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
