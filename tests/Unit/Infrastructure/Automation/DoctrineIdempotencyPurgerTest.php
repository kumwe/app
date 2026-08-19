<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Automation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Automation\DoctrineIdempotencyPurger;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(DoctrineIdempotencyPurger::class)]
final class DoctrineIdempotencyPurgerTest extends TestCase
{
    public function testPurgesAStableBoundedBatch(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"',
        );
        $database->expects(self::once())->method('fetchFirstColumn')->willReturnCallback(
            static function (string $sql): array {
                self::assertStringContainsString('ORDER BY expires_at, id LIMIT 2', $sql);
                self::assertStringContainsString('owner_token IS NULL', $sql);
                self::assertStringContainsString('locked_until <= ?', $sql);
                return [
                    '00000000-0000-7000-8000-000000000001',
                    '00000000-0000-7000-8000-000000000002',
                ];
            },
        );
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters): int {
                self::assertStringContainsString('DELETE FROM', $sql);
                self::assertStringContainsString('expires_at <= ?', $sql);
                self::assertStringContainsString('locked_until <= ?', $sql);
                self::assertCount(3, $parameters);
                self::assertSame($parameters[1], $parameters[2]);
                return 2;
            },
        );

        self::assertSame(2, $this->purger($database)->purgeExpired(2));
    }

    public function testRejectsUnboundedBatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->purger($this->createStub(Connection::class))->purgeExpired(10_001);
    }

    private function purger(Connection $database): DoctrineIdempotencyPurger
    {
        return new DoctrineIdempotencyPurger(
            $database,
            new TableNames($database, 'kumwe_'),
            new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2026-08-05T10:00:00+00:00');
                }
            },
        );
    }
}
