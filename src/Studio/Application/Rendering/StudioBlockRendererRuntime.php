<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Rendering;

use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\BlockRendererRegistry;
use Kumwe\Producer\Render\RenderException;

/**
 * Builds a fresh canonical Producer registry from live host trust authority.
 *
 * No renderer chooses its own coordinate. Canonical documents and separate signed host bindings must
 * agree on owner and identity; extension implementations must additionally remain executable under
 * the current package trust generation. Rebuilding for each decision prevents snapshot reuse after
 * disable, removal, distrust or registry mutation.
 *
 * @since  2.0.0
 */
final readonly class StudioBlockRendererRuntime
{
    /** @since 2.0.0 */
    public function __construct(
        private ExtensionContributionRegistrySet $registries,
        private StudioContentFieldBlockRenderer $fields,
    ) {
    }

    /**
     * Return one fresh Producer registry containing only currently trusted exact coordinates.
     *
     * @param   string  $viewport  Active semantic viewport handed to contributed SDK fragment renderers.
     *
     * @return  BlockRendererRegistry  Direct canonical registry for one publication or render decision.
     *
     * @since   2.0.0
     */
    public function registry(string $viewport = 'expanded'): BlockRendererRegistry
    {
        $registry = BlockRendererRegistry::withCoreCatalog();
        $bindings = [];
        foreach ($this->registries->compositionHostBindings()->entries() as $entry) {
            $binding = $entry['definition'];
            if ($binding instanceof CompositionHostBinding) {
                $bindings[$binding->identifier()] = [
                    'binding' => $binding,
                    'owner' => $entry['owner'],
                ];
            }
        }

        /** @var array<string, array{owner: ContributionOwner, renderer: string}> $extensionCoordinates */
        $extensionCoordinates = [];
        foreach ($this->registries->canonicalCompositionDocuments()->entries() as $entry) {
            $document = $entry['definition'];
            if (
                !$document instanceof CanonicalCompositionDocument
                || $document->kind !== CanonicalCompositionKind::BlockDefinition
            ) {
                continue;
            }
            $bindingEntry = $bindings[$document->identifier()] ?? null;
            $binding = $bindingEntry['binding'] ?? null;
            $bindingOwner = $bindingEntry['owner'] ?? null;
            if (
                !$binding instanceof CompositionHostBinding
                || !$bindingOwner instanceof ContributionOwner
                || $binding->renderer === null
                || $bindingOwner->identifier() !== $entry['owner']->identifier()
            ) {
                continue;
            }
            $canonical = $document->document();
            $type = $canonical->type ?? null;
            $version = $canonical->version ?? null;
            $revision = $canonical->revision ?? null;
            if (!is_string($type) || !is_string($version) || !is_string($revision)) {
                continue;
            }
            try {
                $coordinate = new BlockCoordinate($type, $version, $revision);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if ($entry['owner']->identifier() === ContributionOwner::CORE) {
                $renderer = $this->coreRenderer($registry, $coordinate, $binding->renderer);
                if ($renderer !== null) {
                    $registry->register($coordinate, $renderer);
                }
                continue;
            }
            if (array_key_exists($coordinate->key(), $extensionCoordinates)) {
                throw new RenderException('A trusted extension block coordinate is ambiguous.');
            }
            $extensionCoordinates[$coordinate->key()] = [
                'owner' => $entry['owner'],
                'renderer' => $binding->renderer,
            ];
        }

        foreach ($this->registries->studioPreviewRenderers()->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $implementation = $entry['implementation'];
            if (
                !$definition instanceof StudioPreviewRendererContribution
                || !$implementation instanceof TrustEnforcingStudioPreviewBlockRenderer
            ) {
                continue;
            }
            $coordinate = $definition->coordinate();
            $expected = $extensionCoordinates[$coordinate->key()] ?? null;
            if (
                $expected === null
                || $expected['owner']->identifier() !== $entry['owner']->identifier()
                || $definition->owner->identifier() !== $entry['owner']->identifier()
                || $expected['renderer'] !== $definition->renderer
                || !$implementation->isAvailable()
            ) {
                continue;
            }
            $registry->register($coordinate, new FragmentStudioPreviewBlockRenderer($implementation, $viewport));
        }

        return $registry;
    }

    /**
     * Select the host implementation named by a core-owned binding.
     *
     * @since 2.0.0
     */
    private function coreRenderer(
        BlockRendererRegistry $registry,
        BlockCoordinate $coordinate,
        string $binding,
    ): ?BlockRenderer {
        return match ($binding) {
            'core.renderer/layout' => $registry->draftRendererFor($coordinate->type, $coordinate->version),
            'core.renderer/field' => in_array(
                $coordinate->type,
                StudioContentFieldBlockRenderer::BLOCK_TYPES,
                true,
            ) ? $this->fields : null,
            default => null,
        };
    }
}
