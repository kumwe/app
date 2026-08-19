<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\FailureClassification;
use Kumwe\App\Application\Automation\JitterSource;
use Kumwe\App\Application\Automation\PermanentFailure;
use Kumwe\App\Application\Automation\RetryDecision;
use Kumwe\App\Application\Automation\RetryPolicy;
use Kumwe\App\Application\Automation\TransientFailure;
use Psr\Clock\ClockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryPolicy::class)]
#[CoversClass(RetryDecision::class)]
final class RetryPolicyTest extends TestCase
{
    public function testTransientFailureUsesDeterministicFullJitterAndClock(): void
    {
        $policy = new RetryPolicy($this->clock(), $this->jitter(7), 10, 60);
        $failure = new class ('Temporary database outage') extends RuntimeException implements TransientFailure {
        };
        $decision = $policy->decide($failure, 2, 5);

        self::assertSame(FailureClassification::TRANSIENT, $decision->classification);
        self::assertTrue($decision->shouldRetry);
        self::assertSame(7, $decision->delaySeconds);
        self::assertEquals(new DateTimeImmutable('2026-08-04T12:00:07+00:00'), $decision->retryAt);
    }

    public function testPermanentAndExhaustedFailuresAreNotRetried(): void
    {
        $policy = new RetryPolicy($this->clock(), $this->jitter(0), 10, 60);
        $permanent = new PermanentFailure('Invalid content');

        self::assertSame(FailureClassification::PERMANENT, $policy->classify($permanent));
        self::assertFalse($policy->decide($permanent, 1, 5)->shouldRetry);
        self::assertFalse($policy->decide(new RuntimeException('Outage'), 5, 5)->shouldRetry);
        self::assertSame(
            FailureClassification::PERMANENT,
            $policy->classify(new InvalidArgumentException('Invalid payload')),
        );
    }

    public function testJitterOutsideRequestedRangeIsRejected(): void
    {
        $policy = new RetryPolicy($this->clock(), $this->jitter(11), 10, 60);

        $this->expectException(DomainException::class);
        $policy->decide(new RuntimeException('Outage'), 1, 5);
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-04T12:00:00+00:00');
            }
        };
    }

    private function jitter(int $value): JitterSource
    {
        return new class ($value) implements JitterSource {
            public function __construct(private readonly int $value)
            {
            }

            public function between(int $minimum, int $maximum): int
            {
                return $this->value;
            }
        };
    }
}
