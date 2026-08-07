<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class AdministratorRouteDefinition implements ContributionDefinition
{
    /** @var non-empty-list<string> */
    public array $methods;

    public string $capability;

    /** @param array<mixed> $methods */
    public function __construct(
        public string $name,
        public string $path,
        array $methods,
        string $capability,
        public string $view,
    ) {
        AdministratorWorkspaceDefinition::assertIdentifier($name, 'route');
        AdministratorWorkspaceDefinition::assertIdentifier($view, 'view');
        if (preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/|$))*$#D', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('A contributed administrator route path is unsafe.');
        }
        if (!array_is_list($methods) || $methods === [] || count($methods) > 8) {
            throw new InvalidArgumentException('A contributed administrator route requires 1 to 8 methods.');
        }
        $normalized = [];
        foreach ($methods as $method) {
            if (!is_string($method) || preg_match('/^(?:DELETE|GET|PATCH|POST|PUT)$/D', $method) !== 1) {
                throw new InvalidArgumentException('A contributed administrator route method is unsupported.');
            }
            $normalized[$method] = true;
        }
        $values = array_keys($normalized);
        sort($values, SORT_STRING);
        if (
            array_intersect($values, ['DELETE', 'PATCH', 'POST', 'PUT']) !== []
            && array_intersect($values, ['GET']) !== []
        ) {
            throw new InvalidArgumentException('A contributed route cannot mix safe and mutating methods.');
        }
        /** @var non-empty-list<string> $values */
        $this->methods = $values;
        $this->capability = Capability::fromString($capability)->value();
    }

    public function identifier(): string
    {
        return $this->name;
    }

    /** @return array{name: string, path: string, methods: non-empty-list<string>, capability: string, view: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'methods' => $this->methods,
            'capability' => $this->capability,
            'view' => $this->view,
        ];
    }
}
