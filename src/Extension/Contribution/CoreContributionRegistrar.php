<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\InterfaceStandard\SurfaceDefinition;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\Extension\Spi\Contribution\AdministratorNavigationDefinition;
use Kumwe\Extension\Spi\Contribution\AdministratorWorkspaceDefinition;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Portal\Contribution\PortalNavigationDefinition;
use Kumwe\Extension\Spi\Portal\Contribution\PortalWorkspaceDefinition;

/**
 * Internal composition helper for the App's built-in declarations.
 *
 * This is not an extension-author contract. External declarations arrive only through the SDK's
 * `ManifestContributions`; core uses this concrete host helper because it has no package manifest.
 *
 * @since  2.0.0
 */
final readonly class CoreContributionRegistrar
{
    /**
     * Bind built-in registration to the host registry set.
     *
     * @param  ExtensionContributionRegistrySet  $registries  Complete host contribution registries.
     *
     * @since  2.0.0
     */
    public function __construct(private ExtensionContributionRegistrySet $registries)
    {
    }

    /**
     * Register one built-in capability.
     *
     * @param   CapabilityDefinition  $definition  Core authorization vocabulary entry.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function capability(CapabilityDefinition $definition): void
    {
        $this->registries->capabilities()->register(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in resource policy.
     *
     * @param   ResourcePolicyDefinition  $definition  Core capability-to-resource policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function resourcePolicy(ResourcePolicyDefinition $definition): void
    {
        $this->registries->resourcePolicies()->register(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in administrator workspace.
     *
     * @param   AdministratorWorkspaceDefinition  $definition  Core workspace declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorWorkspace(AdministratorWorkspaceDefinition $definition): void
    {
        $this->registries->workspaces()->register(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in administrator navigation item.
     *
     * @param   AdministratorNavigationDefinition  $definition  Core navigation declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function administratorNavigation(AdministratorNavigationDefinition $definition): void
    {
        $this->registries->navigation()->registerOwned(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in portal workspace.
     *
     * @param   PortalWorkspaceDefinition  $definition  Core portal workspace declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function portalWorkspace(PortalWorkspaceDefinition $definition): void
    {
        $this->registries->portalWorkspaces()->register(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in portal navigation item.
     *
     * @param   PortalNavigationDefinition  $definition  Core portal navigation declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function portalNavigation(PortalNavigationDefinition $definition): void
    {
        $this->registries->portalNavigation()->register(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in semantic interface surface.
     *
     * @param   SurfaceDefinition  $definition  Core KIS declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function interfaceSurface(SurfaceDefinition $definition): void
    {
        $this->registries->interfaceSurfaces()->register(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in field type.
     *
     * @param   FieldTypeDefinition  $definition  Core field-type declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fieldType(FieldTypeDefinition $definition): void
    {
        $this->registries->fieldTypes()->register(DefinitionOwner::core(), $definition);
    }

    /**
     * Bind the built-in presenter for one declared field type.
     *
     * @param   FieldPresentationContribution  $definition  Core context coverage declaration.
     * @param   FieldPresenter                 $presenter   Core semantic presenter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function fieldPresentation(
        FieldPresentationContribution $definition,
        FieldPresenter $presenter,
    ): void {
        $this->registries->fieldPresentations()->register(
            DefinitionOwner::core(),
            $definition->fieldType,
            $definition->contexts,
            $presenter,
        );
    }

    /**
     * Register one built-in event schema.
     *
     * @param   EventSchemaDefinition  $definition  Core event contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function eventSchema(EventSchemaDefinition $definition): void
    {
        $this->registries->eventSchemas()->register(ContributionOwner::core(), $definition);
    }

    /**
     * Register one built-in canonical Studio document.
     *
     * @param   CanonicalCompositionDocument  $document  Producer-validated SDK document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function canonicalCompositionDocument(CanonicalCompositionDocument $document): void
    {
        $this->registries->canonicalCompositionDocuments()->register(ContributionOwner::core(), $document);
    }

    /**
     * Register one built-in Studio host binding.
     *
     * @param   CompositionHostBinding  $binding  Core document host metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compositionHostBinding(CompositionHostBinding $binding): void
    {
        $this->registries->compositionHostBindings()->register(ContributionOwner::core(), $binding);
    }
}
