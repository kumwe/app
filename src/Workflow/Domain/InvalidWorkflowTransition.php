<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use DomainException;

/**
 * Raised when a workflow is asked to move content along an edge it does not declare.
 *
 * Both `Workflow::assertCanTransition()` and `WorkflowDefinition::transition()` throw this instead of
 * returning a falsy answer, so a caller that commits a status change cannot skip the check by
 * ignoring a return value. Ask `Workflow::allows()` when a yes/no answer is what is wanted. The
 * message names the two states and nothing else, which makes it safe to surface to an operator.
 *
 * @since  2.0.0
 */
final class InvalidWorkflowTransition extends DomainException
{
    /**
     * Build the failure from the two states the rejected transition named.
     *
     * Either state may arrive as a raw workflow state key or as the enum case that backs it — a
     * `ContentStatus` on the built-in workflow — and both are reduced to their string key so the
     * message reads the same whichever workflow raised it.
     *
     * @param  string|\BackedEnum  $from  State the content is leaving, as a state key or its enum case.
     * @param  string|\BackedEnum  $to    State the transition targeted, as a state key or its enum case.
     *
     * @since  2.0.0
     */
    public function __construct(string|\BackedEnum $from, string|\BackedEnum $to)
    {
        $from = $from instanceof \BackedEnum ? (string) $from->value : $from;
        $to = $to instanceof \BackedEnum ? (string) $to->value : $to;
        parent::__construct(sprintf(
            'The workflow does not allow a transition from %s to %s.',
            $from,
            $to,
        ));
    }
}
