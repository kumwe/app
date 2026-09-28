<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Retention;

/**
 * The outcome of one retention readiness assessment: a state and the reasons that produced it.
 *
 * @since  2.0.0
 */
final readonly class RetentionVerdict
{
    /**
     * Record the verdict.
     *
     * @param  RetentionReadinessState  $state    Worst state any store reached.
     * @param  list<string>             $reasons  One line per finding, each naming its store; empty when ready.
     *
     * @since  2.0.0
     */
    public function __construct(public RetentionReadinessState $state, public array $reasons)
    {
    }

    /**
     * Whether the process may take traffic on retention grounds.
     *
     * @return  bool  False only for the failed state.
     *
     * @since   2.0.0
     */
    public function ready(): bool
    {
        return $this->state !== RetentionReadinessState::Failed;
    }
}
