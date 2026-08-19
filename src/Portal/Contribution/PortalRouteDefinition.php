<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\ContributionDefinition;
use Kumwe\App\Identity\Domain\Capability;

/**
 * Validated portal route declaration with explicit capability and template ownership.
 *
 * @since  2.0.0
 */
final readonly class PortalRouteDefinition implements ContributionDefinition
{
    /**
     * Normalized, unique, byte-sorted HTTP methods.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    public array $methods;

    /**
     * Normalized required capability.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $capability;

    /**
     * Validate one explicit portal route.
     *
     * @param   string        $name        Owner-scoped dotted route name.
     * @param   string        $path        Safe absolute path, relative to an extension mount.
     * @param   array<mixed>  $methods     One through eight supported verbs.
     * @param   string        $capability  Required owned capability.
     * @param   string        $template    Required owned template identifier.
     *
     * @throws  InvalidArgumentException  When values are unsafe or safe and mutating verbs are mixed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $name,
        public string $path,
        array $methods,
        string $capability,
        public string $template,
    ) {
        PortalWorkspaceDefinition::assertIdentifier($name, 'route');
        PortalWorkspaceDefinition::assertIdentifier($template, 'template');
        if (preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/|$))*$#D', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('A contributed portal route path is unsafe.');
        }
        if (!array_is_list($methods) || $methods === [] || count($methods) > 8) {
            throw new InvalidArgumentException('A contributed portal route requires 1 to 8 methods.');
        }
        $unique = [];
        foreach ($methods as $method) {
            if (!is_string($method) || preg_match('/^(?:DELETE|GET|PATCH|POST|PUT)$/D', $method) !== 1) {
                throw new InvalidArgumentException('A contributed portal route method is unsupported.');
            }
            $unique[$method] = true;
        }
        $values = array_keys($unique);
        sort($values, SORT_STRING);
        if (in_array('GET', $values, true) && array_intersect($values, ['DELETE', 'PATCH', 'POST', 'PUT']) !== []) {
            throw new InvalidArgumentException('A contributed portal route cannot mix safe and mutating methods.');
        }
        /** @var non-empty-list<non-falsy-string> $values */
        $this->methods = $values;
        $this->capability = Capability::fromString($capability)->value();
    }

    /**
     * Return the route's claimed identifier.
     *
     * @return  string  Dotted route name.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->name;
    }

    /**
     * Export the normalized route declaration.
     *
     * @return  array{name: string, path: string, methods: non-empty-list<string>, capability: string, template: string}
     *          Stable declaration shape.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'methods' => $this->methods,
            'capability' => $this->capability,
            'template' => $this->template,
        ];
    }
}
