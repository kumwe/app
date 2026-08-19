<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Aligns versioned content-model identifiers with the authoritative head-table character definitions.
 *
 * MySQL-family databases compare textual GUIDs using their physical character set and collation. Earlier
 * schema creation paths could let version tables resolve a character-set default independently from the
 * database default inherited by the Core tables. This forward repair copies the existing head identifiers'
 * exact character definitions so every physical equality join has one coherent contract.
 *
 * @since  2.0.0
 */
final readonly class ContentModelIdentifierCollationMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity appended after the demo-profile schema migration.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260811020000_content_model_identifier_collations';

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
     * Bind migration compatibility to the exact repair implementation.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When this source file cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The content-model identifier collation checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Repair every textual GUID used to join a content-model version row to authoritative Core state.
     *
     * PostgreSQL has a native UUID type and therefore needs no character repair. On MySQL and MariaDB the
     * existing Core columns are first proven mutually compatible. The two version tables are then altered
     * through DBAL's schema comparator, preserving every non-character column attribute and index.
     *
     * @param   Connection  $database  Installation database whose content-model identifiers are repaired.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an authoritative or versioned identifier is not a physical `CHAR(36)`,
     *          its source character metadata is unavailable, or authoritative join columns already disagree.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        if (!$database->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }

        $manager = $database->createSchemaManager();
        $workflows = $manager->introspectTableByUnquotedName($this->tables->raw('workflows'));
        $contentTypes = $manager->introspectTableByUnquotedName($this->tables->raw('content_types'));
        $contentEntries = $manager->introspectTableByUnquotedName($this->tables->raw('content_entries'));
        $workflowId = $workflows->getColumn('id');
        $contentTypeId = $contentTypes->getColumn('id');

        $this->assertSameCharacterDefinition($workflowId, $contentTypes->getColumn('workflow_id'));
        $this->assertSameCharacterDefinition($workflowId, $contentEntries->getColumn('workflow_id'));
        $this->assertSameCharacterDefinition($contentTypeId, $contentEntries->getColumn('content_type_id'));

        $workflowVersionsBefore = $manager->introspectTableByUnquotedName(
            $this->tables->raw('workflow_definition_versions'),
        );
        $workflowVersionsAfter = clone $workflowVersionsBefore;
        $this->copyCharacterDefinition($workflowId, $workflowVersionsAfter->getColumn('workflow_id'));
        $this->applyTableDifference($database, $workflowVersionsBefore, $workflowVersionsAfter);

        $contentTypeVersionsBefore = $manager->introspectTableByUnquotedName(
            $this->tables->raw('content_type_definition_versions'),
        );
        $contentTypeVersionsAfter = clone $contentTypeVersionsBefore;
        $this->copyCharacterDefinition(
            $contentTypeId,
            $contentTypeVersionsAfter->getColumn('content_type_id'),
        );
        $this->copyCharacterDefinition(
            $contentTypes->getColumn('workflow_id'),
            $contentTypeVersionsAfter->getColumn('workflow_id'),
        );
        $this->applyTableDifference($database, $contentTypeVersionsBefore, $contentTypeVersionsAfter);

        $workflowVersions = $manager->introspectTableByUnquotedName(
            $this->tables->raw('workflow_definition_versions'),
        );
        $contentTypeVersions = $manager->introspectTableByUnquotedName(
            $this->tables->raw('content_type_definition_versions'),
        );
        $this->assertSameCharacterDefinition($workflowId, $workflowVersions->getColumn('workflow_id'));
        $this->assertSameCharacterDefinition($contentTypeId, $contentTypeVersions->getColumn('content_type_id'));
        $this->assertSameCharacterDefinition(
            $contentTypes->getColumn('workflow_id'),
            $contentTypeVersions->getColumn('workflow_id'),
        );
    }

    /**
     * Copy only charset and collation from one proven textual GUID onto another.
     *
     * @param   Column  $source  Authoritative Core identifier supplying the character definition.
     * @param   Column  $target  Version-table identifier receiving that character definition.
     *
     * @return  void
     *
     * @throws  RuntimeException  When either column is not `CHAR(36)` or the source metadata is unavailable.
     *
     * @since   2.0.0
     */
    private function copyCharacterDefinition(Column $source, Column $target): void
    {
        $this->assertTextualGuid($source);
        $this->assertTextualGuid($target);
        $charset = $source->getCharset();
        $collation = $source->getCollation();
        if ($charset === null || $collation === null) {
            throw new RuntimeException('The authoritative content-model identifier has no character definition.');
        }

        $target->setPlatformOption('charset', $charset);
        $target->setPlatformOption('collation', $collation);
    }

    /**
     * Prove two textual GUIDs already share one complete physical character definition.
     *
     * @param   Column  $expected  Authoritative identifier defining the required semantics.
     * @param   Column  $actual    Referencing or repaired identifier compared with it.
     *
     * @return  void
     *
     * @throws  RuntimeException  When either column is incompatible or their charset or collation differs.
     *
     * @since   2.0.0
     */
    private function assertSameCharacterDefinition(Column $expected, Column $actual): void
    {
        $this->assertTextualGuid($expected);
        $this->assertTextualGuid($actual);
        if (
            $expected->getCharset() === null
            || $expected->getCollation() === null
            || $expected->getCharset() !== $actual->getCharset()
            || $expected->getCollation() !== $actual->getCollation()
        ) {
            throw new RuntimeException('Content-model join identifiers have incompatible character definitions.');
        }
    }

    /**
     * Require the portable MySQL-family representation of a Doctrine GUID.
     *
     * @param   Column  $column  Introspected identifier column to validate.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the column is not a fixed 36-character string.
     *
     * @since   2.0.0
     */
    private function assertTextualGuid(Column $column): void
    {
        if (
            Type::lookupName($column->getType()) !== Types::STRING
            || $column->getLength() !== 36
            || !$column->getFixed()
        ) {
            throw new RuntimeException('A content-model join identifier is not a portable textual GUID.');
        }
    }

    /**
     * Apply one table-local schema difference without exposing unrelated introspection noise.
     *
     * @param   Connection  $database  Database executing the generated portable DDL.
     * @param   Table       $before    Introspected table before character repair.
     * @param   Table       $after     Cloned table carrying the required character definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function applyTableDifference(Connection $database, Table $before, Table $after): void
    {
        $manager = $database->createSchemaManager();
        $difference = $manager->createComparator()->compareTables($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterTableSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
