<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessIntegration;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessIntegration\Application\EventContractRegistry;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerService;
use Kumwe\App\BusinessIntegration\Application\ProcessManagerStore;
use Kumwe\App\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\App\BusinessIntegration\Domain\ProcessStatus;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkItem;
use Kumwe\App\BusinessIntegration\Domain\ProcessWorkKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Pins what an operator may enqueue while cancelling a running process.
 *
 * Cancellation is the one transition that ends a process from outside its own handler, so the work it
 * carries is the only work nothing downstream will ever validate. Admitting a command or a timer here
 * would let a cancelled process keep driving the system forward; only compensation — undoing what the
 * process already did — belongs on the way out.
 *
 * @since  2.0.0
 */
#[CoversClass(ProcessManagerService::class)]
final class ProcessCancellationWorkTest extends TestCase
{
    /**
     * Cancelling hands the store exactly the compensation work the operator supplied.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCancellingEnqueuesTheCompensationWorkItWasGiven(): void
    {
        $clock = new ProcessCancellationClock();
        $running = $this->running();
        $compensation = new ProcessWorkItem(
            Uuid::uuid7()->toString(),
            ProcessWorkKind::COMPENSATION,
            'acme.inventory.release',
            ['sku' => 'SKU-7'],
            $clock->now(),
        );
        $store = $this->createMock(ProcessManagerStore::class);
        $store->expects(self::once())->method('load')->willReturn($running);
        $store->expects(self::once())->method('save')->willReturnCallback(
            static function (ProcessInstance $process, int $expectedVersion, iterable $work) use ($compensation): void {
                self::assertSame(ProcessStatus::CANCELLED, $process->status());
                self::assertSame(1, $expectedVersion);
                self::assertSame([$compensation], is_array($work) ? $work : iterator_to_array($work));
            },
        );

        $cancelled = $this->service($store, $clock)->cancel(
            $running->id(),
            1,
            'operator-1',
            'Stock was returned to the supplier.',
            [$compensation],
        );

        self::assertSame(ProcessStatus::CANCELLED, $cancelled->status());
        self::assertSame('operator-1', $cancelled->cancellationBy());
    }

    /**
     * Name every work kind that drives a process forward rather than unwinding it.
     *
     * @return  list<array{ProcessWorkKind}>  Every work kind cancellation must refuse.
     *
     * @since   2.0.0
     */
    public static function forwardWorkKinds(): array
    {
        return [
            'command' => [ProcessWorkKind::COMMAND],
            'timer' => [ProcessWorkKind::TIMER],
        ];
    }

    /**
     * Work that would drive a cancelled process forward is refused before anything is written.
     *
     * @param   ProcessWorkKind  $kind  Work kind offered alongside the cancellation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('forwardWorkKinds')]
    public function testCancellingRefusesWorkThatIsNotCompensation(ProcessWorkKind $kind): void
    {
        $clock = new ProcessCancellationClock();
        $running = $this->running();
        $store = $this->createMock(ProcessManagerStore::class);
        $store->expects(self::once())->method('load')->willReturn($running);
        $store->expects(self::never())->method('save');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cancellation may enqueue only compensation work.');

        $this->service($store, $clock)->cancel(
            $running->id(),
            1,
            'operator-1',
            'Stock was returned to the supplier.',
            [new ProcessWorkItem(
                Uuid::uuid7()->toString(),
                $kind,
                'acme.inventory.reserve',
                ['sku' => 'SKU-7'],
                $clock->now(),
            )],
        );
    }

    /**
     * Build the service under test around one store double.
     *
     * @param   ProcessManagerStore  $store  Durable state and work repository double.
     * @param   ClockInterface       $clock  Transition clock.
     *
     * @return  ProcessManagerService  Service bound to the given collaborators.
     *
     * @since   2.0.0
     */
    private function service(ProcessManagerStore $store, ClockInterface $clock): ProcessManagerService
    {
        return new ProcessManagerService($store, new EventContractRegistry([], []), $clock);
    }

    /**
     * A running process at version one, ready to be cancelled.
     *
     * @return  ProcessInstance  Running instance owned by a system identity.
     *
     * @since   2.0.0
     */
    private function running(): ProcessInstance
    {
        $at = new DateTimeImmutable('2026-08-10T10:00:00+00:00');

        return new ProcessInstance(
            Uuid::uuid7()->toString(),
            'acme.order.fulfilment',
            'order-4711',
            'default',
            null,
            null,
            'system:integration',
            1,
            ProcessStatus::RUNNING,
            ['stage' => 'reserved'],
            $at,
            $at,
        );
    }
}

/**
 * A clock that answers one fixed instant, so a cancellation's recorded time is never the wall clock.
 *
 * @since  2.0.0
 */
final readonly class ProcessCancellationClock implements ClockInterface
{
    /**
     * Answer the fixed instant every cancellation in this case is stamped with.
     *
     * @return  DateTimeImmutable  The one instant this clock ever reports.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-10T11:00:00+00:00');
    }
}
