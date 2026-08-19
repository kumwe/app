<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Kumwe\App\Infrastructure\Persistence\Migration\Migration;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationPlan;
use Kumwe\App\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\App\Infrastructure\Persistence\Migration\NonTransactionalMigrationRecovery;
use Kumwe\App\Infrastructure\Persistence\ReadinessProbe;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ReadinessProbe::class)]
final class ReadinessProbeTest extends TestCase
{
    public function testReportsReadyForACompleteCompatibleLedgerWithoutRecoveryAttempts(): void
    {
        $migration = new ReadinessMigration('20260804000100_create_system_tables');
        $repository = $this->createStub(MigrationRepository::class);
        $repository->method('applied')->willReturn([$migration->id() => $migration->checksum()]);
        $recovery = $this->createStub(NonTransactionalMigrationRecovery::class);

        self::assertTrue($this->probe($repository, new MigrationPlan([$migration]), $recovery)->ready());
    }

    public function testReportsNotReadyWhenLedgerIsMissing(): void
    {
        $migration = new ReadinessMigration('20260804000100_create_system_tables');
        $repository = $this->createStub(MigrationRepository::class);
        $recovery = $this->createStub(NonTransactionalMigrationRecovery::class);

        self::assertFalse(
            $this->probe($repository, new MigrationPlan([$migration]), $recovery, ledgerExists: false)->ready(),
        );
    }

    public function testReportsNotReadyForANewerOrGappedLedger(): void
    {
        $migration = new ReadinessMigration('20260804000100_create_system_tables');
        $repository = $this->createStub(MigrationRepository::class);
        $repository->method('applied')->willReturn([
            $migration->id() => $migration->checksum(),
            '20260804000200_future' => str_repeat('a', 64),
        ]);
        $recovery = $this->createStub(NonTransactionalMigrationRecovery::class);

        self::assertFalse($this->probe($repository, new MigrationPlan([$migration]), $recovery)->ready());
    }

    public function testReportsNotReadyWhileAnyMigrationRecoveryAttemptIsUnresolved(): void
    {
        $migration = new ReadinessMigration('20260804000100_create_system_tables');
        $repository = $this->createStub(MigrationRepository::class);
        $repository->method('applied')->willReturn([$migration->id() => $migration->checksum()]);
        $recovery = $this->createStub(NonTransactionalMigrationRecovery::class);
        $recovery->method('hasUnresolvedAttempts')->willReturn(true);

        self::assertFalse($this->probe($repository, new MigrationPlan([$migration]), $recovery)->ready());
    }

    private function probe(
        MigrationRepository $repository,
        MigrationPlan $plan,
        NonTransactionalMigrationRecovery $recovery,
        bool $ledgerExists = true,
    ): ReadinessProbe {
        $schema = $this->createStub(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn($ledgerExists);
        $database = $this->createStub(Connection::class);
        $database->method('createSchemaManager')->willReturn($schema);
        $database->method('quoteSingleIdentifier')->willReturn('"kumwe_schema_migrations"');
        $database->method('fetchOne')->willReturn(1);

        return new ReadinessProbe(
            $database,
            new NullLogger(),
            new TableNames($database, 'kumwe_'),
            $repository,
            $plan,
            $recovery,
        );
    }
}

final readonly class ReadinessMigration implements Migration
{
    public function __construct(private string $migrationId)
    {
    }

    public function id(): string
    {
        return $this->migrationId;
    }

    public function checksum(): string
    {
        return hash('sha256', $this->migrationId);
    }

    public function up(Connection $database): void
    {
    }
}
