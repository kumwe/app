<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Persistence;

use Joomla\Database\DatabaseInterface;
use Kumwe\CMS\Infrastructure\Persistence\JoomlaTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(JoomlaTransactionManager::class)]
final class JoomlaTransactionManagerTest extends TestCase
{
    public function testCommitsSuccessfulOperation(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('transactionStart');
        $database->expects(self::once())->method('transactionCommit');
        $database->expects(self::never())->method('transactionRollback');

        self::assertSame('result', (new JoomlaTransactionManager($database))->transactional(
            static fn (): string => 'result',
        ));
    }

    public function testRollsBackFailedOperation(): void
    {
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('transactionStart');
        $database->expects(self::never())->method('transactionCommit');
        $database->expects(self::once())->method('transactionRollback');
        $this->expectException(RuntimeException::class);

        (new JoomlaTransactionManager($database))->transactional(
            static fn (): never => throw new RuntimeException('failed'),
        );
    }
}
