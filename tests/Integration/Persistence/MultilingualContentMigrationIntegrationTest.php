<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DriverException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MultilingualContentMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\TranslationGroupSiteOwnershipMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Drives the language-dimension migration itself, rather than inspecting a schema it already produced.
 *
 * An installed database says only that the migration ran once, somewhere, at some version of its source;
 * it cannot say that a re-run after an interrupted attempt is a no-op, that the derived constraint name is
 * the one the `has` check recognises, or that the migration refuses an entry identifier it cannot build a
 * compatible foreign key against. Those are properties of the transformation, so they are proven by
 * applying the transformation here — to a private copy of `content_entries` under a per-test prefix, so a
 * run cannot disturb the installation the rest of the suite shares.
 *
 * The suite is MySQL-family only. Copying a character definition onto the referencing column exists
 * because InnoDB refuses a foreign key between textual GUIDs of differing collations, and that hazard has
 * no counterpart on PostgreSQL; the portable half of the migration is already proven on all three engines
 * by `MultilingualContentIntegrationTest`.
 *
 * @since  2.0.0
 */
#[CoversClass(MultilingualContentMigration::class)]
#[CoversClass(TranslationGroupSiteOwnershipMigration::class)]
final class MultilingualContentMigrationIntegrationTest extends TestCase
{
    /**
     * Character definition the entry identifier and its referencing column must end up sharing.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string IDENTIFIER_COLLATION = 'utf8mb4_unicode_ci';

    /**
     * Prove one application installs the whole language dimension and a second changes nothing.
     *
     * The second run is the point. Every addition is guarded by a `has` check, and the foreign-key guard
     * can only recognise its own earlier work if the digest-derived name is stable across runs, so a
     * replay that produced a second constraint — or failed outright — would mean an interrupted upgrade
     * could never be resumed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneApplicationInstallsTheLanguageDimensionAndAReplayChangesNothing(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        try {
            $this->createEntriesTable($database, $tables, 'CHAR(36) CHARACTER SET utf8mb4 COLLATE %s NOT NULL');
            $migration = new MultilingualContentMigration($tables);
            $migration->up($database);
            $migration->up($database);
            $ownership = new TranslationGroupSiteOwnershipMigration($tables);
            $ownership->up($database);
            $ownership->up($database);

            $manager = $database->createSchemaManager();
            $entries = $manager->introspectTableByUnquotedName($tables->raw('content_entries'));
            $groups = $manager->introspectTableByUnquotedName($tables->raw('content_translation_groups'));

            self::assertTrue($groups->hasColumn('fallback_locale'));
            self::assertTrue($groups->hasIndex('idx_translation_group_site'));
            self::assertTrue($entries->hasIndex('uniq_content_translation_locale'));
            self::assertTrue($entries->getIndex('uniq_content_translation_locale')->isUnique());
            self::assertTrue($entries->hasIndex('idx_content_site_locale'));
            self::assertFalse($entries->getIndex('idx_content_site_locale')->isUnique());

            // Nothing is backfilled, so both new columns have to admit the null an existing row keeps.
            self::assertFalse($entries->getColumn('locale')->getNotnull());
            self::assertFalse($entries->getColumn('translation_group_id')->getNotnull());
            self::assertFalse($entries->getColumn('translation_group_site_identifier')->getNotnull());

            $identifier = $entries->getColumn('id');
            self::assertSame(self::IDENTIFIER_COLLATION, $identifier->getCollation());
            foreach ([$entries->getColumn('translation_group_id'), $groups->getColumn('id')] as $column) {
                self::assertSame($identifier->getCharset(), $column->getCharset());
                self::assertSame($identifier->getCollation(), $column->getCollation());
            }

            $foreignKeys = $entries->getForeignKeys();
            self::assertCount(2, $foreignKeys, 'A replay must not duplicate either group constraint.');
            foreach ($foreignKeys as $name => $foreignKey) {
                self::assertMatchesRegularExpression('/^fk_[0-9a-f]{24}$/D', (string) $name);
                self::assertSame(ReferentialAction::SET_NULL, $foreignKey->getOnDeleteAction());
            }
            self::assertTrue($entries->hasColumn('translation_group_owner_valid'));
            $ownerColumn = $database->fetchAssociative(
                'SELECT DATA_TYPE AS data_type, EXTRA AS extra, '
                . 'GENERATION_EXPRESSION AS generation_expression FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$entriesName, 'translation_group_owner_valid'],
            );
            self::assertIsArray($ownerColumn);
            self::assertSame('tinyint', strtolower((string) ($ownerColumn['data_type'] ?? '')));
            self::assertStringContainsString('virtual', strtolower((string) ($ownerColumn['extra'] ?? '')));
            self::assertStringContainsString('generated', strtolower((string) ($ownerColumn['extra'] ?? '')));
            $generation = strtolower((string) ($ownerColumn['generation_expression'] ?? ''));
            self::assertStringContainsString('translation_group_id', $generation);
            self::assertStringContainsString('translation_group_site_identifier', $generation);
            self::assertStringContainsString('site_identifier', $generation);

            $checks = $database->fetchAllAssociative(
                'SELECT c.CONSTRAINT_NAME AS constraint_name, c.CHECK_CLAUSE AS check_clause '
                . 'FROM information_schema.CHECK_CONSTRAINTS c '
                . 'INNER JOIN information_schema.TABLE_CONSTRAINTS t '
                . 'ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME '
                . "WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = 'CHECK'",
                [$entriesName],
            );
            self::assertCount(1, $checks, 'A replay must retain exactly one generated ownership check.');
            $checkName = (string) ($checks[0]['constraint_name'] ?? '');
            self::assertMatchesRegularExpression('/^ck_[0-9a-f]{24}$/D', $checkName);
            self::assertLessThanOrEqual(63, strlen($checkName));
            $checkClause = strtolower((string) ($checks[0]['check_clause'] ?? ''));
            $normalizedCheck = preg_replace('/[\s`()]+/', '', $checkClause);
            self::assertSame('translation_group_owner_valid=1', $normalizedCheck);
            self::assertSame('0', (string) $database->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TRIGGERS '
                . 'WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
                [$entriesName],
            ), 'Ownership enforcement must not require managed-database trigger privileges.');
        } finally {
            $this->dropTables($database, $tables);
        }
    }

    /**
     * Prove the installed constraints are the database's own rules rather than an application convention.
     *
     * The engine must accept both valid ownership states, refuse either half-null pair and a cross-site
     * owner on insert, refuse an update that makes a valid owner cross-site, and let the composite foreign
     * key null both columns when its group is deleted. Locale uniqueness and repeated ungrouped nulls are
     * exercised beside those ownership rules so the complete translated-entry shape stays proved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheInstalledConstraintsAreEnforcedByTheEngineItself(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        try {
            $this->createEntriesTable($database, $tables, 'CHAR(36) CHARACTER SET utf8mb4 COLLATE %s NOT NULL');
            (new MultilingualContentMigration($tables))->up($database);
            (new TranslationGroupSiteOwnershipMigration($tables))->up($database);

            $group = '018f22e2-7c8b-7ab0-8f3a-88e8026bd001';
            $database->insert($tables->raw('content_translation_groups'), [
                'id' => $group,
                'site_identifier' => 'default',
                'fallback_locale' => 'en-GB',
            ]);
            $this->insertEntry($database, $tables, 'b0001', 'en-GB', $group);
            $this->insertEntry($database, $tables, 'b0002', 'de', $group);
            $this->insertEntry($database, $tables, 'b0003', null, null);
            $this->insertEntry($database, $tables, 'b0004', null, null);

            $this->assertDriverRefuses(
                function () use ($database, $tables, $group): void {
                    $database->insert($tables->raw('content_entries'), [
                        'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026b0007',
                        'site_identifier' => 'default',
                        'locale' => 'fr',
                        'translation_group_id' => $group,
                        'translation_group_site_identifier' => null,
                    ]);
                },
                'A group identifier cannot be stored without its owner.',
            );
            $this->assertDriverRefuses(
                function () use ($database, $tables): void {
                    $database->insert($tables->raw('content_entries'), [
                        'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026b0008',
                        'site_identifier' => 'default',
                        'locale' => 'nl',
                        'translation_group_id' => null,
                        'translation_group_site_identifier' => 'default',
                    ]);
                },
                'A group owner cannot be stored without its identifier.',
            );
            $this->assertDriverRefuses(
                function () use ($database, $tables, $group): void {
                    $database->insert($tables->raw('content_entries'), [
                        'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026b0006',
                        'site_identifier' => 'secondary',
                        'locale' => 'af',
                        'translation_group_id' => $group,
                        'translation_group_site_identifier' => 'default',
                    ]);
                },
                'An entry cannot name a group owned by another site.',
            );
            $this->assertDriverRefuses(
                function () use ($database, $tables): void {
                    $database->update(
                        $tables->raw('content_entries'),
                        ['site_identifier' => 'secondary'],
                        ['id' => '018f22e2-7c8b-7ab0-8f3a-88e8026b0001'],
                    );
                },
                'An update cannot move an entry away from its translation-group owner.',
            );
            self::assertSame('default', $database->fetchOne(sprintf(
                'SELECT site_identifier FROM %s WHERE id = ?',
                $tables->quoted('content_entries'),
            ), ['018f22e2-7c8b-7ab0-8f3a-88e8026b0001']));

            self::assertSame('2', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE translation_group_id IS NULL',
                $tables->quoted('content_entries'),
            )), 'Repeated nulls must sit under the unique index rather than contend for it.');

            $this->assertDriverRefuses(
                function () use ($database, $tables, $group): void {
                    $this->insertEntry($database, $tables, 'b0005', 'en-GB', $group);
                },
                'One item must carry at most one entry per locale.',
            );

            $database->executeStatement(sprintf(
                'DELETE FROM %s WHERE id = ?',
                $tables->quoted('content_translation_groups'),
            ), [$group]);

            self::assertSame('4', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s',
                $tables->quoted('content_entries'),
            )), 'Deleting a group must release its members, not delete them.');
            self::assertSame('0', (string) $database->fetchOne(sprintf(
                'SELECT COUNT(*) FROM %s WHERE translation_group_id IS NOT NULL '
                . 'OR translation_group_site_identifier IS NOT NULL',
                $tables->quoted('content_entries'),
            )), 'The referential action must clear both halves of the nullable ownership pair.');
        } finally {
            $this->dropTables($database, $tables);
        }
    }

    /**
     * Prove the migration fails closed on an entry identifier it cannot build a compatible key against.
     *
     * A `content_entries.id` that is not character data has no character definition to copy, so the
     * foreign key would either be refused by InnoDB or created across a mismatch. The migration stops
     * before emitting any statement at all, which leaves the installation exactly as it found it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEntryIdentifierWithoutACharacterDefinitionIsRefusedBeforeAnythingIsWritten(): void
    {
        $database = $this->connection();
        $tables = $this->tables($database);

        try {
            $this->createEntriesTable($database, $tables, 'BIGINT NOT NULL');

            try {
                (new MultilingualContentMigration($tables))->up($database);
                self::fail('The migration must refuse an identifier with no character definition.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'The content entry identifier has no character definition to copy.',
                    $exception->getMessage(),
                );
            }

            self::assertFalse(
                $database->createSchemaManager()->tablesExist([$tables->raw('content_translation_groups')]),
                'A refused migration must not leave a half-built group table behind.',
            );
        } finally {
            $this->dropTables($database, $tables);
        }
    }

    /**
     * Open the installation connection, skipping the suite where the copied definition has no meaning.
     *
     * @return  Connection  MySQL-family integration connection.
     *
     * @since   2.0.0
     */
    private function connection(): Connection
    {
        $database = TestKernelFactory::create(Environment::fromGlobals())->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Copying a character definition applies only to MySQL-family databases.');
        }

