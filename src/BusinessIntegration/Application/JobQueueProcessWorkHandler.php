<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\JobQueue;
use Kumwe\CMS\BusinessIntegration\Domain\IntegrationContractValidator;
use Kumwe\CMS\BusinessIntegration\Domain\ProcessWorkKind;
use Psr\Clock\ClockInterface;

/**
 * Adapter that hands process commands and compensations to the existing durable job queue.
 *
 * @since  2.0.0
 */
final readonly class JobQueueProcessWorkHandler implements ProcessWorkHandler
{
    /**
     * Configure a queue destination for process effects.
     *
     * @param   JobQueue        $jobs   Existing durable job queue.
     * @param   ClockInterface  $clock  Immediate availability clock.
     * @param   string          $queue  Destination queue.
     *
     * @since   2.0.0
     */
    public function __construct(
        private JobQueue $jobs,
        private ClockInterface $clock,
        private string $queue = 'default',
    ) {
        IntegrationContractValidator::token($queue, 'Process job queue', 64);
    }

    /** @inheritDoc */
    public function supports(ProcessWorkKind $kind, string $name): bool
    {
        return in_array($kind, ProcessWorkKind::cases(), true);
    }

    /** @inheritDoc */
    public function handle(ProcessWorkLease $lease, ExecutionContext $context): void
    {
        $this->jobs->enqueue(
            $context,
            $lease->work->name(),
            [
                'process_id' => $lease->processId,
                'process_version' => $lease->processVersion,
                'work_id' => $lease->work->id(),
                'work_kind' => $lease->work->kind()->value,
                'payload' => $lease->work->payload(),
            ],
            $this->clock->now(),
            $this->queue,
            maximumAttempts: $lease->work->maximumAttempts(),
        );
    }
}
