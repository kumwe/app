<?php

declare(strict_types=1);

namespace Kumwe\App\Workflow\Application;

use Kumwe\App\Content\Domain\ContentStatus;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Identity\Domain\Capability;

/**
 * Maps a built-in content status transition onto the one capability that authorizes it.
 *
 * The built-in draft/review/published/archived workflow has no persisted `WorkflowDefinition` to read
 * a `requiredCapability` from, so that mapping is product policy and lives here: each edge names the
 * narrowest capability that permits it, and any pair without a dedicated rule costs `content.update`.
 * Content running on a custom workflow takes its capability from the matching
 * `WorkflowTransitionDefinition` instead. This class answers only *who may*; whether the edge exists
 * at all is `Workflow`'s decision.
 *
 * @since  2.0.0
 */
final readonly class ContentTransitionAuthorizer
{
    /**
     * Resolves the capability a principal must hold to move content between two statuses.
     *
     * Two of the rules ignore the source state entirely: entering `published` always costs
     * `content.publish` and entering `archived` always costs `content.archive`, whichever status the
     * content leaves. The narrower rules that name both ends are still reached because every one of
     * them targets `draft` or `review`, which neither broad rule matches.
     *
     * @param   ContentStatus  $from  Status the content currently holds.
     * @param   ContentStatus  $to    Status the transition would move it to.
     *
     * @return  Capability  Narrowest capability that permits this edge; `content.update` when no rule matches.
     *
     * @since   2.0.0
     */
    public function requiredCapability(ContentStatus $from, ContentStatus $to): Capability
    {
        $capability = match (true) {
            $from === ContentStatus::Draft && $to === ContentStatus::Review => 'content.submit',
            $from === ContentStatus::Review && $to === ContentStatus::Draft => 'content.review',
            $to === ContentStatus::Published => 'content.publish',
            $from === ContentStatus::Published && $to === ContentStatus::Draft => 'content.unpublish',
            $to === ContentStatus::Archived => 'content.archive',
            $from === ContentStatus::Archived && $to === ContentStatus::Draft => 'content.restore',
            default => 'content.update',
        };

        return Capability::fromString($capability);
    }

    /**
     * Rejects a principal that does not hold the capability the transition requires.
     *
     * This is a capability check only. It says nothing about whether the workflow declares the edge,
     * which `Workflow::assertCanTransition()` decides, so a use case that commits a transition runs
     * both guards.
     *
     * @param   AuthenticatedPrincipal  $principal  Actor whose granted capabilities are inspected.
     * @param   ContentStatus           $from       Status the content currently holds.
     * @param   ContentStatus           $to         Status the transition would move it to.
     *
     * @return  void
     *
     * @throws  InsufficientCapability  When the principal lacks the capability this transition requires.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        AuthenticatedPrincipal $principal,
        ContentStatus $from,
        ContentStatus $to,
    ): void {
        $required = $this->requiredCapability($from, $to);

        if (!$principal->hasCapability($required)) {
            throw new InsufficientCapability($required->value());
        }
    }
}
