<?php

declare(strict_types=1);

namespace Kumwe\CMS\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Contribution\CapabilityDefinitionRegistry;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ContributionSurface;
use Kumwe\CMS\Extension\Runtime\TrustEnforcingRequestHandler;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Portal\Http\Handler\PortalExtensionRootRedirectHandler;
use Kumwe\CMS\Portal\Http\Middleware\PortalAuthorizationMiddleware;
use Kumwe\CMS\Portal\Http\Middleware\PortalCsrfMiddleware;
use Kumwe\CMS\Portal\Presentation\PortalContributionRenderer;
use Kumwe\CMS\Portal\Presentation\PortalRenderer;
use LogicException;
use Mezzio\Application;

/**
 * Collision-safe owner-aware registry that mounts explicit portal extension routes.
 *
 * @since  2.0.0
 */
final class PortalRouteRegistry implements ContributionSurface
{
    /**
     * Recorded routes keyed by mounted route name.
     *
     * @var    array<string, array{owner: ContributionOwner, definition: PortalRouteDefinition,
     *         factory: PortalRouteHandlerFactory}>
     * @since  2.0.0
     */
    private array $routes = [];

    /**
     * Claimed verb and mounted-path pairs.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private array $paths = [];

    /**
     * Bind routes to their capability and template ownership registries.
     *
     * @param  CapabilityDefinitionRegistry  $capabilities   Owned capability authority.
     * @param  PortalTemplateRegistry        $templates      Owned portal template authority.
     * @param  AuthorizationPolicyRegistry   $authorization  Canonical action-to-resource policy authority.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly CapabilityDefinitionRegistry $capabilities,
        private readonly PortalTemplateRegistry $templates,
        private readonly AuthorizationPolicyRegistry $authorization,
    ) {
    }

    /**
     * Register a route whose name, capability, and template all share one owner.
     *
     * @param   ContributionOwner          $owner       Claiming contributor.
     * @param   PortalRouteDefinition      $definition  Validated route declaration.
     * @param   PortalRouteHandlerFactory  $factory     Handler factory.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership or collision checks fail.
     *
     * @since   2.0.0
     */
    public function register(
        ContributionOwner $owner,
        PortalRouteDefinition $definition,
        PortalRouteHandlerFactory $factory,
    ): void {
        $owner->assertOwns($definition->name, 'route');
        if (!$this->capabilities->isOwnedBy($definition->capability, $owner)) {
            throw new InvalidArgumentException('A portal route capability must be owned by its contributor.');
        }
        if (!$this->templates->isOwnedBy($definition->template, $owner)) {
            throw new InvalidArgumentException('A portal route template must be owned by its contributor.');
        }
        if (
            !$this->authorization->supports(
                Capability::fromString($definition->capability),
                AuthorizationResource::collection('portal_session'),
            )
        ) {
            throw new InvalidArgumentException(
                'A portal route capability must declare an enforceable whole-family portal-session policy.',
            );
        }
        $name = self::routeName($owner, $definition);
        $path = self::routePath($owner, $definition);
        if (isset($this->routes[$name])) {
            throw new InvalidArgumentException('A contributed portal route name is already owned.');
        }
        foreach ($definition->methods as $method) {
            if (isset($this->paths[$method . ':' . $path])) {
                throw new InvalidArgumentException('A contributed portal route path collides.');
            }
        }
        $this->routes[$name] = ['owner' => $owner, 'definition' => $definition, 'factory' => $factory];
        foreach ($definition->methods as $method) {
            $this->paths[$method . ':' . $path] = true;
        }
    }

