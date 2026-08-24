<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Adds portable immutable Studio artifact, idempotency and scoped recovery persistence.
 *
 * Canonical documents are stored as text so MariaDB/MySQL, PostgreSQL and SQLite cannot rewrite object
 * order or numeric spellings. Every logical identity uses bounded strings and digest primary keys small
 * enough for MySQL-family indexes, while DBAL compiles the same schema model for all supported engines.
 *
 * @since  2.0.0
 */
final readonly class StudioArtifactRecoveryMigration implements Migration
{
    /**
     * Stable append-only migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260824030000_studio_artifact_recovery';

    /**
     * Bind the migration to prefix-aware table names.
     *
     * @param   TableNames  $tables  Installation table-name compiler.
     *
     * @since   2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only identity recorded in the migration ledger.
     *
     * @return  string  Stable migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind applied migration history to the exact source bytes.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When the source digest cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio artifact migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Create or complete all AP-4 stores through DBAL's portable schema compiler.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver refuses generated schema statements.
     * @throws  RuntimeException  When a partial store has an incompatible primary key.
     *
     * @since  2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;

        $headsName = $this->tables->raw('studio_artifact_heads');
        $heads = $after->hasTable($headsName) ? $after->getTable($headsName) : $after->createTable($headsName);
        $this->artifactColumns($heads);
        $this->primary($heads, ['site_identifier', 'artifact_id', 'artifact_version']);

        $historyName = $this->tables->raw('studio_artifact_revisions');
        $history = $after->hasTable($historyName)
            ? $after->getTable($historyName)
            : $after->createTable($historyName);
        $this->artifactColumns($history);
        $this->primary($history, ['site_identifier', 'artifact_id', 'artifact_version', 'revision']);
        $historyIndex = ConstraintNameIsolationMigration::isolatedName($historyName, 'idx_studio_artifact_history');
        if (!$history->hasIndex($historyIndex)) {
            $history->addIndex(['site_identifier', 'artifact_id', 'artifact_version', 'recorded_at'], $historyIndex);
        }

        $idempotencyName = $this->tables->raw('studio_host_idempotency');
        $idempotency = $after->hasTable($idempotencyName)
            ? $after->getTable($idempotencyName)
            : $after->createTable($idempotencyName);
        $this->string($idempotency, 'scope_digest', 64);
        $this->string($idempotency, 'intent_digest', 64);
        $this->string($idempotency, 'actor_id', 191);
        $this->string($idempotency, 'session_binding', 64);
        $this->string($idempotency, 'resource_context_key', 240);
        $this->string($idempotency, 'session_generation', 200);
        $this->string($idempotency, 'operation_id', 191);
        $this->string($idempotency, 'idempotency_key', 240);
        $this->string($idempotency, 'state', 20);
        $this->text($idempotency, 'result_bytes', 1048576, false);
        $this->date($idempotency, 'created_at');
        $this->date($idempotency, 'completed_at', false);
        $this->primary($idempotency, ['scope_digest']);
        $idempotencyIndex = ConstraintNameIsolationMigration::isolatedName(
            $idempotencyName,
            'idx_studio_idempotency_resource',
        );
        if (!$idempotency->hasIndex($idempotencyIndex)) {
            $idempotency->addIndex(['resource_context_key', 'operation_id'], $idempotencyIndex);
        }

        $recoveryName = $this->tables->raw('studio_recovery_envelopes');
        $recovery = $after->hasTable($recoveryName)
            ? $after->getTable($recoveryName)
            : $after->createTable($recoveryName);
        $this->string($recovery, 'resource_context_key', 240);
        $this->string($recovery, 'actor_id', 191);
        $this->string($recovery, 'session_binding', 64);
        $this->text($recovery, 'canonical_envelope', 1048576);
        $this->integer($recovery, 'envelope_bytes');
        $this->bigint($recovery, 'updated_at_milliseconds');
        $this->primary($recovery, ['resource_context_key']);

        $rateName = $this->tables->raw('studio_recovery_rate_limits');
        $rate = $after->hasTable($rateName) ? $after->getTable($rateName) : $after->createTable($rateName);
        $this->string($rate, 'scope_digest', 64);
        $this->bigint($rate, 'window_started_milliseconds');
        $this->integer($rate, 'request_count');
        $this->primary($rate, ['scope_digest']);

        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }

    /**
     * Add the common immutable artifact columns when absent.
     *
     * @param   Table  $table  Artifact head or history table.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function artifactColumns(Table $table): void
    {
        $this->string($table, 'site_identifier', 191);
        $this->string($table, 'artifact_id', 240);
        $this->string($table, 'artifact_version', 100);
        $this->string($table, 'artifact_kind', 20);
        $this->string($table, 'revision', 200);
        $this->string($table, 'status', 20);
        $this->text($table, 'canonical_document', 16777215);
        $this->text($table, 'canonical_dependencies', 1048576);
        $this->date($table, 'recorded_at');
    }

    /**
     * Add one bounded string column when absent.
     *
     * @param   Table   $table    Table being completed.
     * @param   string  $name     Column name.
     * @param   int     $length   Maximum stored bytes.
     * @param   bool    $notNull  Whether null is forbidden.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function string(Table $table, string $name, int $length, bool $notNull = true): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::STRING, ['length' => $length, 'notnull' => $notNull]);
        }
    }

    /**
     * Add one byte-preserving text column when absent.
     *
     * @param   Table   $table    Table being completed.
     * @param   string  $name     Column name.
     * @param   int     $length   Maximum stored bytes.
     * @param   bool    $notNull  Whether null is forbidden.
     *
     * @return  void
     *
     * @since   2.0.0
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
     * @param   Table   $table  Table being completed.
     * @param   string  $name   Column name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function integer(Table $table, string $name): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::INTEGER);
        }
    }

    /**
     * Add one portable large-integer column when absent.
     *
     * @param   Table   $table  Table being completed.
     * @param   string  $name   Column name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function bigint(Table $table, string $name): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::BIGINT);
        }
    }

    /**
     * Add one immutable UTC instant column when absent.
     *
     * @param   Table   $table    Table being completed.
     * @param   string  $name     Column name.
     * @param   bool    $notNull  Whether null is forbidden.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function date(Table $table, string $name, bool $notNull = true): void
    {
        if (!$table->hasColumn($name)) {
            $table->addColumn($name, Types::DATETIME_IMMUTABLE, ['notnull' => $notNull]);
        }
    }

    /**
     * Add the expected primary key or fail a partially created incompatible store closed.
     *
     * @param   Table                                    $table    Table being completed.
     * @param   non-empty-list<non-empty-string>  $columns  Exact key order.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the requested key is empty or an existing key is incompatible.
     *
     * @since   2.0.0
     */
    private function primary(Table $table, array $columns): void
    {
        $primary = $table->getPrimaryKeyConstraint();
        if ($primary === null) {
            $first = $columns[0] ?? throw new RuntimeException('A Studio artifact primary key cannot be empty.');
            $table->addPrimaryKeyConstraint(
                PrimaryKeyConstraint::editor()->setUnquotedColumnNames(
                    $first,
                    ...array_slice($columns, 1),
                )->create(),
            );

            return;
        }
        $actual = array_map(
            static fn (Name $column): string => $column->toString(),
            $primary->getColumnNames(),
        );
        if ($actual !== $columns) {
            throw new RuntimeException('A partial Studio artifact store has an incompatible primary key.');
        }
    }
}
