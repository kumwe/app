<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use InvalidArgumentException;

final readonly class WorkflowStateDefinition
{
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

    /** @return array{key: string, name: string, initial: bool, public: bool} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'initial' => $this->initial, 'public' => $this->public];
    }
}
