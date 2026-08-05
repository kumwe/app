<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;

final readonly class WorkflowDefinition
{
    /** @var list<WorkflowStateDefinition> */
    private array $states;
    /** @var list<WorkflowTransitionDefinition> */
    private array $transitions;

    /**
     * @param list<WorkflowStateDefinition> $states
     * @param list<WorkflowTransitionDefinition> $transitions
     */
    public function __construct(
        public string $id,
        public SiteContext $site,
        public string $handle,
        public string $name,
        array $states,
        array $transitions,
        public int $version,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $publishedAt,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('A workflow ID must be a canonical UUID.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,99}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('A workflow handle must be a lowercase identifier.');
        }
        if (mb_strlen(trim($name)) < 1 || mb_strlen(trim($name)) > 255 || $version < 1) {
            throw new InvalidArgumentException('A workflow name and version must be valid.');
        }
        $keys = [];
        $publicStates = [];
        $initial = 0;
        foreach ($states as $state) {
            if (isset($keys[$state->key])) {
                throw new InvalidArgumentException('Workflow state keys must be unique.');
            }
            $keys[$state->key] = true;
            $publicStates[$state->key] = $state->public;
            $initial += $state->initial ? 1 : 0;
            if ($state->initial && $state->public) {
                throw new InvalidArgumentException('A workflow initial state cannot be public.');
            }
        }
        if ($states === [] || $initial !== 1) {
            throw new InvalidArgumentException('A workflow must contain exactly one initial state.');
        }
        $edges = [];
        foreach ($transitions as $transition) {
            if (!isset($keys[$transition->from], $keys[$transition->to])) {
                throw new InvalidArgumentException('A transition must reference states in the workflow.');
            }
            $edge = $transition->from . '>' . $transition->to;
            if (isset($edges[$edge])) {
                throw new InvalidArgumentException('Workflow transitions must be unique.');
            }
            if (
                !$publicStates[$transition->from]
                && $publicStates[$transition->to]
                && $transition->requiredCapability->value() !== 'content.publish'
            ) {
                throw new InvalidArgumentException('Entering a public workflow state requires content.publish.');
            }
            if (
                $publicStates[$transition->from]
                && !$publicStates[$transition->to]
                && !in_array(
                    $transition->requiredCapability->value(),
                    ['content.unpublish', 'content.archive'],
                    true,
                )
            ) {
                throw new InvalidArgumentException('Leaving a public workflow state requires content.unpublish.');
            }
            $edges[$edge] = true;
        }
        $this->states = $states;
        $this->transitions = $transitions;
    }

    /** @return list<WorkflowStateDefinition> */
    public function states(): array
    {
        return $this->states;
    }

    /** @return list<WorkflowTransitionDefinition> */
    public function transitions(): array
    {
        return $this->transitions;
    }

    public function initialState(): string
    {
        foreach ($this->states as $state) {
            if ($state->initial) {
                return $state->key;
            }
        }
        throw new \LogicException('The validated workflow has no initial state.');
    }

    public function transition(string $from, string $to): WorkflowTransitionDefinition
    {
        foreach ($this->transitions as $transition) {
            if ($transition->from === $from && $transition->to === $to) {
                return $transition;
            }
        }
        throw new InvalidWorkflowTransition($from, $to);
    }

    public function isPublic(string $stateKey): bool
    {
        foreach ($this->states as $state) {
            if ($state->key === $stateKey) {
                return $state->public;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'site' => $this->site->identifier(),
            'handle' => $this->handle,
            'name' => $this->name,
            'states' => array_map(
                static fn (WorkflowStateDefinition $state): array => $state->toArray(),
                $this->states,
            ),
            'transitions' => array_map(
                static fn (WorkflowTransitionDefinition $transition): array => $transition->toArray(),
                $this->transitions,
            ),
            'version' => $this->version,
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'published_at' => $this->publishedAt->format(DATE_ATOM),
        ];
    }
}
