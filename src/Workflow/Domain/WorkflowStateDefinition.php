<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use InvalidArgumentException;

/**
 * One named state that content can rest in under a custom workflow.
 *
 * A state carries only what routing and visibility need: the key content records store, the label an
 * editor sees, and the two flags that give the state its role — `initial` marks where new content
 * enters, `public` marks a state whose content is reachable by anonymous visitors. Rules that
 * span more than one state, such as uniqueness of keys and the requirement that exactly one
 * non-public state be initial, belong to `WorkflowDefinition`; this class validates its own fields
 * only, so an invalid key or label never reaches the definition's cross-checks.
 *
 * @since  2.0.0
 */
final readonly class WorkflowStateDefinition
{
    /**
     * Build a validated state, rejecting a key or label the workflow cannot route on.
     *
     * @param   string  $key      Lowercase identifier content records store, at most 40 characters.
     * @param   string  $name     Human-readable label shown to editors, 1 to 255 characters.
     * @param   bool    $initial  Whether new content enters the workflow on this state.
     * @param   bool    $public   Whether content resting here is visible to anonymous visitors.
     *
     * @throws  InvalidArgumentException  When the key is not a lowercase identifier or the name is out of range.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $key,
        public string $name,
        public bool $initial = false,
        public bool $public = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $key) !== 1) {
            throw new InvalidArgumentException('A workflow state key must be a lowercase identifier.');
        }
        if (mb_strlen(trim($name)) < 1 || mb_strlen(trim($name)) > 255) {
            throw new InvalidArgumentException('A workflow state name must contain between 1 and 255 characters.');
        }
    }

    /**
     * Exports the state in the shape the workflow definition serializes and the API returns.
     *
     * @return  array{key: string, name: string, initial: bool, public: bool}  Keys match the constructor
     *          arguments, so the array feeds straight back into a new state.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'initial' => $this->initial, 'public' => $this->public];
    }
}
