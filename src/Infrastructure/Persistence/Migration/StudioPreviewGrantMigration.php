<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds portable short-lived preview grants and monotonic replay ledgers.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewGrantMigration implements RepeatableMigration
{
    /**
     * Stable append-only migration identity after AP-4 artifact storage.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260824040000_studio_preview_grants';

    /**
     * Bind the schema to installation-specific prefix-aware names.
     *
     * @param  TableNames  $tables Table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the stable append-only preview migration identifier.
     *
     * @return  string  Migration identifier.
     *
     * @since  2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind the migration checksum to both its identity and exact implementation bytes.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @since  2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio preview migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Create or complete both portable AP-6 stores idempotently.
     *
     * @throws  \Doctrine\DBAL\Exception  When schema inspection or DDL fails.
     * @throws  RuntimeException  When a partial table carries an incompatible primary key.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;

        $grantName = $this->tables->raw('studio_preview_grants');
        $grants = $after->hasTable($grantName) ? $after->getTable($grantName) : $after->createTable($grantName);
        foreach (
            [
                'resource_context_key' => 240,
                'request_id' => 240,
                'actor_id' => 191,
                'site_identifier' => 191,
                'session_binding' => 64,
                'session_generation' => 200,
                'origin' => 255,
                'channel_id' => 240,
                'source_id' => 240,
                'artifact_id' => 240,
                'draft_digest' => 64,
                'draft_revision' => 200,
                'viewport' => 63,
                'state' => 20,
            ] as $column => $length
        ) {
            $this->string($grants, $column, $length);
        }
        foreach (['organization_identifier', 'workspace_identifier'] as $column) {
            $this->string($grants, $column, 191, false);
        }
        foreach (
            ['html_document', 'theme_stylesheet', 'markers_json', 'marker_map_json', 'diagnostics_json'] as $column
        ) {
            $this->text($grants, $column, 16_777_215, false);
        }
        $this->bigint($grants, 'port_sequence');
        $this->integer($grants, 'use_count');
        $this->date($grants, 'expires_at');
        $this->date($grants, 'claimed_at', false);
        $this->primary($grants, ['resource_context_key', 'request_id']);
        $grantIndex = ConstraintNameIsolationMigration::isolatedName($grantName, 'idx_studio_preview_digest');
        if (!$grants->hasIndex($grantIndex)) {
            $grants->addIndex(['resource_context_key', 'draft_digest', 'state'], $grantIndex);
        }
        $cancelOrderIndex = ConstraintNameIsolationMigration::isolatedName(
            $grantName,
            'idx_studio_preview_cancel_order',
        );
        if (!$grants->hasIndex($cancelOrderIndex)) {
            $grants->addIndex(
                ['resource_context_key', 'draft_digest', 'state', 'port_sequence'],
                $cancelOrderIndex,
            );
        }

        $cancellationName = $this->tables->raw('studio_preview_cancellations');
        $cancellations = $after->hasTable($cancellationName)
            ? $after->getTable($cancellationName)
            : $after->createTable($cancellationName);
        $this->string($cancellations, 'resource_context_key', 240);
        $this->string($cancellations, 'draft_digest', 64);
        $this->bigint($cancellations, 'cancel_port_sequence');
        $this->primary($cancellations, ['resource_context_key', 'draft_digest']);

        $sequenceName = $this->tables->raw('studio_preview_sequences');
        $sequences = $after->hasTable($sequenceName)
            ? $after->getTable($sequenceName)
            : $after->createTable($sequenceName);
        $this->string($sequences, 'resource_context_key', 240);
        $this->string($sequences, 'lane', 20);
        $this->bigint($sequences, 'next_sequence');
        $this->primary($sequences, ['resource_context_key', 'lane']);

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Add one bounded string column when absent.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private function string(Table $table, string $name, int $length, bool $notNull = true): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => $notNull]);
        }
    }

    /**
     * Add one bounded text column when absent.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private function text(Table $table, string $name, int $length, bool $notNull = true): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::TEXT, ['length' => $length, 'notnull' => $notNull]);
        }
    }

    /**
     * Add one integer column when absent.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private function integer(Table $table, string $name): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::INTEGER);
        }
    }

    /**
     * Add one portable large integer column when absent.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private function bigint(Table $table, string $name): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::BIGINT);
        }
    }

    /**
     * Add one immutable UTC timestamp when absent.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private function date(Table $table, string $name, bool $notNull = true): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::DATETIME_IMMUTABLE, ['notnull' => $notNull]);
        }
    }

    /**
     * Add the exact primary key or refuse an incompatible partial table.
     *
     * @param   list<string>  $columns Exact primary-key order.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an existing key differs.
     *
     * @since   2.0.0
     */
    private function primary(Table $table, array $columns): void
    {
        $primary = $table->getPrimaryKeyConstraint();
        if ($primary === null) {
            $names = [];
            foreach ($columns as $column) {
                if ($column === '') {
                    throw new RuntimeException('A Studio preview primary key column cannot be empty.');
                }
                $names[] = UnqualifiedName::unquoted($column);
            }
            if ($names === []) {
                throw new RuntimeException('A Studio preview primary key requires at least one column.');
            }
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setColumnNames(
                    $names[0],
                    ...array_slice($names, 1),
                )->create(),
            );

            return;
        }
        $actual = array_map(
            static fn (UnqualifiedName $column): string => $column->getIdentifier()->getValue(),
            $primary->getColumnNames(),
        );
        if ($actual !== $columns) {
            throw new RuntimeException('A partial Studio preview table has an incompatible primary key.');
        }
    }
}
