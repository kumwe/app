<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;

final readonly class AdministratorViewDefinition implements ContributionDefinition
{
    public function __construct(public string $name, public string $template)
    {
        AdministratorWorkspaceDefinition::assertIdentifier($name, 'view');
        if (
            preg_match('#^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.twig$#D', $template) !== 1
            || str_contains($template, '..')
        ) {
            throw new InvalidArgumentException('A contributed administrator view template path is unsafe.');
        }
    }

    public function identifier(): string
    {
        return $this->name;
    }

    /** @return array{name: string, template: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'template' => $this->template];
    }
}
