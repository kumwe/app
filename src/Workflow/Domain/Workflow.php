<?php

declare(strict_types=1);

namespace Kumwe\App\Workflow\Domain;

use Kumwe\App\Content\Domain\ContentStatus;

/**
 * Decides which status changes content is allowed to make.
 *
 * A `Workflow` is the routing half of the editorial lifecycle: it answers whether an edge exists,
 * never who may travel it. `ContentEntry::transition()` consults it before every status change, so an
 * edge this object does not declare cannot be committed. It is built either from the closed built-in
 * state machine — the default the container shares — or from a site's persisted `WorkflowDefinition`,
 * and answers the same questions in both cases, which is what lets the content service treat a custom
 * workflow and the built-in one identically.
 *
 * @since  2.0.0
 */
final class Workflow
{
    /**
     * Edges of the built-in editorial lifecycle, used when no persisted definition applies.
     *
     * This is deliberately closed: changes are product changes that require a new tested workflow
     * version, rather than mutable request configuration.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const ALLOWED_TRANSITIONS = [
        'draft' => [
            'review',
            'archived',
        ],
        'review' => [
            'draft',
            'published',
            'archived',
        ],
        'published' => [
            'draft',
            'archived',
        ],
        'archived' => [
            'draft',
        ],
    ];

    /**
     * Permitted target state keys of the workflow in force, keyed by source state key.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private array $transitions;

    /**
     * Build the workflow from a site's published definition, or from the built-in lifecycle.
     *
     * Every state a definition declares is registered before any edge is read, so a state with no
     * outgoing edge is carried with an empty target list rather than dropped from the map.
     *
     * @param  ?WorkflowDefinition  $definition  Published workflow to follow, or null for the built-in one.
     *
     * @since  2.0.0
     */
    public function __construct(?WorkflowDefinition $definition = null)
    {
        if ($definition === null) {
            $this->transitions = self::ALLOWED_TRANSITIONS;

            return;
        }

        $this->transitions = [];
        foreach ($definition->states() as $state) {
            $this->transitions[$state->key] = [];
        }
        foreach ($definition->transitions() as $transition) {
            $this->transitions[$transition->from][] = $transition->to;
        }
    }

    /**
     * Reports whether this workflow declares an edge between two states.
     *
     * A source state the workflow does not know answers false rather than raising, so content left on
     * a state that a newer workflow version dropped simply has no permitted moves.
     *
     * @param   ContentStatus|string  $from  State the content is leaving, as an enum case or state key.
     * @param   ContentStatus|string  $to    State the transition would move it to.
     *
     * @return  bool  True only when this exact edge is declared; the check is directional.
     *
     * @since   2.0.0
     */
    public function allows(ContentStatus|string $from, ContentStatus|string $to): bool
    {
        $from = $from instanceof ContentStatus ? $from->value : $from;
        $to = $to instanceof ContentStatus ? $to->value : $to;
        return in_array($to, $this->transitions[$from] ?? [], true);
    }

    /**
     * Refuses a status change the workflow does not declare.
     *
     * This is the guard `ContentEntry::transition()` runs, which is why the failure is an exception
     * rather than a return value: it cannot be committed past by accident.
     *
     * @param   ContentStatus|string  $from  State the content is leaving, as an enum case or state key.
     * @param   ContentStatus|string  $to    State the transition would move it to.
     *
     * @return  void
     *
     * @throws  InvalidWorkflowTransition  When the workflow declares no edge between the two states.
     *
     * @since   2.0.0
     */
    public function assertCanTransition(ContentStatus|string $from, ContentStatus|string $to): void
    {
        if (!$this->allows($from, $to)) {
            throw new InvalidWorkflowTransition($from, $to);
        }
    }

    /**
     * Lists the statuses content may move to from where it stands, for a status picker or API affordance.
     *
     * State keys are projected back onto `ContentStatus`, so this reads the built-in lifecycle. A
     * custom workflow whose states are not content statuses is enumerated through
     * `WorkflowDefinition::transitions()` instead.
     *
     * @param   ContentStatus  $from  Status the content currently holds.
     *
     * @return  list<ContentStatus>  Permitted targets in declaration order; empty for a terminal status.
     *
     * @throws  \ValueError  When a state key of the workflow in force is not a `ContentStatus` case.
     *
     * @since   2.0.0
     */
    public function allowedTargets(ContentStatus $from): array
    {
        return array_map(
            static fn (string $status): ContentStatus => ContentStatus::from($status),
            $this->transitions[$from->value] ?? [],
        );
    }
}
