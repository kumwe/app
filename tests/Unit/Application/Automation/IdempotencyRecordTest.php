<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\IdempotencyKey;
use Kumwe\App\Application\Automation\IdempotencyRecord;
use Kumwe\App\Application\Automation\IdempotencyResult;
use Kumwe\App\Application\Automation\IdempotencyState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdempotencyRecord::class)]
#[CoversClass(IdempotencyResult::class)]
final class IdempotencyRecordTest extends TestCase
{
    public function testCompletedResultCanBeReplayedForTheSameCanonicalRequest(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $record = IdempotencyRecord::begin(
            IdempotencyKey::fromString('request:01989abc-def0'),
            'user-1',
            'content.create',
            ['title' => 'Kumwe', 'metadata' => ['language' => 'en', 'featured' => true]],
            $createdAt,
            new DateTimeImmutable('2026-08-05T12:00:00+00:00'),
        );
        $result = new IdempotencyResult(201, ['id' => 'content-1', 'version' => 1]);
        $completed = $record->complete($result);

        self::assertSame(IdempotencyState::COMPLETED, $completed->state());
        self::assertSame(
            $result,
            $completed->replay(
                ['metadata' => ['featured' => true, 'language' => 'en'], 'title' => 'Kumwe'],
                new DateTimeImmutable('2026-08-04T13:00:00+00:00'),
            ),
        );
        self::assertSame(201, $result->statusCode());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result->bodyDigest());
    }

    public function testKeyReuseWithDifferentRequestIsRejected(): void
    {
        $record = $this->inProgressRecord();

        $this->expectException(DomainException::class);
        $record->assertRequestMatches(['id' => 'content-2']);
    }

    public function testInProgressRecordCannotBeReplayed(): void
    {
        $record = $this->inProgressRecord();

        $this->expectException(DomainException::class);
        $record->replay(['id' => 'content-1'], new DateTimeImmutable('2026-08-04T12:30:00+00:00'));
    }

    public function testFailedRecordCannotBeReplayed(): void
    {
        $failed = $this->inProgressRecord()->fail();
        self::assertSame(IdempotencyState::FAILED, $failed->state());

        $this->expectException(DomainException::class);
        $failed->replay(['id' => 'content-1'], new DateTimeImmutable('2026-08-04T12:30:00+00:00'));
    }

    public function testInvalidResultStatusIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IdempotencyResult(700, []);
    }

    private function inProgressRecord(): IdempotencyRecord
    {
        return IdempotencyRecord::begin(
            IdempotencyKey::fromString('request:01989abc-def0'),
            'user-1',
            'content.publish',
            ['id' => 'content-1'],
            new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-05T12:00:00+00:00'),
        );
    }
}
