<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MultilingualContentMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\TranslationGroupSiteOwnershipMigration;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
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
    }

    /**
     * The generated column and schema-global check names stay deterministic and portable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnerInvariantIdentifiersStayInsideThePortableBudget(): void
    {
        $migration = $this->migration();
        $reflection = new ReflectionClass($migration);
        $columnConstant = $reflection->getReflectionConstant('OWNER_VALIDITY_COLUMN');
        self::assertInstanceOf(ReflectionClassConstant::class, $columnConstant);
        $column = $columnConstant->getValue();
        self::assertIsString($column);
        $checkName = new ReflectionMethod($migration, 'ownerCheckName');
        $check = $checkName->invoke($migration, 'kumwe_content_entries');
        $neighbour = $checkName->invoke($migration, 'other_content_entries');

        self::assertSame('translation_group_owner_valid', $column);
        self::assertLessThanOrEqual(63, strlen($column));
        self::assertIsString($check);
        self::assertMatchesRegularExpression('/^ck_[0-9a-f]{24}$/D', $check);
        self::assertLessThanOrEqual(63, strlen($check));
        self::assertNotSame($check, $neighbour);
        self::assertSame($check, $checkName->invoke($migration, 'kumwe_content_entries'));
    }

    /**
     * A fresh MySQL-family install adds the virtual value and its check in one schema statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlOwnerInvariantIsInstalledAtomicallyWithoutTriggers(): void
    {
        $statement = null;
        $database = $this->database();
        $database->expects(self::exactly(2))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            false,
            $this->ownerColumn(),
        );
        $database->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls(
            false,
            '(`translation_group_owner_valid` = 1)',
        );
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statement): int {
                $statement = $sql;

                return 0;
            },
        );
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->addMySqlOwnerConstraint($migration, $database);

        self::assertIsString($statement);
        self::assertStringStartsWith('ALTER TABLE kumwe_content_entries ', $statement);
        self::assertStringContainsString(
            'ADD COLUMN translation_group_owner_valid TINYINT(1) GENERATED ALWAYS AS (',
            $statement,
        );
        self::assertStringContainsString(') VIRTUAL, ADD CONSTRAINT ck_', $statement);
        self::assertStringContainsString('CHECK (translation_group_owner_valid = 1)', $statement);
        self::assertStringNotContainsString('CHECK (translation_group_id', $statement);
        self::assertStringNotContainsString('TRIGGER', $statement);
    }

    /**
     * A fully installed MySQL-family invariant is catalog-proven and emits no replay statement.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlOwnerInvariantReplayIsANoOp(): void
    {
        $database = $this->database();
        $database->expects(self::once())->method('fetchAssociative')->willReturn($this->ownerColumn());
        $database->expects(self::once())->method('fetchOne')->willReturn(
            '(`translation_group_owner_valid` = 1)',
        );
        $database->expects(self::never())->method('executeStatement');
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->addMySqlOwnerConstraint($migration, $database);
    }

    /**
     * A partial MySQL-family attempt reuses its proven virtual column and adds only the missing check.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlOwnerInvariantResumesAColumnOnlyAttempt(): void
    {
        $statement = null;
        $database = $this->database();
        $database->expects(self::exactly(2))->method('fetchAssociative')->willReturn($this->ownerColumn());
        $database->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls(
            false,
            '(translation_group_owner_valid = 1)',
        );
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql) use (&$statement): int {
                $statement = $sql;

                return 0;
            },
        );
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->addMySqlOwnerConstraint($migration, $database);

        self::assertIsString($statement);
        self::assertStringContainsString('ADD CONSTRAINT ck_', $statement);
        self::assertStringNotContainsString('ADD COLUMN', $statement);
    }

    /**
     * MariaDB-style associative parentheses are accepted without discarding boolean precedence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlOwnerInvariantAcceptsMariaDbCatalogParentheses(): void
    {
        $migration = $this->migration();
        $shape = new ReflectionMethod($migration, 'assertMySqlOwnerColumnShape');
        $column = [
            'data_type' => 'tinyint',
            'extra' => 'VIRTUAL GENERATED',
            'generation_expression' => '((`translation_group_id` IS NULL) '
                . 'AND (`translation_group_site_identifier` IS NULL)) OR '
                . '(((`translation_group_id` IS NOT NULL) '
                . 'AND (`translation_group_site_identifier` IS NOT NULL)) '
                . 'AND (`translation_group_site_identifier` = `site_identifier`))',
        ];

        self::assertNull($shape->invoke($migration, $column, 'kumwe_content_entries'));
    }

    /**
     * Replay refuses a same-named generated column whose storage or predicate shape drifted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMySqlOwnerInvariantRejectsAnIncompatibleCatalogShape(): void
    {
        $database = $this->database();
        $database->method('fetchAssociative')->willReturn([
            'data_type' => 'tinyint',
            'extra' => 'VIRTUAL GENERATED',
            'generation_expression' => 'translation_group_id IS NULL AND '
                . '(translation_group_site_identifier IS NULL OR translation_group_id IS NOT NULL) AND '
                . 'translation_group_site_identifier IS NOT NULL AND '
                . 'translation_group_site_identifier = site_identifier',
        ]);
        $database->method('fetchOne')->willReturn(false);
        $database->expects(self::never())->method('executeStatement');
        $migration = new TranslationGroupSiteOwnershipMigration(new TableNames($database, 'kumwe_'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has an incompatible shape');
        $this->addMySqlOwnerConstraint($migration, $database);
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
        $database = $this->database();
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
    }

    /**
     * Invoke the private MySQL-family installation branch through its focused seam.
     *
     * @param   TranslationGroupSiteOwnershipMigration  $migration  Migration under test.
     * @param   Connection                              $database   Catalog and DDL test double.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function addMySqlOwnerConstraint(
        TranslationGroupSiteOwnershipMigration $migration,
        Connection $database,
    ): void {
        (new ReflectionMethod($migration, 'addMySqlOwnerConstraint'))->invoke(
            $migration,
            $database,
            'kumwe_content_entries',
        );
    }

    /**
     * Build a connection double that quotes controlled identifiers without driver syntax.
     *
     * @return  Connection  Connection double used by one migration branch test.
     *
     * @since   2.0.0
     */
    private function database(): Connection
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => $identifier,
        );

        return $database;
    }

    /**
     * Return the catalog shape produced by the virtual generated owner-validity column.
     *
     * @return  array{data_type: string, extra: string, generation_expression: string}  Valid catalog row.
     *
     * @since   2.0.0
     */
    private function ownerColumn(): array
    {
        return [
            'data_type' => 'tinyint',
            'extra' => 'VIRTUAL GENERATED',
            'generation_expression' => '(translation_group_id IS NULL '
                . 'AND translation_group_site_identifier IS NULL) OR '
                . '(translation_group_id IS NOT NULL '
                . 'AND translation_group_site_identifier IS NOT NULL '
                . 'AND translation_group_site_identifier = site_identifier)',
        ];
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
