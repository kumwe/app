<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class WorkflowTransitionDefinition
{
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

    /** @return array{from: string, to: string, required_capability: string} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'required_capability' => $this->requiredCapability->value()];
    }
}
