<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kumwe\CMS\Application\Automation\JobLease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobLease::class)]
final class JobLeaseTest extends TestCase
{
    public function testLeaseOwnershipAndExpiryAreStrict(): void
    {
        $lease = new JobLease(
            'worker-1',
            new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-04T12:01:00+00:00'),
        );

        $lease->assertActiveOwner('worker-1', new DateTimeImmutable('2026-08-04T12:00:59+00:00'));
        self::assertTrue($lease->isExpiredAt(new DateTimeImmutable('2026-08-04T12:01:00+00:00')));

        $this->expectException(DomainException::class);
        $lease->assertActiveOwner('worker-2', new DateTimeImmutable('2026-08-04T12:00:30+00:00'));
    }

    public function testNonPositiveLeaseWindowIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobLease(
            'worker-1',
            new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-04T12:00:00+00:00'),
        );
    }
}
