<?php

declare(strict_types=1);

namespace Kumwe\App\Workflow\Domain;

use InvalidArgumentException;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * One declared edge of a custom workflow: the state it leaves, the state it enters, and its price.
 *
 * Binding a `Capability` to the edge itself is what lets a site design its own editorial process
 * without designing an authorization model to go with it — the content service resolves the edge for
 * a requested status change and authorizes the actor against `requiredCapability`. The capability is
 * carried here rather than derived from the states, which is why `WorkflowDefinition` additionally
 * refuses an edge whose capability would let an actor cross the public boundary on the cheap.
 *
 * @since  2.0.0
 */
final readonly class WorkflowTransitionDefinition
{
    /**
     * Build a validated edge between two distinct states.
     *
     * Only the edge's own shape is checked here. Whether both keys name states the workflow actually
     * declares, and whether the capability is adequate for the boundary being crossed, are decided by
     * `WorkflowDefinition` once the whole set is known.
     *
     * @param   string      $from                Key of the state this edge leaves.
     * @param   string      $to                  Key of the state this edge enters.
     * @param   Capability  $requiredCapability  Capability an actor must hold to travel this edge.
     *
     * @throws  InvalidArgumentException  When either key is not a lowercase identifier, or both are the same.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $from,
        public string $to,
        public Capability $requiredCapability,
    ) {
        foreach ([$from, $to] as $key) {
            if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $key) !== 1) {
                throw new InvalidArgumentException('A workflow transition must reference valid state keys.');
            }
        }
        if ($from === $to) {
            throw new InvalidArgumentException('A workflow transition cannot target its source state.');
        }
    }

    /**
     * Exports the edge in the shape the workflow definition serializes and the API returns.
     *
     * @return  array{from: string, to: string, required_capability: string}  Capability flattened to its string.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'required_capability' => $this->requiredCapability->value()];
    }
}
