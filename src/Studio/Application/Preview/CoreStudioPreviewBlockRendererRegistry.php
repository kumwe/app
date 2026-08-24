<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;
use stdClass;

/**
 * Closed core renderer registry for App structural and truthful field block identifiers.
 *
 * @since  2.0.0
 */
final readonly class CoreStudioPreviewBlockRendererRegistry implements StudioPreviewBlockRendererRegistry
{
    /**
     * Exact core block version implemented by this renderer generation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string BLOCK_VERSION = '1.0.0';

    /**
     * Structural block IDs whose properties have canonical layout semantics.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array LAYOUT_BLOCK_TYPES = [
        'studio.core/section',
        'studio.core/stack',
        'studio.core/grid',
        'studio.core/columns',
    ];

    /**
     * Semantic widths emitted into immutable public markup.
     *
     * Preview renders resolve one requested width. Public pages must instead retain every bounded width
     * so the same immutable HTML responds to the visitor's viewport without executing stored code.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array PUBLIC_VIEWPORTS = ['compact', 'medium', 'expanded'];

    /**
     * Block IDs AP-6 implements without extension-owned rendering code.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array BLOCK_TYPES = [
        'studio.core/section',
        'studio.core/stack',
        'studio.core/grid',
        'studio.core/columns',
        'core/field-text',
        'core/field-rich-text',
        'core/field-integer',
        'core/field-decimal',
        'core/field-boolean',
        'core/field-date',
        'core/field-date-time',
        'core/field-media',
        'core/field-resource',
    ];

    /**
     * Exact definition revisions implemented by this core renderer generation.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array BLOCK_REVISIONS = [
        'studio.core/section' => 'layout-section-r1',
        'studio.core/stack' => 'layout-stack-r1',
        'studio.core/grid' => 'layout-grid-r1',
        'studio.core/columns' => 'layout-columns-r1',
        'core/field-text' => 'core-block-r1',
        'core/field-rich-text' => 'core-block-r1',
        'core/field-integer' => 'core-block-r1',
        'core/field-decimal' => 'core-block-r1',
        'core/field-boolean' => 'core-block-r1',
        'core/field-date' => 'core-block-r1',
        'core/field-date-time' => 'core-block-r1',
        'core/field-media' => 'core-block-r1',
        'core/field-resource' => 'core-block-r1',
    ];

    /**
     * Render structural blocks and coerce only the display shape declared by each core field type.
     *
     * Unknown IDs remain marker-visible as unresolved blocks; contributed renderers can be composed
     * through this interface without granting them arbitrary attributes, elements, or raw HTML.
     *
     * @param   stdClass                     $node       Schema-admitted Blueprint node.
     * @param   StudioPreviewBlockReference  $reference  Exact node and dependency-lock coordinate.
     * @param   StudioPreviewBindingResult   $binding    Safely resolved canonical `value` port.
     * @param   string                       $viewport   Active semantic viewport used for responsive intent.
     *
     * @return  StudioPreviewBlockFragment  Fixed presentation names and plain text only.
     *
     * @since   2.0.0
     */
    public function render(
        stdClass $node,
        StudioPreviewBlockReference $reference,
        StudioPreviewBindingResult $binding,
        string $viewport,
    ): StudioPreviewBlockFragment {
        $nodeVersion = $node->version ?? null;
        if (
            ($node->type ?? null) !== $reference->type
            || (is_string($nodeVersion) && $nodeVersion !== $reference->version)
            || !$this->supports($reference)
        ) {
            return new StudioPreviewBlockFragment('div', 'studio-preview-unresolved', '');
        }
        $type = is_string($node->type ?? null) ? $node->type : '';
        if (str_starts_with($type, 'studio.core/')) {
            return self::structural($node, $type, $viewport);
        }
        if (!in_array($type, self::BLOCK_TYPES, true)) {
            return new StudioPreviewBlockFragment('div', 'studio-preview-unresolved', '');
        }
        $suffix = substr($type, strlen('core/field-'));
        $text = $binding->available ? self::display($suffix, $binding->value) : '';

        return new StudioPreviewBlockFragment(
            $suffix === 'rich-text' ? 'article' : 'div',
            'studio-preview-field-' . $suffix,
            $text,
            $binding->hidden,
        );
    }

    /**
     * Report whether this exact built-in block coordinate has a truthful core implementation.
     *
     * @param   StudioPreviewBlockReference  $reference  Candidate block coordinate.
     *
     * @return  bool  True only for the closed type set at its implemented semantic version.
     *
     * @since   2.0.0
     */
    public function supports(StudioPreviewBlockReference $reference): bool
    {
        return $reference->version === self::BLOCK_VERSION
            && $reference->revision !== null
            && hash_equals(self::BLOCK_REVISIONS[$reference->type] ?? '', $reference->revision);
    }

    /**
     * Return the exact current core definition revision for a block type.
     *
     * This is used only to preserve preview compatibility for pre-lock drafts. Published artifacts
     * always carry an explicit immutable lock and never take this fallback.
     *
     * @param   string  $type  Candidate core block type.
     *
     * @return  string|null  Current exact revision, or null for a non-core type.
     *
     * @since   2.0.0
     */
    public static function revisionFor(string $type): ?string
    {
        return self::BLOCK_REVISIONS[$type] ?? null;
    }

    /**
     * Project all bounded layout widths into the closed public data-attribute vocabulary.
     *
     * Each value travels through the same resolver and allowlist as a one-width Studio preview. The
     * viewport and property names are fixed here, so neither a stored attribute name nor stored CSS can
     * reach the public document. Non-layout blocks deliberately receive no responsive attributes.
     *
     * @param   stdClass  $node  Schema-admitted Blueprint node.
     * @param   string    $type  Exact core block type.
     *
     * @return  array<string, string>  Closed attributes for compact, medium, and expanded widths.
     *
     * @throws  InvalidArgumentException  When any bounded width carries malformed layout intent.
     *
     * @since   2.0.0
     */
    public static function publicLayoutAttributes(stdClass $node, string $type): array
    {
        if (!in_array($type, self::LAYOUT_BLOCK_TYPES, true)) {
            return [];
        }
        $attributes = [];
        foreach (self::PUBLIC_VIEWPORTS as $viewport) {
            foreach (self::layoutAttributes($node, $type, $viewport) as $name => $value) {
                $property = substr($name, strlen('data-studio-layout-'));
                $attributes['data-studio-layout-' . $viewport . '-' . $property] = $value;
            }
        }
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /**
     * Render the four canonical structural block families and legacy safe plain properties.
     *
     * @param   stdClass  $node      Schema-admitted Blueprint node.
     * @param   string    $type      Canonical structural block ID.
     * @param   string    $viewport  Active semantic viewport used for responsive intent.
     *
     * @return  StudioPreviewBlockFragment  Fixed structural presentation.
     *
     * @since   2.0.0
     */
    private static function structural(
        stdClass $node,
        string $type,
        string $viewport,
    ): StudioPreviewBlockFragment {
        $class = match ($type) {
            'studio.core/columns' => 'studio-preview-columns',
            'studio.core/grid' => 'studio-preview-grid',
            'studio.core/section' => 'studio-preview-section',
            'studio.core/stack' => 'studio-preview-stack',
            default => 'studio-preview-unresolved',
        };
        $text = '';
        $properties = $node->properties ?? null;
        if ($properties instanceof stdClass) {
            foreach (['text', 'label', 'heading', 'title'] as $name) {
                $value = $properties->{$name} ?? null;
                if (is_string($value)) {
                    $text = mb_substr($value, 0, 10_000, 'UTF-8');
                    break;
                }
            }
        }

        return new StudioPreviewBlockFragment(
            $type === 'studio.core/section' ? 'section' : 'div',
            $class,
            $text,
            false,
            self::layoutAttributes($node, $type, $viewport),
        );
    }

    /**
     * Resolve the canonical layout family into one fixed data-attribute vocabulary.
     *
     * Responsive values for the active viewport override base properties. Missing values use the
     * Studio core defaults; malformed or out-of-vocabulary values fail instead of reaching markup.
     *
     * @param   stdClass  $node      Schema-admitted Blueprint node.
     * @param   string    $type      Canonical structural block ID.
     * @param   string    $viewport  Active semantic viewport used for responsive intent.
     *
     * @return  array<string, string>  Closed semantic layout attributes, or none for an unknown block.
     *
     * @throws  InvalidArgumentException  When a supported layout property is malformed.
     *
     * @since   2.0.0
     */
    private static function layoutAttributes(stdClass $node, string $type, string $viewport): array
    {
        if (!in_array($type, self::LAYOUT_BLOCK_TYPES, true)) {
            return [];
        }
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
     * Resolve one closed token property for the active viewport.
     *
     * @param   stdClass      $node        Schema-admitted Blueprint node.
     * @param   string        $property    Canonical layout property name.
     * @param   string        $viewport    Active semantic viewport.
     * @param   list<string>  $vocabulary  Exact values the core layout contract admits.
     * @param   string        $default     Runtime default when neither base nor viewport supplies a value.
     *
     * @return  string  Exact safe token.
     *
     * @throws  InvalidArgumentException  When the effective value is outside the closed vocabulary.
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
            throw new InvalidArgumentException('A Studio preview layout token is invalid.');
        }

        return $value;
    }

    /**
     * Resolve the bounded column count for a grid or columns block.
     *
     * @param   stdClass  $node      Schema-admitted Blueprint node.
     * @param   string    $viewport  Active semantic viewport.
     *
     * @return  int  Integer from one through twelve.
     *
     * @throws  InvalidArgumentException  When the effective count is not a bounded integer.
     *
     * @since   2.0.0
     */
    private static function columns(stdClass $node, string $viewport): int
    {
        $value = self::property($node, 'columns', $viewport, 1);
        if (!is_int($value) || $value < 1 || $value > 12) {
            throw new InvalidArgumentException('A Studio preview layout column count is invalid.');
        }

        return $value;
    }

    /**
     * Apply an exact viewport override before the base property and runtime default.
     *
     * @param   stdClass  $node      Schema-admitted Blueprint node.
     * @param   string    $property  Canonical layout property name.
     * @param   string    $viewport  Active semantic viewport.
     * @param   mixed     $default   Runtime default for an omitted property.
     *
     * @return  mixed  Effective canonical JSON value.
     *
     * @throws  InvalidArgumentException  When a supported property container is malformed.
     *
     * @since   2.0.0
     */
    private static function property(
        stdClass $node,
        string $property,
        string $viewport,
        mixed $default,
    ): mixed {
        $responsive = $node->responsive ?? null;
        if ($responsive !== null && !$responsive instanceof stdClass) {
            throw new InvalidArgumentException('Studio preview responsive properties are invalid.');
        }
        if ($responsive instanceof stdClass && property_exists($responsive, $property)) {
            $overrides = $responsive->{$property};
            if (!$overrides instanceof stdClass) {
                throw new InvalidArgumentException('A Studio preview responsive layout property is invalid.');
            }
            if (property_exists($overrides, $viewport)) {
                return $overrides->{$viewport};
            }
        }
        $properties = $node->properties ?? null;
        if (!$properties instanceof stdClass) {
            throw new InvalidArgumentException('Studio preview layout properties are invalid.');
        }

        return property_exists($properties, $property) ? $properties->{$property} : $default;
    }

    /**
     * Format a JSON value according to the selected core field's truthful scalar family.
     *
     * @param   string  $kind   Core field suffix.
     * @param   mixed   $value  Authorized projected JSON value.
     *
     * @return  string  Bounded plain text with no markup or URL interpretation.
     *
     * @since   2.0.0
     */
    private static function display(string $kind, mixed $value): string
    {
        $text = match ($kind) {
            'boolean' => is_bool($value) ? ($value ? 'true' : 'false') : '',
            'integer' => is_int($value) ? (string) $value : '',
            'decimal' => is_int($value) || is_float($value) || is_string($value) ? (string) $value : '',
            'rich-text' => self::richText($value),
            'media', 'resource' => self::reference($value),
            default => is_string($value) ? $value : '',
        };

        return mb_substr($text, 0, 100_000, 'UTF-8');
    }

    /**
     * Flatten the closed rich-text JSON tree to plain text without interpreting links or marks.
     *
     * @param   mixed  $value  Authorized rich-text JSON subtree.
     *
     * @return  string  Concatenated text-node content.
     *
     * @since   2.0.0
     */
    private static function richText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return implode('', array_map(self::richText(...), $value));
        }
        if (!$value instanceof stdClass) {
            return '';
        }
        $text = is_string($value->text ?? null) ? $value->text : '';
        $content = $value->content ?? null;

        return $text . (is_array($content) ? implode('', array_map(self::richText(...), $content)) : '');
    }

    /**
     * Select a non-navigable human-readable media or resource reference.
     *
     * @param   mixed  $value  Authorized projected reference value.
     *
     * @return  string  Label, alternative text, or stable ID without resolving a URL.
     *
     * @since   2.0.0
     */
    private static function reference(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!$value instanceof stdClass) {
            return '';
        }
        foreach (['label', 'alt', 'title', 'id'] as $member) {
            $candidate = $value->{$member} ?? null;
            if (is_string($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
