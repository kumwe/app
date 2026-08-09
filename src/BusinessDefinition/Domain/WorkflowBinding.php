<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * The state machine a business entity's records move through, declared as part of its definition.
 *
 * A binding is optional: an entity that declares one gains a `workflow_state` control column, records
 * start life in `$initialState`, and `BusinessRecordService` moves them only by running an action whose
 * transition both matches the record's current state and whose capability the actor holds. Because
 * transition handles are unique across the binding, one handle describes exactly one edge — the same
 * logical move from two different states needs two handles. This constructor is the only validation
 * point, so a binding that exists already has a bounded, closed graph: every edge names declared states,
 * no edge is a self-loop, and the initial state is one of the declared states. What it deliberately does
 * not check is reachability, which is a modelling choice rather than an integrity one.
 *
 * @since  2.0.0
 */
final readonly class WorkflowBinding
{
    /**
     * Every state a record of this entity may occupy, deduplicated and in declaration order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $states;

    /**
     * The permitted edges, each naming its handle, endpoints, and the capability that may run it.
     *
     * @var    list<array{handle: string, from: string, to: string, capability: string}>
     * @since  2.0.0
     */
    public array $transitions;

    /**
     * Declare a workflow, validating its states, its initial state, and every transition against them.
     *
     * Repeated states are collapsed rather than rejected, so the stored set is the distinct one; repeated
     * transition handles are a hard error, since a handle has to identify a single edge.
     *
     * @param   string                                                                     $initialState  State
     *          a newly created record starts in; it must appear in `$states`.
     * @param   list<string>                                                               $states        Every
     *          state a record may occupy, at most 64 once deduplicated.
     * @param   list<array{handle: string, from: string, to: string, capability: string}>  $transitions   Edges
     *          of the machine, at most 128, each with a unique handle and a dotted guarding capability.
     *
     * @throws  InvalidBusinessDefinition  When the state set is empty or exceeds 64 entries, the initial state
     *          is not among them, a state handle is malformed, more than 128 transitions are given, or a
     *          transition has a malformed handle or capability, names an undeclared endpoint, loops a state
     *          onto itself, or repeats a handle already used.
     *
     * @since   2.0.0
     */
    public function __construct(public string $initialState, array $states, array $transitions)
    {
        $states = array_values(array_unique($states));
        if ($states === [] || count($states) > 64 || !in_array($initialState, $states, true)) {
            throw new InvalidBusinessDefinition(
                'A workflow binding requires a bounded state set and valid initial state.',
            );
        }
        foreach ($states as $state) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $state) !== 1) {
                throw new InvalidBusinessDefinition('A workflow state handle is invalid.');
            }
        }
        if (count($transitions) > 128) {
            throw new InvalidBusinessDefinition('A workflow binding has too many transitions.');
        }
        $seen = [];
        foreach ($transitions as $transition) {
            foreach (['handle', 'from', 'to', 'capability'] as $key) {
                if (!is_string($transition[$key] ?? null)) {
                    throw new InvalidBusinessDefinition('A workflow transition property is invalid.');
                }
            }
            if (
                preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $transition['handle']) !== 1
                || !in_array($transition['from'], $states, true)
                || !in_array($transition['to'], $states, true)
                || $transition['from'] === $transition['to']
                || preg_match('/^[a-z][a-z0-9-]*(?:[._:][a-z0-9-]+)*$/D', $transition['capability']) !== 1
                || isset($seen[$transition['handle']])
            ) {
                throw new InvalidBusinessDefinition('A workflow transition is invalid or duplicated.');
            }
            $seen[$transition['handle']] = true;
        }
        $this->states = $states;
        $this->transitions = $transitions;
    }

    /**
     * Rebuild a binding from its canonical document, rejecting any property the contract does not name.
     *
     * Each transition is rebuilt key by key into a strict four-property object before the constructor sees
     * it, so a document may not smuggle an extra property through, and may not supply a transition as a
     * JSON array. This method settles shape only; the constructor settles the graph.
     *
     * @param   array<string, mixed>  $document  Decoded workflow document keyed by canonical name.
     *
     * @return  self  The validated binding, having passed the same invariants as direct construction.
     *
     * @throws  InvalidBusinessDefinition  When the document carries an unknown property, the initial state is
     *          not a string, the state or transition collection is not a JSON array, a state is not a string,
     *          a transition is a list or declares a property outside the four named ones, a transition
     *          property is absent or not a string, or the constructor rejects the resulting graph.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['initial_state', 'states', 'transitions']) !== []) {
            throw new InvalidBusinessDefinition('A workflow binding contains an unknown property.');
        }
        $initial = $document['initial_state'] ?? null;
        $states = $document['states'] ?? null;
        $transitions = $document['transitions'] ?? [];
        if (
            !is_string($initial) || !is_array($states) || !array_is_list($states)
            || !is_array($transitions) || !array_is_list($transitions)
        ) {
            throw new InvalidBusinessDefinition('A workflow binding has an invalid shape.');
        }
        $mappedStates = [];
        foreach ($states as $state) {
            if (!is_string($state)) {
                throw new InvalidBusinessDefinition('Workflow states must be strings.');
            }
            $mappedStates[] = $state;
        }
        $mappedTransitions = [];
        foreach ($transitions as $transition) {
            if (
                !is_array($transition) || array_is_list($transition)
                || array_diff(array_keys($transition), ['handle', 'from', 'to', 'capability']) !== []
            ) {
                throw new InvalidBusinessDefinition('A workflow transition must be a strict object.');
            }
            $mapped = [];
            foreach (['handle', 'from', 'to', 'capability'] as $key) {
                $value = $transition[$key] ?? null;
                if (!is_string($value)) {
                    throw new InvalidBusinessDefinition('A workflow transition property must be a string.');
                }
                $mapped[$key] = $value;
            }
            /** @var array{handle: string, from: string, to: string, capability: string} $mapped */
            $mappedTransitions[] = $mapped;
        }

        return new self($initial, $mappedStates, $mappedTransitions);
    }

    /**
     * Export the binding as the document that becomes part of a published definition's canonical bytes.
     *
     * The compatibility analyzer compares two versions on exactly this document, so any difference in the
     * states or the transitions — including their order — registers as a workflow change.
     *
     * @return  array<string, mixed>  The initial state, the deduplicated state list, and the transitions,
     *          under the canonical keys `initial_state`, `states` and `transitions`.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'initial_state' => $this->initialState,
            'states' => $this->states,
            'transitions' => $this->transitions,
        ];
    }
}
