<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Rendering;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Preview\ContributedStudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderState;
use Kumwe\Producer\Render\SafeMarkup;
use stdClass;

/**
 * Projects one contributed SDK fragment renderer into the Producer render engine.
 *
 * The engine owns the node wrapper, scope, markers, and slot traversal; the contributed renderer sees
 * only the frozen SDK preview SPI: a copied block, an authorized binding projection, and the active
 * viewport. The returned bounded fragment is serialized here as inner markup — fixed element and class
 * names, escaped plain text, and the closed layout-attribute vocabulary — so no extension-controlled
 * string ever reaches the document unescaped. A content refusal renders the engine's bounded status
 * fallback; trust and lifecycle failures propagate and fail the render decision closed.
 *
 * @since  2.0.0
 */
final readonly class FragmentStudioPreviewBlockRenderer implements BlockRenderer
{
    /**
     * Bind one trusted SDK implementation to the render attempt's semantic viewport.
     *
     * @param   StudioPreviewBlockRenderer  $inner     Trust-enforced contributed SDK renderer.
     * @param   string                      $viewport  Active semantic viewport for this render decision.
     *
     * @throws  InvalidArgumentException  When the viewport leaves the closed semantic set.
     *
     * @since   2.0.0
     */
    public function __construct(
        private StudioPreviewBlockRenderer $inner,
        private string $viewport,
    ) {
        if (!in_array($viewport, ['compact', 'medium', 'expanded'], true)) {
            throw new InvalidArgumentException('A Studio preview viewport is outside the closed semantic set.');
        }
    }

    /**
     * Render one contributed node through the frozen SDK fragment SPI.
     *
     * @param   stdClass     $node   The decoded Blueprint node to render.
     * @param   string       $scope  The node's CSS-safe scope token.
     * @param   RenderState  $state  Per-render accumulation and engine services.
     *
     * @return  string  Escaped inner HTML for the node.
     *
     * @throws  RenderException  When the node lacks the exact coordinates its registration promised.
     *
     * @since   2.0.0
     */
    public function render(stdClass $node, string $scope, RenderState $state): string
    {
        $id = $node->id ?? null;
        $type = $node->type ?? null;
        $version = $node->version ?? null;
        if (
            !is_string($id) || $id === ''
            || !is_string($type) || $type === ''
            || !is_string($version) || $version === ''
        ) {
            throw new RenderException('A contributed Studio block node is missing exact coordinates.');
        }
        $declared = $node->properties ?? null;
        $properties = $declared instanceof stdClass ? get_object_vars($declared) : [];
        $resolution = $state->bindingResolution($node, 'value');
        $binding = match (true) {
            $resolution->isHidden() => StudioPreviewBindingResult::hidden(),
            $resolution->isAvailable() => new StudioPreviewBindingResult(true, false, $resolution->value()),
            default => StudioPreviewBindingResult::unavailable(),
        };
        try {
            $fragment = $this->inner->render(
                new ContributedStudioPreviewBlock($id, $type, $version, $properties),
                $binding,
                $this->viewport,
            );
        } catch (InvalidArgumentException) {
            return '<p role="status">Unavailable Studio block ' . SafeMarkup::escapeHtml($type) . '</p>';
        }
        $attributes = ' class="' . SafeMarkup::escapeAttribute($fragment->className) . '"';
        foreach ($fragment->layoutAttributes as $name => $value) {
            $attributes .= ' ' . $name . '="' . SafeMarkup::escapeAttribute($value) . '"';
        }
        if ($fragment->hidden) {
            $attributes .= ' hidden';
        }
        $content = $fragment->text === ''
            ? ''
            : '<p>' . SafeMarkup::escapeHtml($fragment->text) . '</p>';
        $slots = ($node->slots ?? null) instanceof stdClass ? array_keys(get_object_vars($node->slots)) : [];
        usort($slots, CanonicalJson::compareCodeUnits(...));
        $children = '';
        foreach ($slots as $slot) {
            $children .= $state->renderChildren($node, $slot);
        }

        return '<' . $fragment->element . $attributes . '>' . $content . $children . '</' . $fragment->element . '>';
    }
}
