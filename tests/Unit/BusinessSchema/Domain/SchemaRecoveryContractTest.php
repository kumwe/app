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
use Kumwe\App\BusinessSchema\Domain\SchemaRecoveryEvidence;
use Kumwe\App\BusinessSchema\Domain\SchemaRisk;
use Kumwe\App\BusinessSchema\Domain\SchemaStepStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaPlan::class)]
#[CoversClass(SchemaPlanStep::class)]
#[CoversClass(SchemaRecoveryEvidence::class)]
final class SchemaRecoveryContractTest extends TestCase
{
    private const PLAN_ID = '018f4f24-98d8-7ad4-8f3f-38c909178b6b';

    private const DEFINITION_ID = '018f4f24-98d8-7ad4-8f3f-38c909178b6c';

    private const EVIDENCE_ID = '018f4f24-98d8-7ad4-8f3f-38c909178b6d';

    public function testCanonicalPlanOrderAndChecksumSurviveEveryRecoveryTransition(): void
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $later = $now->modify('+1 minute');
        $operations = [
            self::operation(2, SchemaOperationKind::AddForeignKey, SchemaRisk::BehaviorChanging),
            self::operation(1, SchemaOperationKind::CreateTable, SchemaRisk::OnlineSafeAdditive),
        ];
        $pending = self::plan($operations, SchemaRisk::BehaviorChanging, $now);

        self::assertSame([1, 2], array_map(
            static fn (SchemaOperation $operation): int => $operation->ordinal,
            $pending->operations(),
        ));
        $checksum = $pending->checksum();
        $approved = $pending->approve('approver', $later, $checksum, str_repeat('d', 64));
        $executing = $approved->begin(17, $later);
        $interrupted = $executing->recoveryRequired('connection_lost', ['fence' => 17], $later);
        $resumed = $interrupted->resume(18, $later);
        $completed = $resumed->complete(['fence' => 18, 'resumed' => true], $later);

