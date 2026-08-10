<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Domain;

use InvalidArgumentException;

/**
 * Pure process decision: replacement state, lifecycle and durable requested effects.
 *
 * @since  2.0.0
 */
final readonly class ProcessTransition
{
    /**
     * Next process-manager state produced by the transition.
     *
     * @var    array<string, mixed>  Replacement process state.
     * @since  2.0.0
     */
    private array $state;

    /**
     * Durable work emitted atomically with the state transition.
     *
     * @var    list<ProcessWorkItem>  Requested timers, commands and compensations.
     * @since  2.0.0
     */
    private array $work;

    /**
     * Capture one deterministic process decision.
     *
     * @param   array<string, mixed>       $state   Replacement state object.
     * @param   ProcessStatus              $status  Resulting lifecycle.
     * @param   iterable<ProcessWorkItem>  $work    Durable effects requested with the transition.
     *
     * @throws  InvalidArgumentException  When work identifiers repeat.
     *
     * @since   2.0.0
     */
    public function __construct(array $state, private ProcessStatus $status, iterable $work = [])
    {
        IntegrationContractValidator::object($state, 'Process transition state', EventEnvelope::MAX_PAYLOAD_BYTES);
        $items = [];
        foreach ($work as $item) {
            if (isset($items[$item->id()])) {
                throw new InvalidArgumentException('A process transition repeats a work identity.');
            }
            $items[$item->id()] = $item;
        }
        $this->state = $state;
        $this->work = array_values($items);
    }

    /**
     * Return the state carried by this process transition.
     *
     * @return  array<string, mixed>  Replacement process state.
     *
     * @since   2.0.0
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * Return the status carried by this process transition.
     *
     * @return  ProcessStatus  Resulting lifecycle state.
     *
     * @since   2.0.0
     */
    public function status(): ProcessStatus
    {
        return $this->status;
    }

    /**
     * Return operator-visible work for the requested process.
     *
     * @return  list<ProcessWorkItem>  Durable requested effects.
     *
     * @since   2.0.0
     */
    public function work(): array
    {
        return $this->work;
    }
}
