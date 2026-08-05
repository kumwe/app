<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Automation;

use Kumwe\CMS\Application\Automation\IdempotencyPurger;
use Kumwe\CMS\Application\Automation\Job\PurgeIdempotencyRecordsHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurgeIdempotencyRecordsHandler::class)]
final class PurgeIdempotencyRecordsHandlerTest extends TestCase
{
    public function testProcessesBoundedBatchesUntilTheBacklogIsDrained(): void
    {
        $purger = new CountingIdempotencyPurger([100, 100, 25]);
        $handler = new PurgeIdempotencyRecordsHandler($purger);

        $handler->handle(['batch_size' => 100, 'maximum_batches' => 10]);

        self::assertSame(3, $purger->calls);
    }

    public function testHonoursMaximumBatchLimit(): void
    {
        $purger = new CountingIdempotencyPurger([100, 100, 100]);
        $handler = new PurgeIdempotencyRecordsHandler($purger);

        $handler->handle(['batch_size' => 100, 'maximum_batches' => 2]);

        self::assertSame(2, $purger->calls);
    }
}

final class CountingIdempotencyPurger implements IdempotencyPurger
{
    public int $calls = 0;

    /** @param list<int> $results */
    public function __construct(private array $results)
    {
    }

    public function purgeExpired(int $batchSize = 1_000): int
    {
        return $this->results[$this->calls++] ?? 0;
    }
}
