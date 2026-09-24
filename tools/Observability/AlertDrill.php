<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use Closure;

/**
 * One operator drill: how to induce the condition a critical alert watches for, and how to recover from it.
 *
 * `steps` drives the real application through a `DrillHost`, recording scrapes on a `DrillTimeline` and
 * declaring checkpoints (healthy, firing, cleared); `restore` puts the host back however `steps` ended, so one
 * failed drill cannot poison the next. `action` is the phrase the alert's runbook section must contain for the
 * recovery the drill performs, which is how the drill proves the page is actionable as written.
 *
 * @since  2.0.0
 */
final readonly class AlertDrill
{
    /**
     * Describe a drill.
     *
     * @param  string                                        $id        Drill identifier, named in the runbook section.
     * @param  string                                        $alert     Page-severity alert the drill proves.
     * @param  string                                        $induce    What the drill does to cause the condition.
     * @param  string                                        $recover   What the drill does to recover.
     * @param  string                                        $action    Phrase the runbook section must contain.
     * @param  Closure(DrillHost): list<array<string, string>>  $firing  Label sets of every instance that must fire.
     * @param  Closure(DrillHost, DrillTimeline): void          $steps   Induce, observe and recover.
     * @param  ?Closure(DrillHost): void                        $restore Return the host to its baseline.
     * @param  ?Closure(DrillHost): ?string                     $skip    Reason the drill cannot run here, or null.
     * @param  list<string>                                  $witnesses Further metrics the drill's own checks read.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $alert,
        public string $induce,
        public string $recover,
        public string $action,
        public Closure $firing,
        public Closure $steps,
        public ?Closure $restore = null,
        public ?Closure $skip = null,
        public array $witnesses = [],
    ) {
    }
}
