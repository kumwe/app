<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Application\Automation\JobExecutionClass;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use RuntimeException;

/**
 * Gives the business-record command idempotency ledger an installation-global retention schedule.
 *
 * The ledger is appended to by every typed record mutation. Without a scheduled purge it grows
 * without bound, so the schedule is seeded here rather than left to deployment configuration.
 */
final readonly class BusinessRecordIdempotencyRetentionMigration implements RepeatableMigration
{
    public const string ID = '20260808120000_business_record_idempotency_retention';

    public const string JOB_TYPE = 'business.record.idempotency.purge';

    private const string SCHEDULE_ID = '00000000-0000-7000-8000-000000000803';

    public function __construct(private TableNames $tables)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        $digest = hash_file('sha256', __FILE__);
        if (!is_string($digest)) {
            throw new RuntimeException('The business-record idempotency retention checksum could not be read.');
        }

        return hash('sha256', self::ID . ':' . $digest);
    }

    public function up(Connection $database): void
    {
        $existing = $database->fetchOne(sprintf(
            'SELECT id FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), [self::JOB_TYPE]);

        if ($existing === false) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $database->insert($this->tables->raw('schedules'), [
                'id' => self::SCHEDULE_ID,
                'name' => 'Purge expired business-record idempotency entries',
                'cron_expression' => '43 * * * *',
                'timezone' => 'UTC',
                'queue' => 'default',
                'job_type' => self::JOB_TYPE,
                'job_schema_version' => 1,
                'payload' => ['batch_size' => 500, 'maximum_batches' => 10],
                'priority' => -10,
                'maximum_attempts' => 5,
                'enabled' => true,
                'next_run_at' => $now->modify('+1 hour'),
                'last_run_at' => null,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'execution_scope' => JobExecutionClass::Installation->value,
            ], [
                'payload' => Types::JSON,
                'enabled' => Types::BOOLEAN,
                'next_run_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }

        foreach (['jobs', 'schedules'] as $name) {
            $database->executeStatement(sprintf(
                'UPDATE %s SET execution_scope = ? WHERE job_type = ?',
                $this->tables->quoted($name),
            ), [JobExecutionClass::Installation->value, self::JOB_TYPE]);
        }

        $this->assertSeeded($database);
    }

    private function assertSeeded(Connection $database): void
    {
        $scope = $database->fetchOne(sprintf(
            'SELECT execution_scope FROM %s WHERE job_type = ?',
            $this->tables->quoted('schedules'),
        ), [self::JOB_TYPE]);

        if ($scope !== JobExecutionClass::Installation->value) {
            throw new RuntimeException(
                'The business-record idempotency retention schedule is missing or is not installation-global.',
            );
        }
    }
}
