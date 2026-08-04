<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class PresentationContext
{
    /** @var list<string> */
    private array $roles;

    /** @param array<mixed> $roles */
    public function __construct(
        private string $route,
        private ?string $menuId,
        private string $locale,
        array $roles = [],
    ) {
        if ($route === '' || $locale === '') {
            throw new InvalidArgumentException('Presentation route and locale are required.');
        }

        if (!array_is_list($roles)) {
            throw new InvalidArgumentException('Presentation roles must be a list.');
        }

        foreach ($roles as $role) {
            if (!is_string($role) || $role === '') {
                throw new InvalidArgumentException('Presentation roles must be non-empty strings.');
            }
        }

        /** @var list<string> $roles */
        $this->roles = $roles;
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
