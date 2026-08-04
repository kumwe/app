<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use DomainException;
use Kumwe\CMS\Application\Automation\JobEnvelope;
use Kumwe\CMS\Application\Automation\JobStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobEnvelope::class)]
final class JobEnvelopeTest extends TestCase
{
    public function testAvailableJobCanBeClaimedAndCompletedByLeaseOwner(): void
    {
        $now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $pending = $this->pendingJob();

        self::assertTrue($pending->isClaimableAt($now));
        $reserved = $pending->claim('worker-1', $now, 60);
        self::assertSame(JobStatus::RESERVED, $reserved->status());
        self::assertSame(1, $reserved->attempts());
        self::assertSame('worker-1', $reserved->lease()?->owner());
        self::assertSame(
            JobStatus::COMPLETED,
            $reserved->complete('worker-1', new DateTimeImmutable('2026-08-04T12:00:30+00:00'))->status(),
        );
    }

    public function testReservedJobCannotBeClaimedAgain(): void
    {
        $now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $reserved = $this->pendingJob()->claim('worker-1', $now, 60);

        $this->expectException(DomainException::class);
        $reserved->claim('worker-2', $now, 60);
    }

    public function testRetryReleasesLeaseAndHonoursAvailability(): void
    {
        $now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $retryAt = new DateTimeImmutable('2026-08-04T12:01:30+00:00');
        $pending = $this->pendingJob()
            ->claim('worker-1', $now, 60)
            ->releaseForRetry('worker-1', new DateTimeImmutable('2026-08-04T12:00:30+00:00'), $retryAt);

        self::assertSame(JobStatus::PENDING, $pending->status());
        self::assertNull($pending->lease());
        self::assertFalse($pending->isClaimableAt(new DateTimeImmutable('2026-08-04T12:01:29+00:00')));
        self::assertTrue($pending->isClaimableAt($retryAt));
    }

    public function testExpiredFinalLeaseMovesJobToDeadState(): void
    {
        $now = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $job = JobEnvelope::pending(
            '00000000-0000-7000-8000-000000000001',
            'default',
            'search.reindex',
            ['content_id' => 'content-1'],
            $now,
            $now,
            maximumAttempts: 1,
        );

        $dead = $job->claim('worker-1', $now, 30)
            ->releaseExpiredLease(new DateTimeImmutable('2026-08-04T12:00:30+00:00'));

        self::assertSame(JobStatus::DEAD, $dead->status());
        self::assertFalse($dead->isClaimableAt(new DateTimeImmutable('2026-08-04T13:00:00+00:00')));
    }

    private function pendingJob(): JobEnvelope
    {
        return JobEnvelope::pending(
            '00000000-0000-7000-8000-000000000001',
            'default',
            'search.reindex',
            ['content_id' => 'content-1'],
            new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-04T11:59:00+00:00'),
        );
    }
}