        foreach ([$pending, $approved, $executing, $interrupted, $resumed, $completed] as $plan) {
            self::assertSame($checksum, $plan->checksum());
            self::assertSame($checksum, SchemaPlan::fromArray($plan->toArray())->checksum());
        }
        self::assertSame(SchemaPlanStatus::Completed, $completed->status);
        self::assertSame(18, $completed->executionFence);
    }

    public function testPersistedPlanRejectsCanonicalAndApprovalChecksumDrift(): void
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $pending = self::plan([
            self::operation(1, SchemaOperationKind::CreateTable, SchemaRisk::OnlineSafeAdditive),
        ], SchemaRisk::OnlineSafeAdditive, $now);
        $drifted = $pending->toArray();
        $drifted['operations'][0]['subject'] = 'different_subject';

        try {
            SchemaPlan::fromArray($drifted);
            self::fail('Canonical plan drift must invalidate the persisted plan checksum.');
        } catch (InvalidBusinessSchema $exception) {
            self::assertStringContainsString('checksum', $exception->getMessage());
        }

        $approved = $pending->approve('approver', $now, $pending->checksum());
        $approvalDrifted = $approved->toArray();
        $approvalDrifted['approval']['approved_checksum'] = str_repeat('f', 64);

        $this->expectException(InvalidBusinessSchema::class);
        $this->expectExceptionMessage('approval is bound to a different canonical plan');
        SchemaPlan::fromArray($approvalDrifted);
    }

    public function testLockingApprovalRequiresConfirmationAndSourceBoundEvidence(): void
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $pending = self::plan([
            self::operation(1, SchemaOperationKind::AlterColumn, SchemaRisk::RebuildOrLocking),
        ], SchemaRisk::RebuildOrLocking, $now);

        foreach (
            [
                [null, null],
                [str_repeat('d', 64), null],
            ] as [$confirmation, $evidence]
        ) {
            try {
                $pending->approve('approver', $now, $pending->checksum(), $confirmation, $evidence);
                self::fail('A locking plan must require both confirmation and recovery evidence.');
            } catch (InvalidBusinessSchema) {
                self::assertSame(SchemaPlanStatus::PendingApproval, $pending->status);
            }
        }

        $approved = $pending->approve(
            'approver',
            $now,
            $pending->checksum(),
            str_repeat('d', 64),
            self::EVIDENCE_ID,
        );
        self::assertSame(SchemaPlanStatus::Approved, $approved->status);
        self::assertSame(self::EVIDENCE_ID, $approved->recoveryEvidenceId);
    }

    public function testRecoveryEvidenceQualifiesOnlyForTheExactFreshEnvironmentAndSource(): void
    {
        $backupAt = new DateTimeImmutable('2026-08-08T09:00:00+00:00');
        $verifiedAt = $backupAt->modify('+15 minutes');
        $evidence = new SchemaRecoveryEvidence(
            self::EVIDENCE_ID,
            'default',
            'pgsql',
            '18.0',
            '3.0.0',
            str_repeat('a', 64),
            str_repeat('b', 64),
            true,
            $backupAt,
            $verifiedAt,
            'recovery-operator',
            'drill-2026-08-08',
            ['clean_target_restore' => true],
        );

        self::assertTrue($evidence->qualifies(
            'default',
            'pgsql',
            '18.0',
            '3.0.0',
            str_repeat('a', 64),
            $backupAt,
        ));
        self::assertFalse($evidence->qualifies(
            'default',
            'pgsql',
            '18.1',
            '3.0.0',
            str_repeat('a', 64),
            $backupAt,
        ));
        self::assertFalse($evidence->qualifies(
            'default',
            'pgsql',
            '18.0',
            '3.0.0',
            str_repeat('c', 64),
            $backupAt,
        ));
        self::assertFalse($evidence->qualifies(
            'default',
            'pgsql',
            '18.0',
            '3.0.0',
            str_repeat('a', 64),
            $verifiedAt->modify('+1 second'),
        ));
        self::assertSame($evidence->toArray(), SchemaRecoveryEvidence::fromArray($evidence->toArray())->toArray());
    }

    public function testInterruptedStepAdvancesAttemptAndFenceBeforeCompletion(): void
    {
        $now = new DateTimeImmutable('2026-08-08T10:00:00+00:00');
        $operation = self::operation(1, SchemaOperationKind::Backfill, SchemaRisk::BackfillRequired);
        $failed = SchemaPlanStep::pending(self::PLAN_ID, $operation, $now)
            ->start(23, str_repeat('a', 64), $now)
            ->checkpoint(['last_identity' => self::DEFINITION_ID], $now)
            ->fail('connection_lost', ['processed_rows' => 250], $now);
        $completed = $failed->resume(24, $now)
            ->complete(str_repeat('b', 64), ['processed_rows' => 500], $now);

        self::assertSame(2, $completed->attempt);
        self::assertSame(24, $completed->executionFence);
        self::assertSame(SchemaStepStatus::Completed, $completed->state);
        self::assertSame(['last_identity' => self::DEFINITION_ID], $completed->cursor);
    }

    /** @param list<SchemaOperation> $operations */
    private static function plan(array $operations, SchemaRisk $risk, DateTimeImmutable $now): SchemaPlan
    {
        return new SchemaPlan(
            self::PLAN_ID,
            self::DEFINITION_ID,
            'default',
            1,
            2,
            str_repeat('8', 64),
            str_repeat('9', 64),
            str_repeat('a', 64),
            str_repeat('b', 64),
            $operations,
            $risk,
            SchemaPlanStatus::PendingApproval,
            1,
            'planner',
            $now,
        );
    }

    private static function operation(
        int $ordinal,
        SchemaOperationKind $kind,
        SchemaRisk $risk,
    ): SchemaOperation {
        return new SchemaOperation(
            $ordinal,
            $kind,
            $risk,
            'record',
            'subject_' . $ordinal,
            $kind === SchemaOperationKind::CreateTable ? null : ['physical_name' => 'before_table'],
            $kind === SchemaOperationKind::DropTable ? null : ['physical_name' => 'after_table'],
            $kind === SchemaOperationKind::Backfill,
            $kind === SchemaOperationKind::CreateTable ? 'compensate_safe_addition' : 'resume_required',
        );
    }
}
