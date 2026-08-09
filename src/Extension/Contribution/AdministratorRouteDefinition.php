<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Validated declaration of one administrator HTTP route a contributor publishes.
 *
 * Construction is the validation boundary for a route contribution: a declaration that survives it
 * has a well-formed dotted name and view reference, a path that cannot traverse, one to eight
 * supported verbs with duplicates collapsed, and a normalized capability. That leaves
 * `AdministratorRouteRegistry` with only ownership and collision decisions to make, and lets the
 * contribution registrar compare a provider's route against its manifest by comparing two arrays.
 *
 * @since  2.0.0
 */
final readonly class AdministratorRouteDefinition implements ContributionDefinition
{
    /**
     * Verbs the route answers, de-duplicated and sorted into byte order.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    public array $methods;

    /**
     * Capability a request must carry to reach the route, lowercased by `Capability`.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $capability;

    /**
     * Validate and normalize one administrator route declaration.
     *
     * A route may not mix safe and mutating verbs. The registry decides once per route whether to
     * place the administrator CSRF guard in front of it, so a route answering both GET and POST
     * would drag that guard onto the safe verb as well.
     *
     * @param   string        $name        Dotted route identifier; ownership is checked when it is registered.
     * @param   string        $path        Route path; for an extension it is appended to its own mount prefix.
     * @param   array<mixed>  $methods     Declared verbs; must be a list of 1 to 8 of DELETE, GET, PATCH, POST, PUT.
     * @param   string        $capability  Capability the route requires, normalized through `Capability`.
     * @param   string        $view        Dotted identifier of the contributed view the route's handler renders.
     *
     * @throws  InvalidArgumentException  When an identifier, the path, the verb list, or the capability is
     *          rejected, or when safe and mutating verbs appear together.
     *
     * @since   2.0.0
     */
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
        /** @var non-empty-list<non-falsy-string> $values */
        $this->methods = $values;
        $this->capability = Capability::fromString($capability)->value();
    }

    /**
     * Report the identifier the contribution registries key this route by.
     *
     * @return  string  The dotted route name exactly as declared.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->name;
    }

    /**
     * Export the declaration in the shape the manifest declaration is compared against.
     *
     * Both sides of that comparison are built from this method, and the verbs are normalized
     * first, so the check is insensitive to the order and repetition a declaration was written in.
     *
     * @return  array{name: string, path: string, methods: non-empty-list<string>, capability: string, view: string}
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
            'view' => $this->view,
        ];
    }
}
