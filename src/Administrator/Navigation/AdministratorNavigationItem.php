<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Navigation;

use InvalidArgumentException;

final readonly class AdministratorNavigationItem
{
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $href,
        public string $icon,
        public string $group,
        public string $capability,
        public int $priority,
        public string $keywords = '',
    ) {
        if (preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('An administrator navigation ID is invalid.');
        }
        if (!str_starts_with($href, '/administrator')) {
            throw new InvalidArgumentException('Administrator navigation must use an administrator-local URL.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'href' => $this->href,
            'icon' => $this->icon,
            'group' => $this->group,
            'capability' => $this->capability,
            'priority' => $this->priority,
            'keywords' => $this->keywords,
        ];
    }
}
