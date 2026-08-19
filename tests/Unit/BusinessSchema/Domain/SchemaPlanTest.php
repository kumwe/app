<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSchema\Domain;

use DateTimeImmutable;
use Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;
use Kumwe\App\BusinessSchema\Domain\SchemaPlan;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStatus;
use Kumwe\App\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\App\BusinessSchema\Domain\SchemaRisk;
use Kumwe\App\BusinessSchema\Domain\SchemaStepStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaPlan::class)]
#[CoversClass(SchemaPlanStep::class)]
final class SchemaPlanTest extends TestCase
{
    public function testApprovalIsChecksumBoundAndExecutionIsFenceBound(): void
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $operation = new SchemaOperation(
            1,
            SchemaOperationKind::CreateTable,
            SchemaRisk::OnlineSafeAdditive,
            'record',
            'record',
            null,
            ['physical_name' => 'kb_e_record_12345678901234567890'],
            false,
            'compensate_safe_addition',
        );
        $plan = new SchemaPlan(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            '018f4f24-98d8-7ad4-8f3f-38c909178b6c',
            'default',
            null,
            1,
            null,
            str_repeat('a', 64),
            null,
            str_repeat('b', 64),
            [$operation],
            SchemaRisk::OnlineSafeAdditive,
            SchemaPlanStatus::PendingApproval,
            1,
            'operator',
            $now,
        );

        $approved = $plan->approve('approver', $now, $plan->checksum());
        $executing = $approved->begin(41, $now);
        self::assertSame(41, $executing->executionFence);
        self::assertSame($plan->checksum(), $approved->approval?->approvedChecksum);
        self::assertSame($plan->checksum(), SchemaPlan::fromArray($approved->toArray())->checksum());

        $step = SchemaPlanStep::pending($plan->id, $operation, $now)
            ->start(41, str_repeat('0', 64), $now)
            ->checkpoint(['last_identity' => '018f4f24-98d8-7ad4-8f3f-38c909178b70'], $now)
            ->complete(str_repeat('1', 64), ['fence' => 41], $now);
        self::assertSame(SchemaStepStatus::Completed, $step->state);
        self::assertSame(41, $step->executionFence);
    }

    public function testHighImpactPlanRequiresExactConfirmationAndRecoveryEvidence(): void
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $operation = new SchemaOperation(
            1,
            SchemaOperationKind::DropTable,
            SchemaRisk::Destructive,
            'record',
            'record',
            ['physical_name' => 'kb_e_record_12345678901234567890'],
            null,
            false,
            'restore_required',
        );
        $plan = new SchemaPlan(
            '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            '018f4f24-98d8-7ad4-8f3f-38c909178b6c',
            'default',
            1,
            2,
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
            [$operation],
            SchemaRisk::Destructive,
            SchemaPlanStatus::PendingApproval,
            1,
            'operator',
            $now,
        );

        $this->expectException(InvalidBusinessSchema::class);
        $plan->approve('approver', $now, $plan->checksum(), str_repeat('e', 64));
    }
}
