<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds the optional per-menu presentation binding columns to navigation items.
 *
 * A published page's layout resolves from its content type, and the whole site shares one active
 * colour scheme; these two nullable columns let a single menu item override either decision for the
 * page it links, which is how one navigation tree presents a documentation section, a landing
 * campaign, and an article stream each in its own dress without forking the content model. Null
 * keeps today's behaviour byte-for-byte, so existing menus migrate without change.
 *
 * @since  2.0.0
 */
final readonly class MenuPresentationBindingMigration implements RepeatableMigration
{
    /**
     * Stable migration identity recorded in the schema ledger.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ID = '20260812120000_menu_presentation_binding';

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
            throw new RuntimeException('The menu presentation migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Add the nullable `template` and `color_scheme` columns where they are absent.
     *
     * @param   Connection  $database  Connection the schema change runs on.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the schema change.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $items = $after->getTable($this->tables->raw('navigation_items'));
        if (!$items->hasColumn('template')) {
            $items->addColumn('template', Types::STRING, ['length' => 64, 'notnull' => false]);
        }
        if (!$items->hasColumn('color_scheme')) {
            $items->addColumn('color_scheme', Types::STRING, ['length' => 64, 'notnull' => false]);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
