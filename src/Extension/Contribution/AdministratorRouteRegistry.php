<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionOwner;

use Kumwe\Extension\Spi\Contribution\AdministratorRouteDefinition;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\App\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Runtime\TrustEnforcingRequestHandler;
use Kumwe\Extension\Spi\Binding\Http\AdministratorRouteHandlerFactory;
use Mezzio\Application;

/**
 * Holds contributed administrator routes and mounts them on the Mezzio application.
 *
 * Route contribution is deliberately two-phase: a provider declares a route while its container is
 * still being wired, and `registerInto()` mounts the whole collection later, once the trust
 * boundary and the administrator renderer exist. Between those phases this registry is the only
 * place that enforces that a contributor points a route at a capability and a view it also owns,
 * and that no two contributions claim the same route name or the same verb-and-path pair.
 *
 * Only extension routes are mounted from here. Core administrator routes stay with the core
 * delivery composition, which owns their middleware pipeline.
 *
 * @since  2.0.0
 */
final class AdministratorRouteRegistry implements ContributionSurface
{
    /**
     * Accepted routes with the collaborators needed to mount them, keyed by registered route name.
     *
     * @var    array<string, array{
     *             owner: ContributionOwner,
     *             definition: AdministratorRouteDefinition,
     *             factory: AdministratorRouteHandlerFactory
     *         }>
     * @since  2.0.0
     */
    private array $routes = [];

    /**
     * Verb-and-path pairs already claimed, keyed `VERB:/mounted/path`, so collisions cost one lookup.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $paths = [];

    /**
     * Bind the registry to the sibling registries a route declaration is validated against.
     *
     * @param  CapabilityDefinitionRegistry  $capabilities  Where a route's required capability must already be owned.
     * @param  AdministratorViewRegistry     $views         Where a route's rendered view must already be owned.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly CapabilityDefinitionRegistry $capabilities,
        private readonly AdministratorViewRegistry $views,
    ) {
    }

    /**
     * Accept one contributed route after checking ownership and collisions.
     *
     * The route is only recorded here; nothing reaches the router until `registerInto()` runs.
     * Because ownership of the capability and the view is resolved now, a provider must contribute
     * both of those before the route that uses them.
     *
     * @param   ContributionOwner                 $owner       Contributor claiming the route.
     * @param   AdministratorRouteDefinition      $definition  Validated declaration to record.
     * @param   AdministratorRouteHandlerFactory  $factory     Builds the handler when the route is mounted.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the owner does not own the route name, its capability, or its
     *          view, or when the name or one of its verb-and-path pairs is already taken.
     *
     * @since   2.0.0
     */
    public function register(
        ContributionOwner $owner,
        AdministratorRouteDefinition $definition,
        AdministratorRouteHandlerFactory $factory,
    ): void {
        $owner->assertOwns($definition->name, 'route');
        if (!$this->capabilities->isOwnedBy($definition->capability, $owner)) {
            throw new InvalidArgumentException('An administrator route capability must be owned by its contributor.');
        }
        if (!$this->views->isOwnedBy($definition->view, $owner)) {
            throw new InvalidArgumentException('An administrator route view must be owned by its contributor.');
        }
        $name = self::routeName($owner, $definition);
        $path = self::routePath($owner, $definition);
        if (isset($this->routes[$name]) || $this->pathCollides($definition, $path)) {
            throw new InvalidArgumentException('A contributed administrator route collides with an existing route.');
        }
        $this->routes[$name] = [
            'owner' => $owner,
            'definition' => $definition,
            'factory' => $factory,
        ];
        foreach ($definition->methods as $method) {
            $this->paths[$method . ':' . $path] = true;
        }
    }

    /**
     * Mount every recorded route on the application, in registered-name order.
     *
     * Each handler is wrapped so a request fails closed once its extension is disabled or loses
     * trust, a route with mutating verbs gains the administrator CSRF guard, and every route
     * carries its declared capability as an authorization option. Sorting first makes the router
     * deterministic no matter what order extensions were installed in.
     *
     * @param   Application            $application  Mezzio application the routes are added to.
     * @param   TrustStore             $trust        Trust boundary consulted on every request to these routes.
     * @param   AdministratorRenderer  $renderer     Renderer handed to each route's handler factory.
     *
     * @return  void
     *
     * @throws  \LogicException  When a core-owned route reached this registry; the core delivery composition
     *          registers those itself.
     *
     * @since   2.0.0
     */
    public function registerInto(
        Application $application,
        TrustStore $trust,
        AdministratorRenderer $renderer,
    ): void {
        ksort($this->routes, SORT_STRING);
        foreach ($this->routes as $route) {
            $owner = $route['owner'];
            if ($owner->identifier() === ContributionOwner::CORE) {
                throw new \LogicException('Core routes remain registered by the core delivery composition.');
            }
            $definition = $route['definition'];
            $handler = new TrustEnforcingRequestHandler(
                $route['factory']->create($renderer->forExtensionRoute(
                    $owner->identifier(),
                    $definition->view,
                )),
                $trust,
                $owner->identifier(),
            );
            $middleware = array_intersect($definition->methods, ['DELETE', 'PATCH', 'POST', 'PUT']) === []
                ? $handler
                : [AdministratorCsrfMiddleware::class, $handler];
            $mezzioRoute = $application->route(
                self::routePath($owner, $definition),
                $middleware,
                $definition->methods,
                self::routeName($owner, $definition),
            );
            $mezzioRoute->setOptions([
                AdministratorAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => [$definition->capability],
            ]);
        }
    }

