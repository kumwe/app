<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class PresentationContext
{
    /** @param list<string> $roles */
    public function __construct(
        private string $route,
        private ?string $menuId,
        private string $locale,
        private array $roles = [],
    ) {
        if ($route === '' || $locale === '') {
            throw new InvalidArgumentException('Presentation route and locale are required.');
        }

        if (!array_is_list($roles)) {
            throw new InvalidArgumentException('Presentation roles must be an ordered list.');
        }

        foreach ($roles as $role) {
            if (!is_string($role) || $role === '') {
                throw new InvalidArgumentException('Presentation roles must be non-empty strings.');
            }
        }
    }

    public function route(): string
    {
        return $this->route;
    }

    public function menuId(): ?string
    {
        return $this->menuId;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
