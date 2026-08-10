<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessStatus;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessTransition;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkItem;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(ProcessInstance::class)]
#[CoversClass(ProcessTransition::class)]
#[CoversClass(ProcessWorkItem::class)]
final class ProcessInstanceTest extends TestCase
{
    public function testTransitionAndCancellationAdvanceOptimisticVersion(): void
    {
        $created = new DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $process = $this->process($created);
        $advanced = $process->transition(['step' => 2], ProcessStatus::RUNNING, $created->modify('+1 minute'));
        $cancelled = $advanced->cancel('operator-7', 'Supplier declined.', $created->modify('+2 minutes'));

        self::assertSame(2, $advanced->version());
        self::assertSame(3, $cancelled->version());
        self::assertSame(ProcessStatus::CANCELLED, $cancelled->status());
        self::assertSame('operator-7', $cancelled->cancellationBy());
        self::assertSame(['step' => 2], $cancelled->state());
    }

    public function testTerminalProcessCannotTransitionAgain(): void
    {
        $created = new DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $completed = $this->process($created)->transition(
            ['step' => 2],
            ProcessStatus::COMPLETED,
            $created->modify('+1 minute'),
        );

        $this->expectException(InvalidArgumentException::class);
        $completed->transition(['step' => 3], ProcessStatus::RUNNING, $created->modify('+2 minutes'));
    }

    public function testTransitionRejectsDuplicateWorkIdentity(): void
    {
        $id = Uuid::uuid7()->toString();
        $work = new ProcessWorkItem(
            $id,
            ProcessWorkKind::COMMAND,
            'inventory.reserve',
            ['sku' => 'SKU-1'],
            new DateTimeImmutable('2026-08-10T10:00:00+00:00'),
        );

        $this->expectException(InvalidArgumentException::class);
        new ProcessTransition(['step' => 1], ProcessStatus::RUNNING, [$work, $work]);
    }

    private function process(DateTimeImmutable $created): ProcessInstance
    {
        return new ProcessInstance(
            Uuid::uuid7()->toString(),
            'purchase.fulfilment',
            'order-77',
            'default',
            'organization-1',
            'actor-1',
            null,
            1,
            ProcessStatus::RUNNING,
            ['step' => 1],
            $created,
            $created,
        );
    }
}