    /**
     * List this owner's routes for the contribution inventory.
     *
     * @param   ContributionOwner  $owner  Contributor whose routes are wanted.
     *
     * @return  list<array<string, mixed>>  Each declaration's array plus the `registered_name` and
     *          `registered_path` it is mounted under; empty when the owner contributed no routes.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array
    {
        $result = [];
        foreach ($this->routes as $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                $definition = $entry['definition'];
                $result[] = $definition->toArray() + [
                    'registered_name' => self::routeName($owner, $definition),
                    'registered_path' => self::routePath($owner, $definition),
                ];
            }
        }
        return $result;
    }

    /**
     * Withdraw every route this owner contributed, releasing the paths they reserved.
     *
     * Withdrawal frees the names and verb-and-path pairs for a later contributor. It does not
     * unmount routes already given to a running application: the trust wrapper installed by
     * `registerInto()` is what stops those from serving.
     *
     * @param   ContributionOwner  $owner  Contributor whose routes are withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->routes as $name => $entry) {
            if ($entry['owner']->identifier() !== $owner->identifier()) {
                continue;
            }
            $definition = $entry['definition'];
            foreach ($definition->methods as $method) {
                unset($this->paths[$method . ':' . self::routePath($owner, $definition)]);
            }
            unset($this->routes[$name]);
        }
    }

    /**
     * Report whether any verb of a declaration already has this path reserved.
     *
     * @param   AdministratorRouteDefinition  $definition  Declaration whose verbs are checked.
     * @param   string                        $path        Mounted path the declaration would occupy.
     *
     * @return  bool  True when at least one verb-and-path pair is already taken.
     *
     * @since   2.0.0
     */
    private function pathCollides(AdministratorRouteDefinition $definition, string $path): bool
    {
        foreach ($definition->methods as $method) {
            if (isset($this->paths[$method . ':' . $path])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Compose the router name a declaration is mounted under.
     *
     * The composed name, not the declared one, is what `$routes` is keyed by and what the
     * duplicate check in `register()` compares, which is why `ownedBy()` reports it separately.
     *
     * @param   ContributionOwner             $owner       Contributor the route belongs to.
     * @param   AdministratorRouteDefinition  $definition  Declaration being mounted.
     *
     * @return  non-empty-string  The declared name prefixed with `administrator.` for core,
     *          or with `administrator.extension.` for an extension.
     *
     * @since   2.0.0
     */
    private static function routeName(
        ContributionOwner $owner,
        AdministratorRouteDefinition $definition,
    ): string {
        return $owner->identifier() === ContributionOwner::CORE
            ? 'administrator.' . $definition->name
            : 'administrator.extension.' . $definition->name;
    }

    /**
     * Compose the absolute path a declaration is mounted at.
     *
     * A core route keeps the path it declared. An extension route is confined to a prefix derived
     * from its own identifier below `/administrator/extensions/`, so a contributed path can never
     * reach a core administrator URL however it was written. Public because it is the single
     * authority on where a declared route surfaces: the contribution summary an operator reads
     * composes its links with this exact rule rather than keeping a second copy of it.
     *
     * @param   ContributionOwner             $owner       Contributor the route belongs to.
     * @param   AdministratorRouteDefinition  $definition  Declaration being mounted.
     *
     * @return  non-empty-string  The mounted path; for an extension a declared `/` collapses to
     *          the bare prefix rather than leaving a trailing slash.
     *
     * @throws  \LogicException  When a core declaration carries an empty path.
     *
     * @since   2.0.0
     */
    public static function routePath(
        ContributionOwner $owner,
        AdministratorRouteDefinition $definition,
    ): string {
        if ($owner->identifier() === ContributionOwner::CORE) {
            $path = $definition->path;
            if ($path === '') {
                throw new \LogicException('A core administrator route path cannot be empty.');
            }
            return $path;
        }
        $suffix = $definition->path === '/' ? '' : $definition->path;
        return '/administrator/extensions/' . $owner->identifier() . $suffix;
    }
}
