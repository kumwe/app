<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Kumwe\CMS\Infrastructure\Persistence\Migration\Migration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationLock;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRepository;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationResult;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Tests\Support\AuthorizationContext;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(MigrationResult::class)]
final class MigrationRunnerTest extends TestCase
{
    public function testMigrationsAreAppliedInLexicalOrderAndOnlyOnce(): void
    {
        $repository = new InMemoryMigrationRepository();
        $calls = new MigrationCallLog();
        $runner = $this->runner($repository, [
            new RecordingMigration('20260804000200_second', $calls),
            new RecordingMigration('20260804000100_first', $calls),
        ]);

        self::assertSame(
            ['20260804000100_first', '20260804000200_second'],
            $runner->migrate($this->context())->applied,
        );
        self::assertSame(['20260804000100_first', '20260804000200_second'], $calls->ids);
        self::assertFalse($runner->migrate($this->context())->changed());
    }

    public function testChecksumDriftIsRejected(): void
    {
        $repository = new InMemoryMigrationRepository();
        $repository->checksums['20260804000100_first'] = str_repeat('0', 64);
        $runner = $this->runner($repository, [
            new RecordingMigration('20260804000100_first', new MigrationCallLog()),
        ]);
        $this->expectException(RuntimeException::class);

        $runner->migrate($this->context());
    }

    /**
     * @param list<Migration> $migrations
     */
    private function runner(InMemoryMigrationRepository $repository, array $migrations): MigrationRunner
    {
        $database = $this->createStub(Connection::class);
        $database->method('getDatabasePlatform')->willReturn($this->createStub(AbstractPlatform::class));

        return new MigrationRunner(
            $database,
            $repository,
            new DirectMigrationLock(),
            new DirectTransactionManager(),
            $migrations,
            AuthorizationContext::gateway(),
        );
    }

    private function context(): \Kumwe\CMS\Application\Authorization\ExecutionContext
    {
        return AuthorizationContext::system(SystemIdentity::Migration)->context(
            SiteContext::default(),
            'migration-test',
        );
    }
}

final class MigrationCallLog
{
    /** @var list<string> */
    public array $ids = [];
}

final readonly class RecordingMigration implements Migration
{
    public function __construct(private string $migrationId, private MigrationCallLog $log)
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
        $this->log->ids[] = $this->migrationId;
    }
}

final class InMemoryMigrationRepository implements MigrationRepository
{
    /** @var array<string, string> */
    public array $checksums = [];

    public function ensureLedger(): void
    {
    }

    public function applied(): array
    {
        return $this->checksums;
    }

    public function record(string $id, string $checksum, int $executionMilliseconds): void
    {
        $this->checksums[$id] = $checksum;
    }
}

final class DirectMigrationLock implements MigrationLock
{
    public function synchronized(callable $operation): mixed
    {
        return $operation();
    }
}

final class DirectTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
