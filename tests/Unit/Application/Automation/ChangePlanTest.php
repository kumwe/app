<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Automation;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kumwe\App\Application\Automation\ChangePlan;
use Kumwe\App\Application\Automation\ConfirmationRequirement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangePlan::class)]
final class ChangePlanTest extends TestCase
{
    public function testDigestIsCanonicalAndPayloadChangesAreDetected(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $first = ChangePlan::create(
            'plan-1',
            'content.publish',
            ['version' => 3, 'content' => ['title' => 'Kumwe', 'state' => 'draft']],
            $createdAt,
            300,
        );
        $reordered = ChangePlan::create(
            'plan-2',
            'content.publish',
            ['content' => ['state' => 'draft', 'title' => 'Kumwe'], 'version' => 3],
            $createdAt,
            300,
        );

        self::assertSame($first->digest(), $reordered->digest());
        self::assertNotSame(
            $first->digest(),
            ChangePlan::create(
                'plan-3',
                'content.publish',
                ['content' => ['state' => 'draft', 'title' => 'Kumwe'], 'version' => 4],
                $createdAt,
                300,
            )->digest(),
        );
    }

    public function testPlanExpiresAtTheExactExpiryInstant(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $plan = ChangePlan::create('plan-1', 'content.publish', [], $createdAt, 60);

        self::assertFalse($plan->isExpiredAt(new DateTimeImmutable('2026-08-04T12:00:59+00:00')));
        self::assertTrue($plan->isExpiredAt(new DateTimeImmutable('2026-08-04T12:01:00+00:00')));

        $this->expectException(DomainException::class);
        $plan->assertCanApply($plan->digest(), new DateTimeImmutable('2026-08-04T12:01:00+00:00'));
    }

    public function testExplicitConfirmationMustMatchThePlanDigest(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-04T12:00:00+00:00');
        $plan = ChangePlan::create(
            'plan-1',
            'content.publish',
            ['id' => 'content-1'],
            $createdAt,
            60,
            ConfirmationRequirement::EXPLICIT,
        );

        self::assertTrue($plan->requiresConfirmation());
        self::assertNotNull($plan->confirmationToken());
        $plan->assertCanApply(
            $plan->digest(),
            new DateTimeImmutable('2026-08-04T12:00:30+00:00'),
            $plan->confirmationToken(),
        );

        $this->expectException(DomainException::class);
        $plan->assertCanApply(
            $plan->digest(),
            new DateTimeImmutable('2026-08-04T12:00:30+00:00'),
            'confirm-the-wrong-plan',
        );
    }

    public function testInvalidTimeToLiveIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ChangePlan::create('plan-1', 'content.publish', [], new DateTimeImmutable(), 0);
    }
}
