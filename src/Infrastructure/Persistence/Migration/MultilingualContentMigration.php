<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Gives content a language, a translation group, and the constraints that keep the group coherent.
 *
 * Three things arrive together because none of them means anything alone. `content_entries` gains
 * `locale` and `translation_group_id`, so one entry is one locale of one logical item and keeps its own
 * workflow state and publication window — which is what lets English be live while another language is
 * still drafting. `content_translation_groups` holds the one fact no member can hold, the declared
 * fallback locale served when the negotiated language has no published entry. And a unique index over
 * `(translation_group_id, locale)` makes "one entry per locale" a property of the database rather than a
 * hope about the application, alongside the `uniq_content_site_slug` index the content model already
 * carries, which is what proves two locales of one item never collide on a route segment.
 *
 * Both new columns are nullable and nothing is backfilled. An entry authored before content carried a
 * language dimension keeps reading as content whose language nobody has declared, so its stored revision
 * checksums stay valid and a single-language site is completely unaffected by the change. All three
 * engines admit repeated nulls in a unique index, so those rows sit under the constraint without
 * contending for it.
 *
 * Two portability hazards are handled explicitly. On MySQL and MariaDB the new group identifier copies
 * the exact character set and collation of `content_entries.id`, because a foreign key between textual
 * GUIDs of differing collations is refused outright. And the foreign-key name is derived from a digest
 * of the table and column rather than written as a readable literal, because InnoDB names constraints in
 * a dictionary shared far more widely than one table.
 *
 * @since  2.0.0
 */
final readonly class MultilingualContentMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after every migration this release already carries.
     *
     * `MigrationPlan` requires an installation's applied ledger to be an exact prefix of the sorted
     * plan, so a new identity has to sort above the highest one already released — which is why this
     * sits a day past the ownership-scope and interface-override migrations rather than beside them.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260818010000_multilingual_content';

    /**
     * Widest language tag the locale columns accept, in characters.
     *
     * A normalised `LocaleTag` is at most twelve characters — three for the language, four for the
     * script, three for the region and two hyphens — so 35 leaves room for a wider tag grammar without
     * a further migration while staying far inside every engine's index-width limit.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int LOCALE_LENGTH = 35;

    /**
     * Bind the migration to the installation's physical table names.
     *
     * @param  TableNames  $tables  Prefix-aware table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only migration identity stored in the schema ledger.
     *
     * @return  string  Stable ordered migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind migration compatibility to the exact bytes of this implementation.
     *
     * @return  string  Lowercase 64-character SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When this source file cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The multilingual content migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Add the group table, the two entry columns, their indexes and the group foreign key.
     *
     * The whole change is expressed as one schema difference so DBAL emits the statements each platform
     * needs, and every addition is guarded by a `has` check so a re-run after an interrupted attempt is
     * a no-op rather than a duplicate-object failure.
     *
     * @param   Connection  $database  Installation database the language dimension is added to.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the MySQL-family character definition of the entry identifier
     *          cannot be read, so a compatible foreign key cannot be created.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $this->extend($after, $database->getDatabasePlatform() instanceof AbstractMySQLPlatform);
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Describe the language dimension against an introspected schema.
     *
     * @param   Schema  $schema       Introspected schema being extended in place.
     * @param   bool    $mysqlFamily  Whether the platform stores GUIDs as character data and therefore
     *          needs the identifier's character definition copied onto the referencing column.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a MySQL-family entry identifier carries no character definition.
     *
     * @since   2.0.0
     */
    private function extend(Schema $schema, bool $mysqlFamily): void
    {
        $entries = $schema->getTable($this->tables->raw('content_entries'));
        $identifier = $entries->getColumn('id');

        $groupsName = $this->tables->raw('content_translation_groups');
        if (!$schema->hasTable($groupsName)) {
            $groups = $schema->createTable($groupsName);
            $groups->addColumn('id', Types::GUID);
            $groups->addColumn('site_identifier', Types::STRING, ['length' => 191]);
            $groups->addColumn('fallback_locale', Types::STRING, ['length' => self::LOCALE_LENGTH]);
            $groups->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create(),
            );
            $groups->addIndex(['site_identifier'], 'idx_translation_group_site');
            if ($mysqlFamily) {
                $this->copyCharacterDefinition($identifier, $groups->getColumn('id'));
                $this->copyCharacterDefinition(
                    $entries->getColumn('site_identifier'),
                    $groups->getColumn('site_identifier'),
                );
            }
        }

        if (!$entries->hasColumn('locale')) {
            $entries->addColumn('locale', Types::STRING, [
                'length' => self::LOCALE_LENGTH,
                'notnull' => false,
            ]);
        }
        if (!$entries->hasColumn('translation_group_id')) {
            $entries->addColumn('translation_group_id', Types::GUID, ['notnull' => false]);
            if ($mysqlFamily) {
                $this->copyCharacterDefinition($identifier, $entries->getColumn('translation_group_id'));
            }
        }
        if (!$entries->hasIndex('uniq_content_translation_locale')) {
            $entries->addUniqueIndex(['translation_group_id', 'locale'], 'uniq_content_translation_locale');
        }
        if (!$entries->hasIndex('idx_content_site_locale')) {
            $entries->addIndex(['site_identifier', 'locale'], 'idx_content_site_locale');
        }

        $foreignKey = $this->foreignKeyName($this->tables->raw('content_entries'), 'translation_group_id');
        if (!$entries->hasForeignKey($foreignKey)) {
            $entries->addForeignKeyConstraint(
                $groupsName,
                ['translation_group_id'],
                ['id'],
                ['onDelete' => 'SET NULL'],
                $foreignKey,
            );
        }
    }

    /**
     * Derive a foreign-key name that cannot collide with another table's constraint.
     *
     * InnoDB keeps constraint names in a dictionary far wider than the table they belong to, and a
     * readable literal such as `fk_entry_group` is exactly the kind of name two unrelated tables end up
     * claiming. Hashing the table and column makes the name unique by construction while staying stable
     * across runs, which is what lets the `has` check above recognise its own earlier work.
     *
     * @param   string  $table   Unprefixed-through-`TableNames` physical table name.
     * @param   string  $column  Referencing column the constraint is declared on.
     *
     * @return  string  A 27-character constraint name, well inside every engine's identifier limit.
     *
     * @since   2.0.0
     */
    private function foreignKeyName(string $table, string $column): string
    {
        return 'fk_' . substr(hash('sha256', $table . ':' . $column), 0, 24);
    }

    /**
     * Copy only the character set and collation of one column onto another.
     *
     * @param   Column  $source  Column supplying the authoritative character definition.
     * @param   Column  $target  Column that must join or reference it without a collation mismatch.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the source column carries no character definition to copy.
     *
     * @since   2.0.0
     */
    private function copyCharacterDefinition(Column $source, Column $target): void
    {
        $charset = $source->getCharset();
        $collation = $source->getCollation();
        if ($charset === null || $collation === null) {
            throw new RuntimeException('The content entry identifier has no character definition to copy.');
        }

        $target->setPlatformOption('charset', $charset);
        $target->setPlatformOption('collation', $collation);
    }
}
