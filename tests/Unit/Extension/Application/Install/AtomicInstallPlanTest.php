<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Application\Install;

use Kumwe\App\Extension\Application\Install\AtomicInstallPlan;
use Kumwe\App\Extension\Application\Install\InstallAction;
use Kumwe\App\Extension\Application\Install\InstallState;
use Kumwe\App\Extension\Application\Install\InvalidInstallTransition;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Extension\Domain\PackageChecksum;
use Kumwe\App\Extension\Domain\SemanticVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtomicInstallPlan::class)]
final class AtomicInstallPlanTest extends TestCase
{
    public function testCompletesEveryActionInOrderBeforeCommit(): void
    {
        $plan = $this->plan();
        $plan->start();

        while (($action = $plan->nextAction()) !== null) {
            $plan->complete($action);
        }

        $plan->commit();

        self::assertSame(InstallState::Committed, $plan->state());
        self::assertCount(count(InstallAction::cases()), $plan->completedActions());
    }

    public function testRejectsOutOfOrderActions(): void
    {
        $plan = $this->plan();
        $plan->start();
        $this->expectException(InvalidInstallTransition::class);

        $plan->complete(InstallAction::ActivateFiles);
    }

    public function testFailureCanBeCompensatedByRollback(): void
    {
        $plan = $this->plan();
        $plan->start();
        $plan->fail('migration.failed');
        $plan->beginRollback();
        $plan->finishRollback();

        self::assertSame(InstallState::RolledBack, $plan->state());
        self::assertSame('migration.failed', $plan->failureCode());
    }

    private function plan(): AtomicInstallPlan
    {
        return new AtomicInstallPlan(
            'a9c73b0d-4468-44ce-a1a0-ac973d684ce0',
            ExtensionIdentifier::fromString('acme/editor'),
            SemanticVersion::fromString('1.0.0'),
            PackageChecksum::calculate('package'),
            null,
        );
    }
}
