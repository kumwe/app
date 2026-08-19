<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Plan;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Delivery\Http\Api\Plan\SafePlan;
use Kumwe\App\Delivery\Http\Api\Plan\SafePlanFactory;
use Kumwe\App\Delivery\Http\Api\Plan\SafePlanOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(SafePlan::class)]
#[CoversClass(SafePlanFactory::class)]
final class SafePlanTest extends TestCase
{
    public function testCreatesAnExpiringNonExecutablePlan(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-04T12:00:00+00:00');
            }
        };
        $plan = (new SafePlanFactory($clock, 600))->create(SafePlanOperation::SeoReview, 'homepage');
        $data = $plan->toArray();

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/D', $plan->id());
        self::assertSame('plan_only', $data['mode']);
        self::assertFalse($data['apply_supported']);
        self::assertSame('2026-08-04T12:10:00+00:00', $data['expires_at']);
    }

    public function testRejectsUnsafeTargets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SafePlan(
            '01989c5e-7c00-7000-8000-000000000001',
            SafePlanOperation::ContentReview,
            "unsafe\nvalue",
            new DateTimeImmutable('2026-08-04T12:00:00Z'),
            new DateTimeImmutable('2026-08-04T12:10:00Z'),
        );
    }
}
