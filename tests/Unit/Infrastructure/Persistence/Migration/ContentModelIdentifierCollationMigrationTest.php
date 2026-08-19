<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\Migration\ContentModelIdentifierCollationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\RepeatableMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the physical character-definition repair used by versioned content-model identifiers.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentModelIdentifierCollationMigration::class)]
final class ContentModelIdentifierCollationMigrationTest extends TestCase
{
    /**
     * Proves a versioned GUID receives the complete character definition of its authoritative source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCharacterDefinitionIsCopiedWithoutChangingTheIdentifierShape(): void
    {
        $migration = $this->migration();
        $source = $this->identifier('source', 'utf8mb4_unicode_ci');
        $target = $this->identifier('target', 'utf8mb4_bin');

        (new ReflectionMethod($migration, 'copyCharacterDefinition'))->invoke($migration, $source, $target);

        self::assertSame(36, $target->getLength());
        self::assertTrue($target->getFixed());
        self::assertSame('utf8mb4', $target->getCharset());
        self::assertSame('utf8mb4_unicode_ci', $target->getCollation());
    }

    /**
     * Proves DBAL turns the copied platform options into a real MariaDB column alteration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCopiedDefinitionProducesPortableMariaDbAlterSql(): void
    {
        $migration = $this->migration();
        $platform = new MariaDBPlatform();
        $before = new Table('kumwe_workflow_definition_versions');
        $before->addColumn('workflow_id', Types::STRING, [
            'length' => 36,
            'fixed' => true,
            'platformOptions' => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_bin',
            ],
        ]);
        $after = clone $before;
        $source = $this->identifier('source', 'utf8mb4_unicode_ci');
        (new ReflectionMethod($migration, 'copyCharacterDefinition'))->invoke(
            $migration,
            $source,
            $after->getColumn('workflow_id'),
        );

        $difference = (new Comparator($platform))->compareTables($before, $after);
        $sql = implode("\n", $platform->getAlterTableSQL($difference));

        self::assertStringContainsString('ALTER TABLE kumwe_workflow_definition_versions', $sql);
        self::assertStringContainsString('CHAR(36)', $sql);
        self::assertStringContainsString('CHARACTER SET utf8mb4', $sql);
        self::assertStringContainsString(
            'COLLATE ' . $platform->quoteSingleIdentifier('utf8mb4_unicode_ci'),
            $sql,
        );
    }

    /**
     * Proves the repair fails closed instead of rewriting a column that is not a textual GUID.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNonGuidTargetIsRejected(): void
    {
        $migration = $this->migration();
        $source = $this->identifier('source', 'utf8mb4_unicode_ci');
        $table = new Table('target_table');
        $target = $table->addColumn('target', Types::STRING, [
            'length' => 35,
            'fixed' => true,
            'platformOptions' => [
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_bin',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a portable textual GUID');
        (new ReflectionMethod($migration, 'copyCharacterDefinition'))->invoke($migration, $source, $target);
    }

    /**
     * Proves an interrupted MySQL-family attempt may replay and the migration has ledger-safe identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMigrationIsAppendOnlyAndRepeatable(): void
    {
        $migration = $this->migration();

        self::assertInstanceOf(RepeatableMigration::class, $migration);
        self::assertSame(
            '20260811020000_content_model_identifier_collations',
            ContentModelIdentifierCollationMigration::ID,
        );
        self::assertSame(ContentModelIdentifierCollationMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
    }

    /**
     * Build one physical MySQL-family GUID column with an explicit character definition.
     *
     * @param   string  $name       Column name inside its isolated test table.
     * @param   string  $collation  Collation attached to the textual GUID.
     *
     * @return  Column  Fixed 36-character test identifier.
     *
     * @since   2.0.0
     */
    private function identifier(string $name, string $collation): Column
    {
        $table = new Table($name . '_table');

        return $table->addColumn($name, Types::STRING, [
            'length' => 36,
            'fixed' => true,
            'platformOptions' => [
                'charset' => 'utf8mb4',
                'collation' => $collation,
            ],
        ]);
    }

    /**
     * Build the migration without opening a database connection.
     *
     * @return  ContentModelIdentifierCollationMigration  Migration under test.
     *
     * @since   2.0.0
     */
    private function migration(): ContentModelIdentifierCollationMigration
    {
        $database = $this->createStub(Connection::class);

        return new ContentModelIdentifierCollationMigration(new TableNames($database, 'kumwe_'));
    }
}
