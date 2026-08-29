<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Composition;

use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\Producer\Render\BlockCoordinate;
use stdClass;

/**
 * Active canonical contribution projection for Studio boot.
 *
 * Renderer support determines the immutable dependency lock and trusted runtime renderer map.
 * Actor capabilities filter only the authoring documents presented to the current user.
 *
 * @since  2.0.0
 */
final readonly class StudioCompositionContributionCatalog
{
    /**
     * Bind the catalogue to the owned live registries shared by core and extensions.
     *
     * @param  ExtensionContributionRegistrySet  $registries  Owned live contribution lifecycle.
     * @param  StudioBlockRendererRuntime        $runtime     Fresh canonical Producer registry authority.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionContributionRegistrySet $registries,
        private StudioBlockRendererRuntime $runtime,
    ) {
    }

    /**
     * Return all six active kinds, omitting only blocks the host cannot render or find in an
     * existing Blueprint's immutable dependency lock. Actor capabilities affect only presented
     * authoring documents; they never narrow the deployment-derived lock or renderer metadata.
     *
     * @param   array<string, true>  $capabilities  Current actor's capability lookup.
     * @param   list<string>         $renderers     Exact renderer capabilities implemented by this host.
     * @param   ?list<stdClass>      $lockedBlocks  Existing exact lock, or null while provisioning.
     *
     * @return  StudioCompositionContributionProjection  Owner-bound deterministic catalog snapshot.
     *
     * @since   2.0.0
     */
    public function project(
        array $capabilities,
        array $renderers,
        ?array $lockedBlocks = null,
    ): StudioCompositionContributionProjection {
        $runtime = $this->runtime->registry();
        $bindings = [];
        foreach ($this->registries->compositionHostBindings()->entries() as $entry) {
            $definition = $entry['definition'];
            if ($definition instanceof CompositionHostBinding) {
                $bindings[$definition->identifier()] = [
                    'binding' => $definition,
                    'owner' => $entry['owner'],
                ];
            }
        }
        $extensionRenderers = [];
        foreach ($this->registries->studioPreviewRenderers()->entries() as $entry) {
            $definition = $entry['definition'];
            if (
                $definition instanceof StudioPreviewRendererContribution
                && $entry['owner']->identifier() === $definition->owner->identifier()
            ) {
                $extensionRenderers[$definition->blockType] = [
                    'definition' => $definition,
                    'owner' => $entry['owner'],
                ];
            }
        }
        $lockMap = self::lockMap($lockedBlocks);
        $documents = [];
        $owners = [];
        $blockLocks = [];
        $blockRenderers = [];
        foreach ($this->registries->canonicalCompositionDocuments()->entries() as $entry) {
            $definition = $entry['definition'];
            if (!$definition instanceof CanonicalCompositionDocument) {
                continue;
            }
            $bindingEntry = $bindings[$definition->identifier()] ?? null;
            $binding = $bindingEntry['binding'] ?? null;
            $bindingOwner = $bindingEntry['owner'] ?? null;
            if (
                !$binding instanceof CompositionHostBinding
                || !$bindingOwner instanceof ContributionOwner
                || $bindingOwner->identifier() !== $entry['owner']->identifier()
            ) {
                continue;
            }
            if ($definition->kind === CanonicalCompositionKind::BlockDefinition) {
                $document = $definition->document();
                $lock = self::blockLock($document);
                if ($lock === null || $binding->renderer === null) {
                    continue;
                }
                $coordinate = new BlockCoordinate(
                    $lock['type'],
                    $lock['version'],
                    $lock['revision'],
                );
                if (!$runtime->supports($coordinate)) {
                    continue;
                }
                $rendererCapability = $binding->renderer;
                if ($entry['owner']->identifier() === ContributionOwner::CORE) {
                    if (!in_array($binding->renderer, $renderers, true)) {
                        continue;
                    }
                } else {
                    $runtimeEntry = $extensionRenderers[$lock['type']] ?? null;
                    $runtimeDefinition = $runtimeEntry['definition'] ?? null;
                    $runtimeOwner = $runtimeEntry['owner'] ?? null;
                    if (
                        !$runtimeDefinition instanceof StudioPreviewRendererContribution
                        || !$runtimeOwner instanceof ContributionOwner
                        || $runtimeOwner->identifier() !== $entry['owner']->identifier()
                        || !$runtimeDefinition->matches($coordinate)
                        || $runtimeDefinition->renderer !== $binding->renderer
                        || $runtimeDefinition->authoringCapability !== $binding->capability
                    ) {
                        continue;
                    }
                    $rendererCapability = $runtimeDefinition->previewCapability;
                }
                if ($lockMap !== null) {
                    $locked = $lockMap[$lock['type']] ?? null;
                    if ($locked === null) {
                        continue;
                    }
                    if (
                        $locked['version'] !== $lock['version']
                        || $locked['revision'] !== $lock['revision']
                    ) {
                        throw new StudioCompositionLockMismatch($lock['type']);
                    }
                }
                $blockLocks[$lock['type']] = (object) $lock;
                $blockRenderers[$lock['type']] = $rendererCapability;
            }
            if (
                $binding->capability !== null
                && !isset($capabilities[$binding->capability])
            ) {
                continue;
            }
            if (
                $definition->kind === CanonicalCompositionKind::Pattern
                && $lockMap !== null
                && !self::patternIsLocked($definition->document(), $lockMap)
            ) {
                continue;
            }
            $documents[$definition->identifier()] = $definition->document();
            $owners[$definition->identifier()] = $entry['owner']->identifier();
        }
        ksort($documents, SORT_STRING);
        ksort($owners, SORT_STRING);
        ksort($blockLocks, SORT_STRING);
        ksort($blockRenderers, SORT_STRING);

        return new StudioCompositionContributionProjection(
            array_values($documents),
            $owners,
            array_values($blockLocks),
            $blockRenderers,
        );
    }

    /**
     * Compile exact Blueprint lock coordinates into a type-keyed lookup.
     *
     * @param   ?list<stdClass>  $locks  Blueprint lock entries, or null for a new artifact.
     *
     * @return  ?array<string, array{version: string, revision: string}>  Exact lock lookup.
     *
     * @since   2.0.0
     */
    private static function lockMap(?array $locks): ?array
    {
        if ($locks === null) {
            return null;
        }
        $result = [];
        foreach ($locks as $lock) {
            $type = $lock->type ?? null;
            $version = $lock->version ?? null;
            $revision = $lock->revision ?? null;
            if (!is_string($type) || !is_string($version) || !is_string($revision)) {
                continue;
            }
            $result[$type] = ['version' => $version, 'revision' => $revision];
        }

        return $result;
    }

    /**
     * Project an exact immutable lock coordinate from one canonical block definition.
     *
     * @param   stdClass  $document  Canonical block-definition document.
     *
     * @return  ?array{type: string, version: string, revision: string}  Exact canonical lock coordinate.
     *
     * @since   2.0.0
     */
    private static function blockLock(stdClass $document): ?array
    {
        $type = $document->type ?? null;
        $version = $document->version ?? null;
        $revision = $document->revision ?? null;
        if (!is_string($type) || !is_string($version) || !is_string($revision)) {
            return null;
        }

        return [
            'type' => $type,
            'version' => $version,
            'revision' => $revision,
        ];
    }

    /**
     * Decide whether one pattern dependency exactly matches an immutable block lock.
     *
     * @param   stdClass                                                 $candidate  Canonical block dependency.
     * @param   array<string, array{version: string, revision: string}>  $locks      Exact lock lookup.
     *
     * @return  bool  True only for an exact type, version, and revision match.
     *
     * @since   2.0.0
     */
    private static function isLocked(stdClass $candidate, array $locks): bool
    {
        $type = $candidate->type ?? null;
        $version = $candidate->version ?? null;
        $revision = $candidate->revision ?? null;
        if (!is_string($type) || !is_string($version) || !is_string($revision)) {
            return false;
        }
        $locked = $locks[$type] ?? null;

        return $locked !== null
            && $locked['version'] === $version
            && $locked['revision'] === $revision;
    }

    /**
     * Decide whether every block required by one pattern is exactly locked.
     *
     * @param   stdClass                                                 $document  Canonical pattern document.
     * @param   array<string, array{version: string, revision: string}>  $locks     Exact lock lookup.
     *
     * @return  bool  True only when all declared dependencies are exact lock members.
     *
     * @since   2.0.0
     */
    private static function patternIsLocked(stdClass $document, array $locks): bool
    {
        $dependencies = $document->blockDependencies ?? null;
        if (!is_array($dependencies)) {
            return false;
        }
        foreach ($dependencies as $dependency) {
            if (!$dependency instanceof stdClass || !self::isLocked($dependency, $locks)) {
                return false;
            }
        }

        return true;
    }
}
