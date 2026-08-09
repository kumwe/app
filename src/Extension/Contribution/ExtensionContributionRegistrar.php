<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;

/**
 * The sink a contribution provider registers each of its declared surfaces through.
 *
 * This is the whole of an extension's reach into the CMS shell: it never sees the registries behind
 * the methods, so it cannot read, replace, or withdraw anything another package contributed. Every
 * implementation is bound to one owner for one contribution phase, rejects an identifier outside that
 * owner's namespace, rejects the same identifier twice, and stops accepting once the phase closes.
 *
 * Order within a phase matters: a navigation item names a workspace and a capability, a route names a
 * capability and a view, and each of those must already have been registered by this same owner.
 *
 * @since  2.0.0
 */
interface ExtensionContributionRegistrar
{
    /**
     * Add one permission code to the site-wide capability vocabulary.
     *
     * A contributed capability is catalogued but granted to nobody, so an operator still has to assign
     * it to a role before the surfaces guarded by it become reachable.
     *
     * @param   CapabilityDefinition  $definition  Capability code with the wording shown to an operator.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function capability(CapabilityDefinition $definition): void;

    /**
     * Add a top-level grouping that administrator navigation items can be filed under.
     *
     * @param   AdministratorWorkspaceDefinition  $definition  Workspace identity, wording, and ordering priority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorWorkspace(AdministratorWorkspaceDefinition $definition): void;

    /**
     * Add one entry to the administrator navigation.
     *
     * The entry is presented only to an operator holding its capability, and only while its owner is
     * still trusted, so contributing it is not by itself enough to make the page visible.
     *
     * @param   AdministratorNavigationDefinition  $definition  Link target, wording, and the workspace and
     *          capability it belongs to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorNavigation(AdministratorNavigationDefinition $definition): void;

    /**
     * Add a named template that contributed administrator routes may render.
     *
     * @param   AdministratorViewDefinition  $definition  View name and the template it resolves to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorView(AdministratorViewDefinition $definition): void;

    /**
     * Add one guarded administrator route and the factory that builds its handler.
     *
     * The handler is built later, when the application is routed, and is wrapped in authorization and
     * live trust enforcement, so the factory runs at wiring time rather than on the request path.
     *
     * @param   AdministratorRouteDefinition      $definition  Route name, path, methods, and the capability
     *          and view it references.
     * @param   AdministratorRouteHandlerFactory  $factory     Builds the route's handler once the renderer exists.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorRoute(
        AdministratorRouteDefinition $definition,
        AdministratorRouteHandlerFactory $factory,
    ): void;

    /**
     * Add a field type that business definitions may build fields from.
     *
     * @param   FieldTypeDefinition  $definition  Field-type structure, identified under the owner's namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fieldType(FieldTypeDefinition $definition): void;

    /**
     * Add an entity type this package owns to the contributed business-definition set.
     *
     * The whole contributed set is validated as one graph after every provider has run, so a
     * definition referencing another package's type fails the phase rather than this call.
     *
     * @param   EntityTypeDefinition  $definition  Entity type whose handle and owner sit in the owner's namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function businessDefinition(EntityTypeDefinition $definition): void;
}
