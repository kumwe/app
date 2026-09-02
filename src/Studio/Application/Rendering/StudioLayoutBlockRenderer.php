<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Rendering;

use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderState;
use Kumwe\Producer\Render\SafeMarkup;
use stdClass;

/**
 * App-owned Producer renderer for the four structural core block families.
 *
 * Producer owns the outer block wrapper, hidden state, scope and tree traversal. The App owns the
 * presentation vocabulary its site stylesheet and Studio canvas key on: one classed structural element
 * carrying the closed `data-studio-layout-*` intent. A preview resolves one requested semantic width;
 * public markup retains every bounded width so the immutable page responds to the visitor's viewport.
 *
 * @since  2.0.0
 */
final readonly class StudioLayoutBlockRenderer implements BlockRenderer
{
    /**
     * Exact structural block types implemented here.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array BLOCK_TYPES = [
        'studio.core/section',
        'studio.core/stack',
        'studio.core/grid',
        'studio.core/columns',
    ];

    /**
     * Semantic widths retained in immutable public markup, narrowest first.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array PUBLIC_VIEWPORTS = ['compact', 'medium', 'expanded'];

    /**
     * Bind the renderer to one preview width, or to every public width.
     *
     * @param  ?string  $viewport  Active preview semantic width; null retains every public width.
     *
     * @since  2.0.0
     */
    public function __construct(private ?string $viewport = null)
    {
        if ($viewport !== null && !in_array($viewport, self::PUBLIC_VIEWPORTS, true)) {
            throw new RenderException('The Studio layout renderer received an unknown semantic width.');
        }
    }

    /**
     * Render one structural container with its closed layout vocabulary and its slot children.
     *
     * @param   stdClass     $node   Schema-admitted Blueprint node.
     * @param   string       $scope  Producer-owned CSS scope (unused by this renderer).
     * @param   RenderState  $state  Per-render Producer services rendering the slot children.
     *
     * @return  string  Classed structural element carrying its layout intent and children.
     *
     * @throws  RenderException  When invoked outside its exact registration or the intent is malformed.
     *
     * @since   2.0.0
     */
    public function render(stdClass $node, string $scope, RenderState $state): string
    {
        unset($scope);
        $type = $node->type ?? null;
        if (!is_string($type) || !in_array($type, self::BLOCK_TYPES, true)) {
            throw new RenderException('The App layout renderer received an unregistered block type.');
        }
        $kind = substr($type, strlen('studio.core/'));
        $attributes = '';
        foreach ($this->layoutAttributes($node, $type) as $name => $value) {
            $attributes .= ' ' . $name . '="' . SafeMarkup::escapeAttribute($value) . '"';
        }
        $element = $kind === 'section' ? 'section' : 'div';

        return sprintf(
            '<%1$s class="studio-preview-%2$s"%3$s>%4$s</%1$s>',
            $element,
            $kind,
            $attributes,
            $state->renderChildren($node, $kind === 'section' ? 'content' : 'items'),
        );
    }

    /**
     * Resolve the closed attribute vocabulary for the bound width, or every public width.
     *
     * @param   stdClass  $node  Schema-admitted Blueprint node.
     * @param   string    $type  Exact structural block type.
     *
     * @return  array<string, string>  Closed layout attributes in deterministic order.
     *
     * @throws  RenderException  When a supported layout property is malformed.
     *
     * @since   2.0.0
     */
    private function layoutAttributes(stdClass $node, string $type): array
    {
        if ($this->viewport !== null) {
            return self::viewportAttributes($node, $type, $this->viewport);
        }
        $attributes = [];
        foreach (self::PUBLIC_VIEWPORTS as $viewport) {
            foreach (self::viewportAttributes($node, $type, $viewport) as $name => $value) {
                $property = substr($name, strlen('data-studio-layout-'));
                $attributes['data-studio-layout-' . $viewport . '-' . $property] = $value;
            }
        }
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /**
     * Resolve the canonical layout family for one semantic width into fixed data attributes.
     *
     * Responsive values for the width override base properties. Missing values use the Studio core
     * defaults; malformed or out-of-vocabulary values fail closed instead of reaching markup.
     *
     * @param   stdClass  $node      Schema-admitted Blueprint node.
     * @param   string    $type      Exact structural block type.
     * @param   string    $viewport  Semantic width whose intent is resolved.
     *
     * @return  array<string, string>  Closed semantic layout attributes.
     *
     * @throws  RenderException  When a supported layout property is malformed.
     *
     * @since   2.0.0
     */
    private static function viewportAttributes(stdClass $node, string $type, string $viewport): array
    {
        $attributes = [
            'data-studio-layout-alignment' => self::token(
                $node,
                'alignment',
                $viewport,
                ['center', 'end', 'start', 'stretch'],
                'stretch',
            ),
            'data-studio-layout-spacing' => self::token(
                $node,
                'spacing',
                $viewport,
                ['comfortable', 'compact', 'none', 'spacious'],
                'comfortable',
            ),
            'data-studio-layout-visibility' => self::token(
                $node,
                'visibility',
                $viewport,
                ['hidden', 'visible'],
                'visible',
            ),
        ];
        if ($type === 'studio.core/stack') {
            $attributes['data-studio-layout-direction'] = self::token(
                $node,
                'direction',
                $viewport,
                ['block', 'inline'],
                'block',
            );
        }
        if (in_array($type, ['studio.core/grid', 'studio.core/columns'], true)) {
            $attributes['data-studio-layout-collapse'] = self::token(
                $node,
                'collapse',
                $viewport,
                ['preserve', 'stack', 'wrap'],
                'stack',
            );
            $attributes['data-studio-layout-columns'] = (string) self::columns($node, $viewport);
        }

        return $attributes;
    }

    /**
     * Resolve one closed token property for a semantic width.
     *
     * @param   stdClass      $node        Schema-admitted Blueprint node.
     * @param   string        $property    Canonical layout property name.
     * @param   string        $viewport    Semantic width being resolved.
     * @param   list<string>  $vocabulary  Exact values the core layout contract admits.
     * @param   string        $default     Runtime default when neither base nor width supplies a value.
     *
     * @return  string  Exact safe token.
     *
     * @throws  RenderException  When the effective value is outside the closed vocabulary.
     *
     * @since   2.0.0
     */
    private static function token(
        stdClass $node,
        string $property,
        string $viewport,
        array $vocabulary,
        string $default,
    ): string {
        $value = self::property($node, $property, $viewport, $default);
        if (!is_string($value) || !in_array($value, $vocabulary, true)) {
            throw new RenderException('A Studio layout token is outside its closed vocabulary.');
        }

        return $value;
    }

    /**
     * Resolve the bounded column count for a grid or columns block.
     *
     * @param   stdClass  $node      Schema-admitted Blueprint node.
     * @param   string    $viewport  Semantic width being resolved.
     *
     * @return  int  Integer from one through twelve.
     *
     * @throws  RenderException  When the effective count is not a bounded integer.
     *
     * @since   2.0.0
     */
    private static function columns(stdClass $node, string $viewport): int
    {
        $value = self::property($node, 'columns', $viewport, 1);
        if (!is_int($value) || $value < 1 || $value > 12) {
            throw new RenderException('A Studio layout column count is outside its bounds.');
        }

        return $value;
    }

    /**
     * Apply an exact width override before the base property and runtime default.
     *
     * @param   stdClass  $node      Schema-admitted Blueprint node.
     * @param   string    $property  Canonical layout property name.
     * @param   string    $viewport  Semantic width being resolved.
     * @param   mixed     $default   Runtime default for an omitted property.
     *
     * @return  mixed  Effective canonical JSON value.
     *
     * @throws  RenderException  When a supported property container is malformed.
     *
     * @since   2.0.0
     */
    private static function property(stdClass $node, string $property, string $viewport, mixed $default): mixed
    {
        $responsive = $node->responsive ?? null;
        if ($responsive !== null && !$responsive instanceof stdClass) {
            throw new RenderException('Studio responsive layout properties are malformed.');
        }
        if ($responsive instanceof stdClass && property_exists($responsive, $property)) {
            $overrides = $responsive->{$property};
            if (!$overrides instanceof stdClass) {
                throw new RenderException('A Studio responsive layout property is malformed.');
            }
            if (property_exists($overrides, $viewport)) {
                return $overrides->{$viewport};
            }
        }
        $properties = $node->properties ?? null;
        if ($properties !== null && !$properties instanceof stdClass) {
            throw new RenderException('Studio layout properties are malformed.');
        }

        return $properties instanceof stdClass && property_exists($properties, $property)
            ? $properties->{$property}
            : $default;
    }
}
