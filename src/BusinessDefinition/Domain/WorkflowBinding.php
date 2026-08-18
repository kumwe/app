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
     * States whose entry closes the record: from then on its fields and owned lines refuse every mutation.
     *
     * Immutability is a property of the document, not of the state machine: a record in one of these
     * states still moves through declared transitions, but `BusinessRecordService` refuses to update,
     * archive, restore, relate, unrelate, reorder or rewrite it, on every surface, with the stable
     * `BusinessRecordImmutable` error. Correction happens by issuing a new record that carries a
     * `RelationshipKind::Reversal` link back to this one, never by editing it.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $immutableStates;

    /**
     * Declare a workflow, validating its states, its initial state, and every transition against them.
     *
     * Repeated states are collapsed rather than rejected, so the stored set is the distinct one; repeated
     * transition handles are a hard error, since a handle has to identify a single edge. Immutable states
     * must be declared states other than the initial one, because a record has to start life editable —
     * immutability is entered through a transition, which is what makes the freeze an audited, capability
     * gated act instead of a property a record could be born with.
     *
     * @param   string                                                                     $initialState     State
     *          a newly created record starts in; it must appear in `$states`.
     * @param   list<string>                                                               $states           Every
     *          state a record may occupy, at most 64 once deduplicated.
     * @param   list<array{handle: string, from: string, to: string, capability: string}>  $transitions      Edges
     *          of the machine, at most 128, each with a unique handle and a dotted guarding capability.
     * @param   list<string>                                                               $immutableStates  States
     *          whose entry makes the record immutable; each must be a declared, non-initial state.
     *
     * @throws  InvalidBusinessDefinition  When the state set is empty or exceeds 64 entries, the initial state
     *          is not among them, a state handle is malformed, more than 128 transitions are given, a
     *          transition has a malformed handle or capability, names an undeclared endpoint, loops a state
     *          onto itself, or repeats a handle already used, or an immutable state is undeclared or is the
     *          initial state.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $initialState,
        array $states,
        array $transitions,
        array $immutableStates = [],
    ) {
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
        $immutableStates = array_values(array_unique($immutableStates));
        foreach ($immutableStates as $state) {
            if (!in_array($state, $states, true) || $state === $initialState) {
                throw new InvalidBusinessDefinition(
                    'An immutable workflow state must be a declared, non-initial state.',
                );
            }
        }
        $this->states = $states;
        $this->transitions = $transitions;
        $this->immutableStates = $immutableStates;
    }

    /**
     * Answer whether a record occupying the given state is closed against every content mutation.
     *
     * A null state means the record predates the binding or the entity binds no workflow, and such a
     * record is never immutable — only an entered, declared state can close a document.
     *
     * @param   ?string  $state  Workflow state the record currently holds, or null when it holds none.
     *
     * @return  bool  True when the definition declares that state immutable.
     *
     * @since   2.0.0
     */
    public function immutableIn(?string $state): bool
    {
        return $state !== null && in_array($state, $this->immutableStates, true);
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
     *          not a string, the state, transition or immutable-state collection is not a JSON array, a state
     *          is not a string, a transition is a list or declares a property outside the four named ones, a
     *          transition property is absent or not a string, or the constructor rejects the resulting graph.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        if (
            array_diff(
                array_keys($document),
                ['initial_state', 'states', 'transitions', 'immutable_states'],
            ) !== []
        ) {
            throw new InvalidBusinessDefinition('A workflow binding contains an unknown property.');
        }
        $initial = $document['initial_state'] ?? null;
        $states = $document['states'] ?? null;
        $transitions = $document['transitions'] ?? [];
        $immutable = $document['immutable_states'] ?? [];
        if (
            !is_string($initial) || !is_array($states) || !array_is_list($states)
            || !is_array($transitions) || !array_is_list($transitions)
            || !is_array($immutable) || !array_is_list($immutable)
        ) {
            throw new InvalidBusinessDefinition('A workflow binding has an invalid shape.');
        }
        $mappedImmutable = [];
        foreach ($immutable as $state) {
            if (!is_string($state)) {
                throw new InvalidBusinessDefinition('Immutable workflow states must be strings.');
            }
            $mappedImmutable[] = $state;
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

        return new self($initial, $mappedStates, $mappedTransitions, $mappedImmutable);
    }

    /**
     * Export the binding as the document that becomes part of a published definition's canonical bytes.
     *
     * The compatibility analyzer compares two versions on exactly this document, so any difference in the
     * states or the transitions — including their order — registers as a workflow change. The immutable
     * state list is written only when one is declared, which keeps the canonical bytes of every binding
     * published before the declaration existed exactly as they were.
     *
     * @return  array<string, mixed>  The initial state, the deduplicated state list, and the transitions,
     *          under the canonical keys `initial_state`, `states` and `transitions`, plus
     *          `immutable_states` when the binding declares any.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $document = [
            'initial_state' => $this->initialState,
            'states' => $this->states,
            'transitions' => $this->transitions,
        ];
        if ($this->immutableStates !== []) {
            $document['immutable_states'] = $this->immutableStates;
        }

        return $document;
    }
}
