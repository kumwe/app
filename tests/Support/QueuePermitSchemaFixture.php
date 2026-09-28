<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\Migration\QueueWorkerPermitsMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Ramsey\Uuid\Uuid;

/**
 * Creates the minimal uniquely prefixed schema a queue-permit or fairness drill needs on the real engine.
 *
 * The permit and turn tables come from the shipped `QueueWorkerPermitsMigration`; the job, runtime,
 * ownership and site tables are the narrow subset the claim SQL reads. Every name carries a random
 * prefix so a drill never touches the shared application schema and can drop its own tables afterwards.
 *
 * @since  2.0.0
 */
final class QueuePermitSchemaFixture
{
    /**
     * Create the drill schema on the supplied session and return its table names.
     *
     * @param   Connection  $database  Session the tables are created on.
     *
     * @return  TableNames  Names owned by this fixture.
     *
     * @since   2.0.0
     */
    public static function create(Connection $database): TableNames
    {
        $tables = new TableNames(
            $database,
            'qp_' . substr(str_replace('-', '', Uuid::uuid7()->toString()), -8) . '_',
        );
        $specifications = [
            'jobs' => ['id' => Types::GUID, 'queue' => Types::STRING, 'status' => Types::STRING,
                'lease_token' => Types::GUID, 'lease_expires_at' => Types::DATETIME_IMMUTABLE,
                'worker_scope' => Types::STRING, 'execution_scope' => Types::STRING,
                'available_at' => Types::DATETIME_IMMUTABLE],
            'integration_inbox' => ['consumer_id' => Types::STRING, 'event_id' => Types::GUID,
                'queue' => Types::STRING, 'status' => Types::STRING, 'lease_token' => Types::GUID,
                'lease_expires_at' => Types::DATETIME_IMMUTABLE, 'site_identifier' => Types::STRING,
                'organization_id' => Types::STRING, 'event_type' => Types::STRING,
                'schema_version' => Types::INTEGER, 'handler_version' => Types::STRING,
                'aggregate_type' => Types::STRING, 'aggregate_id' => Types::STRING,
                'aggregate_version' => Types::BIGINT, 'envelope' => Types::JSON,
                'attempts' => Types::INTEGER, 'maximum_attempts' => Types::INTEGER,
                'available_at' => Types::DATETIME_IMMUTABLE, 'lease_owner' => Types::STRING,
                'lease_acquired_at' => Types::DATETIME_IMMUTABLE, 'runtime_generation' => Types::STRING,
                'failure_classification' => Types::STRING, 'exception_type' => Types::STRING,
                'error_message' => Types::TEXT, 'first_received_at' => Types::DATETIME_IMMUTABLE,
                'completed_at' => Types::DATETIME_IMMUTABLE, 'evidence_compacted_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE],
            'job_queue_runtime' => ['queue_id' => Types::STRING, 'lease_seconds' => Types::INTEGER,
                'maximum_attempts' => Types::INTEGER, 'maximum_in_flight' => Types::INTEGER,
                'retention_days' => Types::INTEGER, 'runtime_generation' => Types::BIGINT,
                'last_claimed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE],
            'resource_site_ownership' => ['resource_type' => Types::STRING, 'resource_id' => Types::STRING,
                'site_identifier' => Types::STRING],
            'sites' => ['identifier' => Types::STRING, 'enabled' => Types::BOOLEAN],
        ];
        foreach ($specifications as $name => $columns) {
            $table = new Table($tables->raw($name));
            $identity = array_key_first($columns);
            foreach ($columns as $column => $type) {
                $options = ['notnull' => $column === $identity];
                if ($type === Types::STRING) {
                    $options['length'] = 191;
                }
                $table->addColumn($column, $type, $options);
            }
            $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(
                ...($name === 'integration_inbox' ? ['consumer_id', 'event_id'] : [$identity]),
            )->create());
            foreach ($database->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
                $database->executeStatement($sql);
            }
        }
        (new QueueWorkerPermitsMigration($tables))->up($database);

        return $tables;
    }

    /**
     * Drop every table the fixture created.
     *
     * @param   Connection  $database  Session the tables were created on.
     * @param   TableNames  $tables    Names returned by `create()`.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function drop(Connection $database, TableNames $tables): void
    {
        if ($database->isTransactionActive()) {
            $database->rollBack();
        }
        foreach (
            ['job_queue_permits', 'job_queue_turns', 'integration_delivery_turns', 'integration_delivery_health',
                'job_queue_runtime', 'integration_inbox', 'jobs', 'resource_site_ownership', 'sites'] as $name
        ) {
            $database->executeStatement('DROP TABLE ' . $tables->quoted($name));
        }
    }
}
