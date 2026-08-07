<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class WorkflowBinding
{
    /** @var list<string> */
    public array $states;

    /** @var list<array{handle: string, from: string, to: string, capability: string}> */
    public array $transitions;

    /**
     * @param list<string> $states
     * @param list<array{handle: string, from: string, to: string, capability: string}> $transitions
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
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $transition['handle']) !== 1
                || !in_array($transition['from'], $states, true)
                || !in_array($transition['to'], $states, true)
                || $transition['from'] === $transition['to']
                || preg_match('/^[a-z][a-z0-9-]*(?:[._:][a-z0-9-]+)*$/D', $transition['capability']) !== 1
                || isset($seen[$transition['handle']])) {
                throw new InvalidBusinessDefinition('A workflow transition is invalid or duplicated.');
            }
            $seen[$transition['handle']] = true;
        }
        $this->states = $states;
        $this->transitions = $transitions;
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        if (array_diff(array_keys($document), ['initial_state', 'states', 'transitions']) !== []) {
            throw new InvalidBusinessDefinition('A workflow binding contains an unknown property.');
        }
        $initial = $document['initial_state'] ?? null;
        $states = $document['states'] ?? null;
        $transitions = $document['transitions'] ?? [];
        if (!is_string($initial) || !is_array($states) || !array_is_list($states)
            || !is_array($transitions) || !array_is_list($transitions)) {
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
            if (!is_array($transition) || array_is_list($transition)
                || array_diff(array_keys($transition), ['handle', 'from', 'to', 'capability']) !== []) {
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'initial_state' => $this->initialState,
            'states' => $this->states,
            'transitions' => $this->transitions,
        ];
    }
}
