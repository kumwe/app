<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockReference;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRendererRegistry;
use stdClass;
use Throwable;

/**
 * Resolves core plus exact trusted extension renderer coordinates without interpreting manifest code.
 *
 * @since  2.0.0
 */
final readonly class ContributedStudioPreviewBlockRendererRegistry implements StudioPreviewBlockRendererRegistry
{
    /**
     * Compose the closed core registry with executable owner-bound runtime contributions.
     *
     * @param  CoreStudioPreviewBlockRendererRegistry  $core        Built-in renderer registry.
     * @param  OwnedRuntimeContributionRegistry        $extensions  Active trusted renderer implementations.
     *
     * @since  2.0.0
     */
    public function __construct(
        private CoreStudioPreviewBlockRendererRegistry $core,
        private OwnedRuntimeContributionRegistry $extensions,
    ) {
    }

    /**
     * Report whether an exact core or owner-bound extension coordinate is executable now.
     *
     * @param   StudioPreviewBlockReference  $reference  Candidate dependency-lock coordinate.
     *
     * @return  bool  True only while an exact implementation and its lifecycle fence remain live.
     *
     * @since   2.0.0
     */
    public function supports(StudioPreviewBlockReference $reference): bool
    {
        if ($this->core->supports($reference)) {
            return true;
        }
        if ($reference->revision === null) {
            return false;
        }
        foreach ($this->extensions->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $implementation = $entry['implementation'];
            if (
                $definition instanceof StudioPreviewRendererContribution
                && $implementation instanceof StudioPreviewBlockRenderer
                && $entry['owner']->identifier() === $definition->owner->identifier()
                && $definition->matches($reference)
                && (!$implementation instanceof TrustEnforcingStudioPreviewBlockRenderer
                    || $implementation->isAvailable())
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render through core or one exact owner/version/revision registration; otherwise stay inert.
     *
     * @param   stdClass                     $node       Schema-admitted Blueprint node.
     * @param   StudioPreviewBlockReference  $reference  Exact node and dependency-lock coordinate.
     * @param   StudioPreviewBindingResult   $binding    Safely resolved canonical `value` port.
     * @param   string                       $viewport   Active semantic viewport.
     *
     * @return  StudioPreviewBlockFragment  Safe rendered fragment or inert unresolved diagnostic.
     *
     * @since   2.0.0
     */
    public function render(
        stdClass $node,
        StudioPreviewBlockReference $reference,
        StudioPreviewBindingResult $binding,
        string $viewport,
    ): StudioPreviewBlockFragment {
        if ($this->core->supports($reference)) {
            return $this->core->render($node, $reference, $binding, $viewport);
        }
        if (!$reference->matchesNode($node) || $reference->revision === null) {
            return self::unresolved();
        }
        foreach ($this->extensions->executableEntries() as $entry) {
            $definition = $entry['definition'];
            $implementation = $entry['implementation'];
            if (
                !$definition instanceof StudioPreviewRendererContribution
                || !$implementation instanceof StudioPreviewBlockRenderer
                || $entry['owner']->identifier() !== $definition->owner->identifier()
                || !$definition->matches($reference)
            ) {
                continue;
            }
            try {
                $properties = $node->properties ?? null;
                $propertyValues = [];
                if ($properties instanceof stdClass) {
                    foreach (get_object_vars($properties) as $name => $value) {
                        if (!is_string($name)) {
                            continue;
                        }
                        $propertyValues[$name] = $value;
                    }
                }

                return $implementation->render(
                    new StudioPreviewBlock(
                        is_string($node->id ?? null) ? $node->id : '',
                        $reference->type,
                        $reference->version,
                        $propertyValues,
                    ),
                    $binding,
                    $viewport,
                );
            } catch (Throwable) {
                return self::unresolved();
            }
        }

        return self::unresolved();
    }

    /**
     * Produce the inert marker-visible diagnostic used for every refused renderer path.
     *
     * @return  StudioPreviewBlockFragment  Safe unresolved fragment.
     *
     * @since   2.0.0
     */
    private static function unresolved(): StudioPreviewBlockFragment
    {
        return new StudioPreviewBlockFragment('div', 'studio-preview-unresolved', '');
    }
}
