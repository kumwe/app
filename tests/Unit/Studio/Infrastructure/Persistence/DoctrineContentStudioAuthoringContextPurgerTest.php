<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineContentStudioAuthoringContextPurger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Pins bounded deterministic retention and malformed-storage refusals at the Doctrine seam.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineContentStudioAuthoringContextPurger::class)]
final class DoctrineContentStudioAuthoringContextPurgerTest extends TestCase
{
    /**
     * One cutoff drives the ordered candidate read and the guarded delete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPurgesAStableBoundedBatchAtOneCutoff(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"',
        );
        $key = 'contexts/' . str_repeat('a', 64);
        $database->expects(self::once())->method('fetchFirstColumn')->willReturnCallback(
            static function (string $sql, array $parameters) use ($key): array {
                self::assertStringContainsString('expires_at <= ?', $sql);
                self::assertStringContainsString('ORDER BY expires_at, context_key LIMIT 1', $sql);
                self::assertCount(1, $parameters);

                return [$key];
            },
        );
        $database->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $parameters) use ($key): int {
                self::assertStringContainsString('context_key IN (?) AND expires_at <= ?', $sql);
                self::assertSame([$key], $parameters[0]);
                self::assertInstanceOf(DateTimeImmutable::class, $parameters[1]);
                self::assertSame('2026-08-27T08:00:00+00:00', $parameters[1]->format('c'));

                return 1;
            },
        );
        $clock = new CountingRetentionClock();

        self::assertSame(1, $this->purger($database, $clock)->purgeExpired(1));
        self::assertSame(1, $clock->calls);
    }

    /**
     * An unsupported limit is refused before a candidate query can run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnUnboundedBatch(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects(self::never())->method('fetchFirstColumn');

        $this->expectException(InvalidArgumentException::class);
        $this->purger($database, new CountingRetentionClock())->purgeExpired(10_001);
    }

    /**
     * A malformed persisted key stops the pass without issuing any delete.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAMalformedCandidateBeforeDeleting(): void
    {
        $database = $this->createMock(Connection::class);
        $database->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $name): string => '"' . $name . '"',
        );
        $database->expects(self::once())->method('fetchFirstColumn')->willReturn(['not-a-context-key']);
        $database->expects(self::never())->method('executeStatement');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A Studio Content authoring context purge candidate is invalid.');
        $this->purger($database, new CountingRetentionClock())->purgeExpired(1);
    }

    /**
     * Build the production adapter around test doubles.
     *
     * @param   Connection      $database  Doctrine persistence double.
     * @param   ClockInterface  $clock     Trusted cutoff source.
     *
     * @return  DoctrineContentStudioAuthoringContextPurger  Adapter under test.
     *
     * @since   2.0.0
     */
    private function purger(
        Connection $database,
        ClockInterface $clock,
    ): DoctrineContentStudioAuthoringContextPurger {
        return new DoctrineContentStudioAuthoringContextPurger(
            $database,
            new TableNames($database, 'kumwe_'),
            $clock,
        );
    }
}

/**
 * Counts cutoff reads while returning one immutable boundary.
 *
 * @since  2.0.0
 */
final class CountingRetentionClock implements ClockInterface
{
    /**
     * Number of times retention observed the clock.
     *
     * @var    int
     * @since  2.0.0
     */
    public int $calls = 0;

    /**
     * Return the fixed cutoff and record the observation.
     *
     * @return  DateTimeImmutable  Fixed retention boundary.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        $this->calls++;

        return new DateTimeImmutable('2026-08-27T08:00:00+00:00');
    }
}
