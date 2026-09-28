<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence;

use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\StatementBudget;
use Kumwe\App\Infrastructure\Persistence\StatementBudgetExceeded;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the declared statement bounds and the refusal each one raises.
 *
 * @since  2.0.0
 */
#[CoversClass(StatementBudget::class)]
#[CoversClass(StatementBudgetExceeded::class)]
final class StatementBudgetTest extends TestCase
{
    /**
     * The default browse budget is five seconds and eight mebibytes, and both extremes are accepted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDefaultAndExtremeBudgetsAreAccepted(): void
    {
        $default = new StatementBudget();
        self::assertSame(5_000, $default->timeoutMilliseconds);
        self::assertSame(8 * 1024 * 1024, $default->maximumBytes);
        $widest = new StatementBudget(StatementBudget::MAXIMUM_TIMEOUT_MILLISECONDS, StatementBudget::MAXIMUM_BYTES);
        self::assertSame(30_000, $widest->timeoutMilliseconds);
        self::assertSame(64 * 1024 * 1024, $widest->maximumBytes);
        $narrowest = new StatementBudget(1, 1);
        self::assertSame(1, $narrowest->timeoutMilliseconds);
        self::assertSame(1, $narrowest->maximumBytes);
    }

    /**
     * A bound outside its range is refused.
     *
     * @param   int  $timeout  Milliseconds.
     * @param   int  $bytes    Bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('outOfRange')]
    public function testABoundOutsideItsRangeIsRefused(int $timeout, int $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StatementBudget($timeout, $bytes);
    }

    /**
     * Budgets just outside each range.
     *
     * @return  array<string, array{int, int}>  Timeout and byte pairs.
     *
     * @since   2.0.0
     */
    public static function outOfRange(): array
    {
        return [
            'zero timeout' => [0, 1_024],
            'timeout above thirty seconds' => [30_001, 1_024],
            'zero bytes' => [1_000, 0],
            'bytes above sixty-four mebibytes' => [1_000, 64 * 1024 * 1024 + 1],
        ];
    }

    /**
     * Each refusal names its bound and limit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachRefusalNamesItsBoundAndLimit(): void
    {
        $time = new StatementBudgetExceeded(StatementBudgetExceeded::TIME, 250);
        self::assertSame('time', $time->bound);
        self::assertSame(250, $time->limit);
        self::assertSame('The statement was cancelled at its 250 ms execution-time budget.', $time->getMessage());
        $bytes = new StatementBudgetExceeded(StatementBudgetExceeded::BYTES, 4_096);
        self::assertSame('bytes', $bytes->bound);
        self::assertSame('The statement result passed its 4096 byte budget.', $bytes->getMessage());
    }
}
