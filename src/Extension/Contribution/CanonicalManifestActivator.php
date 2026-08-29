<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\Extension\Manifest\ManifestContributions;

/**
 * Activates the declarative part of one canonical SDK manifest in the App-owned host registries.
 *
 * The signed SDK graph is the only declaration authority. This host step does not ask provider code
 * to repeat declarations; it registers SDK values directly and interprets only the App-owned policy
 * values that intentionally remain behind the application boundary. Executable declarations are left
 * for `OwnedExtensionBindingRegistrar`, which attaches behavior by canonical identifier.
 *
 * @since  2.0.0
 */
final readonly class CanonicalManifestActivator
{
    /**
     * Bind activation to the complete host registry set.
     *
     * @param  ExtensionContributionRegistrySet  $registries  Host-owned declaration destinations.
     *
     * @since  2.0.0
     */
    public function __construct(private ExtensionContributionRegistrySet $registries)
    {
    }

    /**
     * Activate every non-executable declaration from one SDK-owned manifest graph.
     *
     * @param   ManifestContributions  $manifest  Canonical graph produced by the SDK parser.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function activate(ManifestContributions $manifest): void
    {
        $owner = $manifest->owner;
        $businessOwner = DefinitionOwner::extension($owner->identifier());
        $host = new CanonicalManifestInterpreter($manifest);

        foreach ($host->capabilities() as $definition) {
            $this->registries->capabilities()->register($owner, $definition);
        }
        foreach ($host->resourcePolicies() as $definition) {
            $this->registries->resourcePolicies()->register($owner, $definition);
        }
        foreach ($manifest->administratorWorkspaces() as $definition) {
            $this->registries->workspaces()->register($owner, $definition);
        }
        foreach ($manifest->administratorNavigation() as $definition) {
            $this->registries->navigation()->registerOwned($owner, $definition);
        }
        foreach ($manifest->administratorViews() as $definition) {
            $this->registries->views()->register($owner, $definition);
        }
        foreach ($manifest->portalWorkspaces() as $definition) {
            $this->registries->portalWorkspaces()->register($owner, $definition);
        }
        foreach ($manifest->portalNavigation() as $definition) {
            $this->registries->portalNavigation()->register($owner, $definition);
        }
        foreach ($manifest->portalTemplates() as $definition) {
            $this->registries->portalTemplates()->register($owner, $definition);
        }
        foreach ($host->interfaceSurfaces() as $definition) {
            $this->registries->interfaceSurfaces()->register($owner, $definition);
        }
        foreach ($host->fieldTypes() as $definition) {
            $this->registries->fieldTypes()->register($businessOwner, $definition);
        }
        foreach ($host->businessDefinitions() as $definition) {
            $this->registries->businessDefinitions()->register($businessOwner, $definition);
        }
        foreach ($host->eventSchemas() as $definition) {
            $this->registries->eventSchemas()->register($owner, $definition);
        }
        foreach ($host->queues() as $definition) {
            $this->registries->queues()->register($owner, $definition);
        }
        foreach ($host->schedules() as $definition) {
            $this->registries->schedules()->register($owner, $definition);
        }
        foreach ($host->reports() as $definition) {
            $this->registries->reports()->register($owner, $definition);
        }
        foreach ($host->contentTranslationGroups() as $definition) {
            $this->registries->contentTranslationGroups()->register($owner, $definition);
        }
        foreach ($manifest->compositionBlocks() as $definition) {
            $this->registries->compositionBlocks()->register($owner, $definition);
        }
        foreach ($manifest->compositionPatterns() as $definition) {
            $this->registries->compositionPatterns()->register($owner, $definition);
        }
        foreach ($manifest->compositionFieldControls() as $definition) {
            $this->registries->compositionFieldControls()->register($owner, $definition);
        }
        foreach ($manifest->compositionInspectors() as $definition) {
            $this->registries->compositionInspectors()->register($owner, $definition);
        }
        foreach ($manifest->compositionDesignVocabularies() as $definition) {
            $this->registries->compositionDesignVocabularies()->register($owner, $definition);
        }
        foreach ($manifest->compositionMigrations() as $definition) {
            $this->registries->compositionMigrations()->register($owner, $definition);
        }
        foreach ($manifest->canonicalCompositionDocuments() as $definition) {
            $this->registries->canonicalCompositionDocuments()->register($owner, $definition);
        }
        foreach ($manifest->compositionHostBindings() as $definition) {
            $this->registries->compositionHostBindings()->register($owner, $definition);
        }
    }
}
