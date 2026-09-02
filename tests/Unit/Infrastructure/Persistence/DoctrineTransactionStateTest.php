<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the DBAL transaction-state adapter reports exactly what the connection reports.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineTransactionState::class)]
final class DoctrineTransactionStateTest extends TestCase
{
    /**
     * An open connection transaction, at any nesting level, is reported as active.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOpenConnectionTransactionIsReportedAsActive(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);

        self::assertTrue((new DoctrineTransactionState($connection))->isActive());
    }

    /**
     * A connection outside any transaction is reported as inactive.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAConnectionOutsideAnyTransactionIsReportedAsInactive(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        self::assertFalse((new DoctrineTransactionState($connection))->isActive());
    }
}
