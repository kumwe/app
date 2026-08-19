<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\MatchType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\MultilingualContentMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\TranslationGroupSiteOwnershipMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the append-only translation-group ownership repair and its platform-specific enforcement.
 *
 * @since  2.0.0
 */
#[CoversClass(TranslationGroupSiteOwnershipMigration::class)]
final class TranslationGroupSiteOwnershipMigrationTest extends TestCase
{
    /**
     * The ledger identity is ordered correctly and its checksum binds the exact source bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdentityAndChecksumAreSelfPinned(): void
    {
        $migration = $this->migration();

        self::assertSame('20260819020000_translation_group_site_ownership', $migration->id());
        self::assertGreaterThan(MultilingualContentMigration::ID, $migration->id());
        self::assertLessThan(ConstraintNameIsolationPortabilityMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame($migration->checksum(), $this->migration()->checksum());

        $source = (new ReflectionClass($migration))->getFileName();
        self::assertIsString($source);
        $sourceDigest = hash_file('sha256', $source);
        self::assertIsString($sourceDigest);
        self::assertSame(hash('sha256', $migration->id() . ':' . $sourceDigest), $migration->checksum());
        $contents = file_get_contents($source);
        self::assertIsString($contents);
        self::assertStringNotContainsString('CREATE TRIGGER', $contents);
        self::assertStringNotContainsString('information_schema.TRIGGERS', $contents);
        self::assertStringNotContainsString('GENERATED ALWAYS', $contents);
        self::assertStringNotContainsString('translation_group_owner_valid', $contents);
    }

    /**
     * The schema-global ownership-check name stays deterministic and portable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnerInvariantIdentifiersStayInsideThePortableBudget(): void
    {
        $migration = $this->migration();
        $checkName = new ReflectionMethod($migration, 'ownerCheckName');
        $check = $checkName->invoke($migration, 'kumwe_content_entries');
        $neighbour = $checkName->invoke($migration, 'other_content_entries');

        self::assertIsString($check);
        self::assertMatchesRegularExpression('/^ck_[0-9a-f]{24}$/D', $check);
        self::assertLessThanOrEqual(63, strlen($check));
        self::assertNotSame($check, $neighbour);
        self::assertSame($check, $checkName->invoke($migration, 'kumwe_content_entries'));
    }

    /**
     * A fresh MariaDB install adds and reads back the direct ownership check without trigger DDL.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMariaDbOwnerCheckIsInstalledAndCatalogProven(): void
    {
        $statement = null;
        $catalogSql = [];
        $database = $this->database(new MariaDBPlatform());
        $calls = 0;
        $database->expects(self::exactly(2))->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql) use (&$calls, &$catalogSql): array {
                $catalogSql[] = $sql;
                ++$calls;

                return $calls === 1 ? [] : [$this->ownerCheckRow()];
            },
        );
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statement): int {
                $statement = $sql;

                return 0;
            },
        );
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->addOwnerCheckConstraint($migration, $database);

        self::assertIsString($statement);
        self::assertMatchesRegularExpression(
            '/^ALTER TABLE kumwe_content_entries ADD CONSTRAINT ck_[0-9a-f]{24} CHECK /D',
            $statement,
        );
        self::assertStringContainsString('translation_group_id IS NULL', $statement);
        self::assertStringContainsString('translation_group_site_identifier = site_identifier', $statement);
        self::assertStringNotContainsString('ENFORCED', implode(' ', $catalogSql));
        self::assertStringNotContainsString('GENERATED', $statement);
        self::assertStringNotContainsString('TRIGGER', $statement);
    }

    /**
     * A fully installed MySQL check is enforced, catalog-proven and emits no replay statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlOwnerCheckReplayIsANoOp(): void
    {
        $catalogSql = null;
        $database = $this->database(new MySQL84Platform());
        $database->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql) use (&$catalogSql): array {
                $catalogSql = $sql;

                return [$this->ownerCheckRow()];
            },
        );
        $database->expects(self::never())->method('executeStatement');
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->addOwnerCheckConstraint($migration, $database);

        self::assertIsString($catalogSql);
        self::assertStringContainsString('t.ENFORCED AS enforced', $catalogSql);
    }

    /**
     * A same-named MySQL check that is not enforced cannot close replay.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlOwnerCheckReplayRefusesANonEnforcedConstraint(): void
    {
        $database = $this->database(new MySQL84Platform());
        $database->method('fetchAllAssociative')->willReturn([$this->ownerCheckRow('NO')]);
        $database->expects(self::never())->method('executeStatement');
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not enforced and validated');
        $this->addOwnerCheckConstraint($migration, $database);
    }

    /**
     * PostgreSQL text casts and identifier parentheses are accepted without discarding precedence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPostgreSqlOwnerCheckReplayAcceptsTextCasts(): void
    {
        $database = $this->database(new PostgreSQLPlatform());
        $database->method('fetchAllAssociative')->willReturn([$this->ownerCheckRow(
            true,
            '(((translation_group_id IS NULL) AND (translation_group_site_identifier IS NULL)) OR '
                . '((translation_group_id IS NOT NULL) '
                . 'AND (translation_group_site_identifier IS NOT NULL) '
                . 'AND ((translation_group_site_identifier)::text = (site_identifier)::text)))',
        )]);
        $database->expects(self::never())->method('executeStatement');
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->addOwnerCheckConstraint($migration, $database);
    }

    /**
     * PostgreSQL replay refuses a correctly named check that has not validated every stored row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPostgreSqlOwnerCheckReplayRefusesAnUnvalidatedConstraint(): void
    {
        $database = $this->database(new PostgreSQLPlatform());
        $database->method('fetchAllAssociative')->willReturn([$this->ownerCheckRow(false)]);
        $database->expects(self::never())->method('executeStatement');
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not enforced and validated');
        $this->addOwnerCheckConstraint($migration, $database);
    }

    /**
     * Replay refuses a materially regrouped predicate even when the deterministic name matches.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnerCheckReplayRefusesAnIncompatiblePredicate(): void
    {
        $migration = $this->migration();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has an incompatible shape');
        (new ReflectionMethod($migration, 'assertOwnerCheckShape'))->invoke(
            $migration,
            'translation_group_id IS NULL AND (translation_group_site_identifier IS NULL '
                . 'OR translation_group_id IS NOT NULL) AND translation_group_site_identifier IS NOT NULL '
                . 'AND translation_group_site_identifier = site_identifier',
            'kumwe_content_entries',
            'ck_drifted',
        );
    }

    /**
     * Duplicate catalog rows are ambiguous rather than silently trusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnerCheckReplayRefusesDuplicateCatalogRows(): void
    {
        $database = $this->database(new MariaDBPlatform());
        $database->method('fetchAllAssociative')->willReturn([
            $this->ownerCheckRow(),
            $this->ownerCheckRow(),
        ]);
        $database->expects(self::never())->method('executeStatement');
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is ambiguous');
        $this->addOwnerCheckConstraint($migration, $database);
    }

    /**
     * The exact predecessor is selected once and a completed replay recognises only its replacement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPredecessorForeignKeySelectionIsExactAndReplaySafe(): void
    {
        $migration = $this->migration();
        $selection = new ReflectionMethod($migration, 'predecessorForeignKey');
        $predecessor = $this->foreignKey(
            'fk_predecessor',
            ['translation_group_id'],
            ['id'],
        );
        $replacement = $this->foreignKey(
            'fk_replacement',
            ['translation_group_id', 'translation_group_site_identifier'],
            ['id', 'site_identifier'],
            ReferentialAction::RESTRICT,
        );

        self::assertSame(
            $predecessor,
            $selection->invoke($migration, [$predecessor, $replacement], 'kumwe_content_translation_groups'),
        );
        self::assertNull(
            $selection->invoke($migration, [$replacement], 'kumwe_content_translation_groups'),
        );
        $mysqlReplacement = $this->foreignKey(
            'fk_mysql_replacement',
            ['translation_group_id', 'translation_group_site_identifier'],
            ['id', 'site_identifier'],
            ReferentialAction::NO_ACTION,
            ReferentialAction::RESTRICT,
        );
        self::assertNull(
            $selection->invoke($migration, [$mysqlReplacement], 'kumwe_content_translation_groups'),
        );
    }

    /**
     * A local-column match is not enough to delete a divergent predecessor relationship.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPredecessorForeignKeySelectionRefusesAWrongShape(): void
    {
        $migration = $this->migration();
        $selection = new ReflectionMethod($migration, 'predecessorForeignKey');
        $wrong = $this->foreignKey(
            'fk_wrong',
            ['translation_group_id'],
            ['site_identifier'],
        );
        $replacement = $this->foreignKey(
            'fk_replacement',
            ['translation_group_id', 'translation_group_site_identifier'],
            ['id', 'site_identifier'],
            ReferentialAction::RESTRICT,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('predecessor translation-group foreign key has an incompatible shape');
        $selection->invoke($migration, [$wrong, $replacement], 'kumwe_content_translation_groups');
    }

    /**
     * A composite relationship with a mutating update action is not the fail-closed replacement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPredecessorForeignKeySelectionRefusesAWrongReplacementAction(): void
    {
        $migration = $this->migration();
        $selection = new ReflectionMethod($migration, 'predecessorForeignKey');
        $predecessor = $this->foreignKey('fk_predecessor', ['translation_group_id'], ['id']);
        $replacement = $this->foreignKey(
            'fk_replacement',
            ['translation_group_id', 'translation_group_site_identifier'],
            ['id', 'site_identifier'],
            ReferentialAction::RESTRICT,
            ReferentialAction::CASCADE,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('composite translation-group foreign key has an incompatible shape');
        $selection->invoke(
            $migration,
            [$predecessor, $replacement],
            'kumwe_content_translation_groups',
        );
    }

    /**
     * More than one predecessor candidate is ambiguous and therefore cannot be removed safely.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPredecessorForeignKeySelectionRefusesAmbiguity(): void
    {
        $migration = $this->migration();
        $selection = new ReflectionMethod($migration, 'predecessorForeignKey');
        $first = $this->foreignKey('fk_first', ['translation_group_id'], ['id']);
        $second = $this->foreignKey('fk_second', ['translation_group_id'], ['id']);
        $replacement = $this->foreignKey(
            'fk_replacement',
            ['translation_group_id', 'translation_group_site_identifier'],
            ['id', 'site_identifier'],
            ReferentialAction::RESTRICT,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('predecessor translation-group foreign key is ambiguous');
        $selection->invoke(
            $migration,
            [$first, $second, $replacement],
            'kumwe_content_translation_groups',
        );
    }

    /**
     * PostgreSQL retains the direct check over the three authoritative owner columns.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPostgreSqlCheckRetainsTheCompleteOwnershipPredicate(): void
    {
        $statement = null;
        $catalogSql = [];
        $calls = 0;
        $database = $this->database(new PostgreSQLPlatform());
        $database->expects(self::exactly(2))->method('fetchAllAssociative')->willReturnCallback(
            function (string $sql) use (&$calls, &$catalogSql): array {
                $catalogSql[] = $sql;
                ++$calls;

                return $calls === 1 ? [] : [$this->ownerCheckRow(true)];
            },
        );
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statement): int {
                $statement = $sql;

                return 0;
            },
        );
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        (new ReflectionMethod($migration, 'addOwnerCheckConstraint'))->invoke(
            $migration,
            $database,
            'kumwe_content_entries',
        );

        self::assertIsString($statement);
        self::assertMatchesRegularExpression(
            '/^ALTER TABLE kumwe_content_entries ADD CONSTRAINT ck_[0-9a-f]{24} CHECK /D',
            $statement,
        );
        self::assertStringContainsString('translation_group_id IS NULL', $statement);
        self::assertStringContainsString('translation_group_site_identifier IS NULL', $statement);
        self::assertStringContainsString('translation_group_site_identifier = site_identifier', $statement);
        self::assertStringNotContainsString('translation_group_owner_valid', $statement);
        self::assertStringContainsString('c.convalidated AS enforced', implode(' ', $catalogSql));
    }

    /**
     * Invoke the private ownership-check installation branch through its focused seam.
     *
     * @param   TranslationGroupSiteOwnershipMigration  $migration  Migration under test.
     * @param   Connection                              $database   Catalog and DDL test double.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addOwnerCheckConstraint(
        TranslationGroupSiteOwnershipMigration $migration,
        Connection $database,
    ): void {
        (new ReflectionMethod($migration, 'addOwnerCheckConstraint'))->invoke(
            $migration,
            $database,
            'kumwe_content_entries',
        );
    }

    /**
     * Build a connection double that quotes controlled identifiers without driver syntax.
     *
     * @param   AbstractPlatform  $platform  Database family whose catalog branch is exercised.
     *
     * @return  Connection  Connection double used by one migration branch test.
     *
     * @since   2.0.0
     */
    private function database(AbstractPlatform $platform): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('getDatabasePlatform')->willReturn($platform);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    /**
     * Return one catalog-proven ownership-check row.
     *
     * @param   mixed    $enforced  Engine-specific enforcement or validation marker.
     * @param   ?string  $clause    Alternate catalog clause, or the canonical predicate by default.
     *
     * @return  array{check_clause: string, enforced: mixed}  Valid catalog row.
     *
     * @since   2.0.0
     */
    private function ownerCheckRow(mixed $enforced = 'YES', ?string $clause = null): array
    {
        return [
            'check_clause' => $clause ?? '((translation_group_id IS NULL '
                . 'AND translation_group_site_identifier IS NULL) OR '
                . '(translation_group_id IS NOT NULL '
                . 'AND translation_group_site_identifier IS NOT NULL '
                . 'AND translation_group_site_identifier = site_identifier))',
            'enforced' => $enforced,
        ];
    }

