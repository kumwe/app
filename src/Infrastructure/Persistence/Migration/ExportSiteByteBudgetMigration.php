<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Creates the one-row-per-site ledger of cumulative export bytes published in the current UTC day.
 *
 * The table holds a row for each site that has completed an export and nothing else, so it never grows
 * with export volume. It is created only when missing, which makes a repeated pass a no-op.
 *
 * @since  2.0.0
 */
final readonly class ExportSiteByteBudgetMigration implements RepeatableMigration
{
    /**
     * Append-only migration identity reserved for the per-site export byte budget (P5-H).
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260924140000_export_site_byte_budget';

    /**
     * Resolve the physical name within the installation prefix.
     *
     * @param  TableNames  $tables  Installation table names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the migration ledger identity.
     *
     * @return  string  Ordered identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Freeze the migration's source bytes when shipped.
     *
     * @return  string  Source-bound checksum.
     *
     * @throws  RuntimeException  When the source is unreadable.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The export site byte budget migration checksum could not be read.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Create the budget ledger unless it exists.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $name = $this->tables->raw('business_report_export_site_budgets');
        if ($database->createSchemaManager()->tablesExist([$name])) {
            return;
        }
        $schema = new Schema();
        $table = $schema->createTable($name);
        $table->addColumn('site_identifier', Types::STRING, ['length' => 191]);
        $table->addColumn('window_start', Types::DATETIME_IMMUTABLE);
        $table->addColumn('window_bytes', Types::BIGINT);
        $table->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $table->addPrimaryKeyConstraint(
            PrimaryKeyConstraint::editor()->setUnquotedColumnNames('site_identifier')->create(),
        );
        foreach ($schema->toSql($database->getDatabasePlatform()) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
