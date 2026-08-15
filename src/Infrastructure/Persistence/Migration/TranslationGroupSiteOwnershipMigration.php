<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Makes a translation group's site ownership a database-enforced relationship.
 *
 * The original multilingual migration made the group UUID globally unique but allowed an entry from a
 * different site to reference that UUID, because its foreign key named the group identifier alone. This
 * append-only repair adds the redundant group-owner column relational engines require, binds
 * `(translation_group_id, translation_group_site_identifier)` to the group's `(id, site_identifier)`,
 * and checks that the redundant owner equals the entry owner. Keeping the entry owner itself out of the
 * foreign key preserves `ON DELETE SET NULL`: deleting a group releases the translation fields without
 * trying to null the non-null site owner. The original migration is not edited, so an applied checksum
 * remains a trustworthy record of the bytes an installation ran.
 *
 * @since  2.0.0
 */
final readonly class TranslationGroupSiteOwnershipMigration implements RepeatableMigration
{
    /**
     * Stable ordered identity after the multilingual content migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260819020000_translation_group_site_ownership';

    /**
     * Bind the repair to the installation's physical table names.
     *
     * @param  TableNames  $tables  Prefix-aware table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only migration identity.
     *
     * @return  string  Stable ordered identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind migration compatibility to the exact implementation bytes.
     *
     * @return  string  Lowercase SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When this source file cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The translation group ownership migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Refuse contradictory rows, then add the composite owner key and foreign key.
     *
     * @param   Connection  $database  Installation database being repaired.
     *
     * @return  void
     *
     * @throws  RuntimeException  When existing data already crosses a site boundary.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects inspection or a schema statement.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $entriesName = $this->tables->raw('content_entries');
        $groupsName = $this->tables->raw('content_translation_groups');
        $contradictions = $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s e INNER JOIN %s g ON g.id = e.translation_group_id '
            . 'WHERE e.site_identifier <> g.site_identifier',
            $this->tables->quoted('content_entries'),
            $this->tables->quoted('content_translation_groups'),
        ));
        if ((int) $contradictions > 0) {
            throw new RuntimeException(
                'Translation group site ownership cannot be enforced while cross-site members exist.',
            );
        }

        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        $entries = $after->getTable($entriesName);
        $groups = $after->getTable($groupsName);
        if (!$entries->hasColumn('translation_group_site_identifier')) {
            $entries->addColumn('translation_group_site_identifier', Types::STRING, [
                'length' => 191,
                'notnull' => false,
            ]);
            if ($database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $this->copyCharacterDefinition(
                    $entries->getColumn('site_identifier'),
                    $entries->getColumn('translation_group_site_identifier'),
                );
            }
        }
        $unique = $this->uniqueIndexName($groupsName);
        if (!$groups->hasIndex($unique)) {
            $groups->addUniqueIndex(['id', 'site_identifier'], $unique);
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }

        $database->executeStatement(sprintf(
            'UPDATE %s SET translation_group_site_identifier = site_identifier '
            . 'WHERE translation_group_id IS NOT NULL '
            . 'AND (translation_group_site_identifier IS NULL '
            . 'OR translation_group_site_identifier <> site_identifier)',
            $this->tables->quoted('content_entries'),
        ));

        $before = $manager->introspectSchema();
        $after = clone $before;
        $entries = $after->getTable($entriesName);
        $foreignKey = $this->foreignKeyName($entriesName);
        if (!$entries->hasForeignKey($foreignKey)) {
            $entries->addForeignKeyConstraint(
                $groupsName,
                ['translation_group_id', 'translation_group_site_identifier'],
                ['id', 'site_identifier'],
                ['onDelete' => 'SET NULL'],
                $foreignKey,
            );
        }

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
        $this->addOwnerCheckConstraint($database, $entriesName);
    }

    /**
     * Derive a globally collision-resistant foreign-key name from its table and columns.
     *
     * @param   string  $table  Physical content-entry table name.
     *
     * @return  string  Stable name inside every supported engine's identifier limit.
     *
     * @since   2.0.0
     */
    private function foreignKeyName(string $table): string
    {
        return 'fk_' . substr(
            hash('sha256', $table . ':translation_group_id:translation_group_site_identifier'),
            0,
            24,
        );
    }

    /**
     * Derive a schema-global unique-index name from the physical group table.
     *
     * PostgreSQL puts index names in the schema namespace, so two installations with different table
     * prefixes cannot safely share a literal index name even though MySQL scopes one to its table.
     *
     * @param   string  $table  Physical translation-group table name.
     *
     * @return  string  Stable name inside the portable identifier limit.
     *
     * @since   2.0.0
     */
    private function uniqueIndexName(string $table): string
    {
        return 'uniq_' . substr(hash('sha256', $table . ':id:site_identifier'), 0, 24);
    }

    /**
     * Copy the entry owner's character definition onto its nullable group-owner mirror.
     *
     * @param   Column  $source  Authoritative site-identifier column.
     * @param   Column  $target  Nullable group-owner mirror used by the composite foreign key.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a MySQL-family source has no character definition to copy.
     *
     * @since   2.0.0
     */
    private function copyCharacterDefinition(Column $source, Column $target): void
    {
        $charset = $source->getCharset();
        $collation = $source->getCollation();
        if ($charset === null || $collation === null) {
            throw new RuntimeException('The content entry site identifier has no character definition to copy.');
        }

        $target->setPlatformOption('charset', $charset);
        $target->setPlatformOption('collation', $collation);
    }

    /**
     * Require the nullable group owner to be absent with the group or equal to the entry owner.
     *
     * @param   Connection  $database  Installation database being repaired.
     * @param   string      $table     Physical content-entry table name.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When a non-duplicate driver failure occurs.
     *
     * @since   2.0.0
     */
    private function addOwnerCheckConstraint(Connection $database, string $table): void
    {
        $constraint = 'ck_' . substr(hash('sha256', $table . ':translation_group_site_owner'), 0, 24);
        try {
            $database->executeStatement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK ('
                . '(translation_group_id IS NULL AND translation_group_site_identifier IS NULL) OR '
                . '(translation_group_id IS NOT NULL AND translation_group_site_identifier IS NOT NULL '
                . 'AND translation_group_site_identifier = site_identifier))',
                $database->quoteSingleIdentifier($table),
                $database->quoteSingleIdentifier($constraint),
            ));
        } catch (\Doctrine\DBAL\Exception $failure) {
            $message = strtolower($failure->getMessage());
            if (
                !str_contains($message, 'already exists')
                && !str_contains($message, 'duplicate check constraint')
                && !str_contains($message, 'duplicate key name')
            ) {
                throw $failure;
            }
        }
    }
}