    /**
     * Build one ownership foreign key with a stable catalog name and explicit deletion rule.
     *
     * @param   string             $name         Constraint name.
     * @param   list<string>       $referencing  Content-entry columns.
     * @param   list<string>       $referenced   Translation-group columns.
     * @param   ReferentialAction  $onDelete     Delete action under test.
     * @param   ReferentialAction  $onUpdate     Update action under test.
     *
     * @return  ForeignKeyConstraint  Constraint shape under test.
     *
     * @since   2.0.0
     */
    private function foreignKey(
        string $name,
        array $referencing,
        array $referenced,
        ReferentialAction $onDelete = ReferentialAction::SET_NULL,
        ReferentialAction $onUpdate = ReferentialAction::NO_ACTION,
    ): ForeignKeyConstraint {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames(...$referencing)
            ->setUnquotedReferencedTableName('kumwe_content_translation_groups')
            ->setUnquotedReferencedColumnNames(...$referenced)
            ->setMatchType(MatchType::SIMPLE)
            ->setOnUpdateAction($onUpdate)
            ->setOnDeleteAction($onDelete)
            ->create();
    }

    /**
     * Build the migration over a prefix-aware table map.
     *
     * @return  TranslationGroupSiteOwnershipMigration  Migration under test.
     *
     * @since   2.0.0
     */
    private function migration(): TranslationGroupSiteOwnershipMigration
    {
        return new TranslationGroupSiteOwnershipMigration(
            new TableNames($this->createStub(Connection::class), 'kumwe_'),
        );
    }
}
