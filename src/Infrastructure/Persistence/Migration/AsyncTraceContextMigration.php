<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Lets queued jobs and integration receipts carry the identifiers of the unit of work that wrote them.
 *
 * A job used to record nothing about where it came from, so every log line it wrote carried the
 * worker's own correlation identifier and the end-to-end thread from the HTTP request that queued it
 * was cut at the queue. Outbox and inbox rows already carried correlation and causation in their
 * envelopes, but not the upstream W3C trace identifier the request had accepted. This migration adds
 * nullable `correlation_id`, `causation_id` and `trace_id` columns to `jobs`, and a nullable `trace_id`
 * to `integration_outbox` and `integration_inbox`. Rows written before it stay valid: a claim of a row
 * with no recorded origin simply falls back to the claimer's own identifiers.
 *
 * The columns are propagation, not tracing (ADR 0022): they hold identifiers an upstream proxy already
 * minted, and nothing reads them except the log-context frame a claim opens.
 *
 * @since  2.0.0
 */
final readonly class AsyncTraceContextMigration implements RepeatableMigration
{
    /**
     * Append-only migration identity reserved for the P7-D asynchronous trace-context columns.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260924130000_async_trace_context';

    /**
     * Columns added per table: name, maximum length.
     *
     * @var    array<string, array<string, int>>
     * @since  2.0.0
     */
    private const array COLUMNS = [
        'jobs' => ['correlation_id' => 191, 'causation_id' => 191, 'trace_id' => 32],
        'integration_outbox' => ['trace_id' => 32],
        'integration_inbox' => ['trace_id' => 32],
    ];

    /**
     * Resolve all physical names within the installation prefix.
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
            throw new RuntimeException('The asynchronous trace-context migration checksum could not be read.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    /**
     * Add each missing nullable column, leaving columns an interrupted earlier pass already added.
     *
     * @param   Connection  $database  Installation database.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $manager = $database->createSchemaManager();
        $before = $manager->introspectSchema();
        $after = clone $before;
        foreach (self::COLUMNS as $name => $columns) {
            $physical = $this->tables->raw($name);
            if (!$after->hasTable($physical)) {
                continue;
            }
            $table = $after->getTable($physical);
            foreach ($columns as $column => $length) {
                if (!$table->hasColumn($column)) {
                    $table->addColumn($column, Types::STRING, ['length' => $length, 'notnull' => false]);
                }
            }
        }
        $difference = $manager->createComparator()->compareSchemas($before, $after);
        foreach ($database->getDatabasePlatform()->getAlterSchemaSQL($difference) as $statement) {
            $database->executeStatement($statement);
        }
    }
}
