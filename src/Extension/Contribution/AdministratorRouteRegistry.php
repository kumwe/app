<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorAuthorizationMiddleware;
use Kumwe\CMS\Administrator\Http\Middleware\AdministratorCsrfMiddleware;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Runtime\TrustEnforcingRequestHandler;
use Mezzio\Application;

final class AdministratorRouteRegistry
{
    /**
     * @var array<string, array{
     *     owner: ContributionOwner,
     *     definition: AdministratorRouteDefinition,
     *     factory: AdministratorRouteHandlerFactory
     * }>
     */
    private array $routes = [];

    /** @var array<string, true> */
    private array $paths = [];

    public function __construct(
        private readonly CapabilityDefinitionRegistry $capabilities,
        private readonly AdministratorViewRegistry $views,
    ) {
    }

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
                $route['factory']->create($renderer),
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

    /** @return list<array<string, mixed>> */
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

    private function pathCollides(AdministratorRouteDefinition $definition, string $path): bool
    {
        foreach ($definition->methods as $method) {
            if (isset($this->paths[$method . ':' . $path])) {
                return true;
            }
        }
        return false;
    }

    private static function routeName(
        ContributionOwner $owner,
        AdministratorRouteDefinition $definition,
    ): string {
        return $owner->identifier() === ContributionOwner::CORE
            ? 'administrator.' . $definition->name
            : 'administrator.extension.' . $definition->name;
    }

    private static function routePath(
        ContributionOwner $owner,
        AdministratorRouteDefinition $definition,
    ): string {
        if ($owner->identifier() === ContributionOwner::CORE) {
            return $definition->path;
        }
        $suffix = $definition->path === '/' ? '' : $definition->path;
        return '/administrator/extensions/' . $owner->identifier() . $suffix;
    }
}