    /**
     * Mount extension routes with live trust, portal CSRF, and fail-closed capability declarations.
     *
     * @param   Application     $application  Mezzio application.
     * @param   TrustStore      $trust        Live extension trust authority.
     * @param   PortalRenderer  $renderer     Shared portal renderer.
     *
     * @return  void
     *
     * @throws  LogicException  When a core route reaches the extension mounting phase.
     *
     * @since   2.0.0
     */
    public function registerInto(Application $application, TrustStore $trust, PortalRenderer $renderer): void
    {
        ksort($this->routes, SORT_STRING);
        foreach ($this->routes as $entry) {
            $owner = $entry['owner'];
            if ($owner->identifier() === ContributionOwner::CORE) {
                throw new LogicException('Core portal routes remain registered by core delivery composition.');
            }
            $definition = $entry['definition'];
            $handler = new TrustEnforcingRequestHandler(
                $entry['factory']->create(new PortalContributionRenderer(
                    $renderer,
                    $owner->identifier(),
                    $definition->template,
                )),
                $trust,
                $owner->identifier(),
            );
            $pipeline = array_intersect($definition->methods, ['DELETE', 'PATCH', 'POST', 'PUT']) === []
                ? $handler
                : [PortalCsrfMiddleware::class, $handler];
            $route = $application->route(
                self::routePath($owner, $definition),
                $pipeline,
                $definition->methods,
                self::routeName($owner, $definition),
            );
            $route->setOptions([
                PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => [$definition->capability],
            ]);
            if ($definition->path !== '/') {
                continue;
            }

            $canonicalPath = self::routePath($owner, $definition);
            $redirectHandler = new TrustEnforcingRequestHandler(
                new PortalExtensionRootRedirectHandler($canonicalPath),
                $trust,
                $owner->identifier(),
            );
            $redirectPipeline = array_intersect($definition->methods, ['DELETE', 'PATCH', 'POST', 'PUT']) === []
                ? $redirectHandler
                : [PortalCsrfMiddleware::class, $redirectHandler];
            $redirectRoute = $application->route(
                $canonicalPath . '/',
                $redirectPipeline,
                $definition->methods,
                self::routeName($owner, $definition) . ':canonical-trailing-slash',
            );
            $redirectRoute->setOptions([
                PortalAuthorizationMiddleware::OPTION_REQUIRED_CAPABILITIES => [$definition->capability],
            ]);
        }
    }

    /**
     * List one owner's routes with their collision-resolved mounted names and paths.
     *
     * @param   ContributionOwner  $owner  Contributor to inspect.
     *
     * @return  list<array<string, mixed>>  Route inventory.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array
    {
        $result = [];
        foreach ($this->routes as $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                $result[] = $entry['definition']->toArray() + [
                    'registered_name' => self::routeName($owner, $entry['definition']),
                    'registered_path' => self::routePath($owner, $entry['definition']),
                ];
            }
        }
        return $result;
    }

    /**
     * Withdraw one owner's unmounted declarations and release path claims.
     *
     * @param   ContributionOwner  $owner  Contributor being withdrawn.
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
            foreach ($entry['definition']->methods as $method) {
                unset($this->paths[$method . ':' . self::routePath($owner, $entry['definition'])]);
            }
            unset($this->routes[$name]);
        }
    }

    /**
     * Compose a globally unique router name.
     *
     * @param   ContributionOwner      $owner       Route owner.
     * @param   PortalRouteDefinition  $definition  Route declaration.
     *
     * @return  non-empty-string  Core or extension-prefixed name.
     *
     * @since   2.0.0
     */
    private static function routeName(ContributionOwner $owner, PortalRouteDefinition $definition): string
    {
        $name = $owner->identifier() === ContributionOwner::CORE
            ? 'portal.' . $definition->name
            : 'portal.extension.' . $definition->name;
        /** @var non-falsy-string $name */
        return $name;
    }

    /**
     * Confine extension routes below their own `/portal/extensions/vendor/name` prefix.
     *
     * Public because it is the single authority on where a contributed portal route surfaces:
     * the contribution summary an operator reads composes its links with this exact rule rather
     * than keeping a second copy of it.
     *
     * @param   ContributionOwner      $owner       Route owner.
     * @param   PortalRouteDefinition  $definition  Route declaration.
     *
     * @return  non-empty-string  Absolute mounted path.
     *
     * @since   2.0.0
     */
    public static function routePath(ContributionOwner $owner, PortalRouteDefinition $definition): string
    {
        $path = $owner->identifier() === ContributionOwner::CORE
            ? $definition->path
            : '/portal/extensions/' . $owner->identifier()
                . ($definition->path === '/' ? '' : $definition->path);
        /** @var non-empty-string $path */
        return $path;
    }
}