        return $database;
    }

    /**
     * Compile table names under a prefix no other test or installation can be using.
     *
     * @param   Connection  $database  Integration connection supplying identifier quoting.
     *
     * @return  TableNames  Compiler bound to a prefix unique to this test method.
     *
     * @since   2.0.0
     */
    private function tables(Connection $database): TableNames
    {
        return new TableNames($database, 'm' . substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 10) . '_');
    }

    /**
     * Create the one pre-existing table the migration extends, with the identifier shape under test.
     *
     * @param   Connection  $database    Integration connection the table is created on.
     * @param   TableNames  $tables      Unique test table-name compiler.
     * @param   string      $identifier  Physical definition of `id`, with one `%s` for the collation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function createEntriesTable(Connection $database, TableNames $tables, string $identifier): void
    {
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (id %s, site_identifier %s, PRIMARY KEY (id)) ENGINE = InnoDB',
            $tables->quoted('content_entries'),
            sprintf($identifier, self::IDENTIFIER_COLLATION),
            sprintf('VARCHAR(191) CHARACTER SET utf8mb4 COLLATE %s NOT NULL', self::IDENTIFIER_COLLATION),
        ));
    }

    /**
     * Insert one entry row, in a locale of a group or in no language at all.
     *
     * @param   Connection  $database  Integration connection the row is written on.
     * @param   TableNames  $tables    Unique test table-name compiler.
     * @param   string      $tail      Final five characters distinguishing this row's identifier.
     * @param   ?string     $locale    Language tag the row declares, or null when it declares none.
     * @param   ?string     $group     Group the row belongs to, or null when it belongs to none.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertEntry(
        Connection $database,
        TableNames $tables,
        string $tail,
        ?string $locale,
        ?string $group,
    ): void {
        $database->insert($tables->raw('content_entries'), [
            'id' => '018f22e2-7c8b-7ab0-8f3a-88e8026' . $tail,
            'site_identifier' => 'default',
            'locale' => $locale,
            'translation_group_id' => $group,
            'translation_group_site_identifier' => $group === null ? null : 'default',
        ]);
    }

    /**
     * Assert that one deliberately invalid write is refused by the database itself.
     *
     * @param   callable(): void  $write    Invalid write whose driver exception proves enforcement.
     * @param   string            $message  Failure message used when the write unexpectedly succeeds.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertDriverRefuses(callable $write, string $message): void
    {
        try {
            $write();
            self::fail($message);
        } catch (DriverException) {
            // Reaching the driver refusal is the property under test.
        }
    }

    /**
     * Remove both test tables, the referencing one first so the constraint never blocks the cleanup.
     *
     * @param   Connection  $database  Integration connection the tables live on.
     * @param   TableNames  $tables    Unique test table-name compiler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function dropTables(Connection $database, TableNames $tables): void
    {
        foreach (['content_entries', 'content_translation_groups'] as $name) {
            $database->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $tables->quoted($name)));
        }
    }
}
