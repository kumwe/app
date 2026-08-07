<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class AdministratorNavigationDefinition
{
    public string $capability;

    public function __construct(
        public string $id,
        public string $workspace,
        public string $label,
        public string $description,
        public string $path,
        public string $icon,
        string $capability,
        public int $priority,
        public string $keywords = '',
    ) {
        AdministratorWorkspaceDefinition::assertIdentifier($id, 'navigation');
        AdministratorWorkspaceDefinition::assertIdentifier($workspace, 'workspace');
        $this->capability = Capability::fromString($capability)->value();
        if (trim($label) === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('An administrator navigation label must contain 1 to 80 characters.');
        }
        if (trim($description) === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException(
                'An administrator navigation description must contain 1 to 255 characters.',
            );
        }
        if (preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/|$))*$#D', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('A contributed administrator navigation path is unsafe.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $icon) !== 1) {
            throw new InvalidArgumentException('A contributed administrator navigation icon is invalid.');
        }
        if ($priority < 0 || $priority > 100_000 || mb_strlen($keywords) > 500) {
            throw new InvalidArgumentException('Administrator navigation ordering or keywords are invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workspace' => $this->workspace,
            'label' => $this->label,
            'description' => $this->description,
            'path' => $this->path,
            'icon' => $this->icon,
            'capability' => $this->capability,
            'priority' => $this->priority,
            'keywords' => $this->keywords,
        ];
    }
}
