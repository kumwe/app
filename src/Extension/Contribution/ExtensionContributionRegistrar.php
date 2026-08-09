<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteDefinition;
use Kumwe\CMS\Portal\Contribution\PortalRouteHandlerFactory;
use Kumwe\CMS\Portal\Contribution\PortalTemplateDefinition;
use Kumwe\CMS\Portal\Contribution\PortalWorkspaceDefinition;

/**
 * The sink a contribution provider registers each of its declared surfaces through.
 *
 * This is the whole of an extension's reach into the CMS shell: it never sees the registries behind
 * the methods, so it cannot read, replace, or withdraw anything another package contributed. Every
 * implementation is bound to one owner for one contribution phase, rejects an identifier outside that
 * owner's namespace, rejects the same identifier twice, and stops accepting once the phase closes.
 *
 * Order within a phase matters: resource policies name capabilities, navigation items name workspaces
 * and capabilities, and routes name capabilities and views. Every referenced contribution must already
 * have been registered by this same owner.
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
     * Bind one owned capability to the bounded resource selectors it may authorize.
     *
     * The capability must already have been registered by this owner. Extensions cannot attach a
     * policy to someone else's capability or grant authority to a core system identity.
     *
     * @param   ResourcePolicyDefinition  $definition  Owner-namespaced typed action/resource binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function resourcePolicy(ResourcePolicyDefinition $definition): void;

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
     * Add a top-level workspace to the ordinary-user portal shell.
     *
     * @param   PortalWorkspaceDefinition  $definition  Owner-bound workspace declaration.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalWorkspace(PortalWorkspaceDefinition $definition): void;

    /**
     * Add one capability-filtered item to portal navigation.
     *
     * @param   PortalNavigationDefinition  $definition  Owner-bound navigation declaration.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalNavigation(PortalNavigationDefinition $definition): void;

    /**
     * Add a namespaced template that a contributed portal route may render.
     *
     * @param   PortalTemplateDefinition  $definition  Owner-bound portal template declaration.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalTemplate(PortalTemplateDefinition $definition): void;

    /**
     * Add one guarded portal route and the factory that builds its handler.
     *
     * @param   PortalRouteDefinition      $definition  Route and authorization declaration.
     * @param   PortalRouteHandlerFactory  $factory     Handler factory invoked at route-mount time.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function portalRoute(PortalRouteDefinition $definition, PortalRouteHandlerFactory $factory): void;

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
