<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds the per-item layout pointer and the handed-type fence of contextual Content authoring.
 *
 * An entry may keep its own layout as an immutable Studio Blueprint artifact; the existing per-entry
 * composition override record pins the exact revision it uses, and without that pointer the entry
 * follows its content type's layout. Each authoring context records a digest of the reusable content
 * type it last handed its session, so an item save is refused before any effect when the type moved
 * meanwhile. Both are nullable columns added to existing tables without a default, so no stored row
 * is rewritten, and this append-only migration leaves the bytes, and therefore the checksums, of the
 * migrations that created those tables unchanged. Every column is added only when it is absent, so an
 * interrupted attempt may be replayed in full.
 *
 * @since  2.0.0
 */
final readonly class StudioItemCompositionMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20261008120000_studio_item_composition';

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
            throw new RuntimeException('The Studio item composition migration checksum is unavailable.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Add the nullable item layout pointer and handed-type digest columns when they are absent.
     *
     * @param   Connection  $database  Installation database holding both parent tables.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a parent table is missing.
     * @throws  \Doctrine\DBAL\Exception  When the schema cannot be introspected or altered.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $overridesName = $this->tables->raw('studio_entry_composition_overrides');
        if (!$after->hasTable($overridesName)) {
            throw new RuntimeException('The Studio entry composition override table must exist before its layout.');
        }
        $contextsName = $this->tables->raw('studio_content_authoring_contexts');
        if (!$after->hasTable($contextsName)) {
            throw new RuntimeException('The Studio Content authoring context table must exist before its type fence.');
        }
        $overrides = $after->getTable($overridesName);
        if (!$overrides->hasColumn('item_blueprint_revision')) {
            $overrides->addColumn('item_blueprint_revision', Types::STRING, ['length' => 200, 'notnull' => false]);
        }
        $contexts = $after->getTable($contextsName);
        if (!$contexts->hasColumn('handed_type_digest')) {
            $contexts->addColumn('handed_type_digest', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
