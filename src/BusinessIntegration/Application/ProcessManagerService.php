<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationEvent;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessInstance;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessStatus;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessTransition;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkItem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Executes pure process decisions against an optimistic durable state repository.
 *
 * @since  2.0.0
 */
final readonly class ProcessManagerService
{
    /**
     * Bind process decisions to storage and exact event contracts.
     *
     * @param  ProcessManagerStore    $store      Durable state and work repository.
     * @param  EventContractRegistry  $contracts  Exact event catalog.
     * @param  ClockInterface         $clock      Transition clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ProcessManagerStore $store,
        private EventContractRegistry $contracts,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Start or advance the correlated process using one deterministic handler.
     *
     * @param   ProcessManagerHandler  $handler  Trusted process decision handler.
     * @param   IntegrationEvent       $event    Durable triggering fact.
     *
     * @return  ProcessInstance  Persisted state after the decision.
     *
     * @since   2.0.0
     */
    public function handle(ProcessManagerHandler $handler, IntegrationEvent $event): ProcessInstance
    {
        $this->contracts->assertEvent($event);
        $correlation = $handler->correlationId($event);
        if ($correlation === '' || strlen($correlation) > 191 || preg_match('/[\x00-\x1F\x7F]/D', $correlation) === 1) {
            throw new InvalidArgumentException('A process correlation identity is invalid.');
        }
        $current = $this->store->findByCorrelation(
            $handler->processType(),
            $event->siteIdentifier(),
            $correlation,
        );
        $now = $this->clock->now();
        if ($current === null) {
            $transition = $handler->start($event);
            $process = new ProcessInstance(
                Uuid::uuid7()->toString(),
                $handler->processType(),
                $correlation,
                $event->siteIdentifier(),
                $event->organizationId(),
                $event->actorId(),
                $event->systemIdentity(),
                1,
                $transition->status(),
                $transition->state(),
                $now,
                $now,
            );
            $this->store->create($process, $transition->work());
            return $process;
        }
        if ($current->status() !== ProcessStatus::RUNNING) {
            throw new InvalidArgumentException('A terminal process cannot consume another event.');
        }
        if ($current->organizationId() !== $event->organizationId()) {
            throw new InvalidArgumentException('A process cannot cross organization boundaries.');
        }
        $transition = $handler->apply($current, $event);
        $next = $current->transition($transition->state(), $transition->status(), $now);
        $this->store->save($next, $current->version(), $transition->work());
        return $next;
    }

    /**
     * Cancel a running process and atomically persist requested compensation work.
     *
     * @param   string                     $processId        Process UUID.
     * @param   int                        $expectedVersion  Optimistic version presented by the operator.
     * @param   string                     $operatorId       Freshly authorised operator identity.
     * @param   string                     $note             Cancellation rationale.
     * @param   iterable<ProcessWorkItem>  $compensations    Explicit best-effort compensation requests.
     *
     * @return  ProcessInstance  Cancelled persisted snapshot.
     *
     * @since   2.0.0
     */
    public function cancel(
        string $processId,
        int $expectedVersion,
        string $operatorId,
        string $note,
        iterable $compensations = [],
    ): ProcessInstance {
        $current = $this->store->load($processId)
            ?? throw new InvalidArgumentException('The process does not exist.');
        if ($current->version() !== $expectedVersion) {
            throw new InvalidArgumentException('The process version is stale.');
        }
        $items = [];
        foreach ($compensations as $work) {
            if ($work->kind() !== \Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkKind::COMPENSATION) {
                throw new InvalidArgumentException('Cancellation may enqueue only compensation work.');
            }
            $items[] = $work;
        }
        $cancelled = $current->cancel($operatorId, $note, $this->clock->now());
        $this->store->save($cancelled, $expectedVersion, $items);
        return $cancelled;
    }
}
