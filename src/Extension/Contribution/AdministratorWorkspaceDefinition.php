<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

final readonly class AdministratorWorkspaceDefinition
{
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public int $priority,
    ) {
        self::assertIdentifier($id, 'workspace');
        if (trim($label) === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('An administrator workspace label must contain 1 to 80 characters.');
        }
        if (trim($description) === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException(
                'An administrator workspace description must contain 1 to 255 characters.',
            );
        }
        if ($priority < 0 || $priority > 100_000) {
            throw new InvalidArgumentException('An administrator workspace priority must be between 0 and 100000.');
        }
    }

    public static function assertIdentifier(string $identifier, string $kind): void
    {
        if (preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*){1,7}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('A contributed administrator %s identifier is invalid.', $kind));
        }
    }

    /** @return array{id: string, label: string, description: string, priority: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'priority' => $this->priority,
        ];
    }
}
