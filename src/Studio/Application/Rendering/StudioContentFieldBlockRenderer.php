<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Rendering;

use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderState;
use Kumwe\Producer\Render\SafeMarkup;
use stdClass;

/**
 * App-owned Producer renderer for the Content field block family.
 *
 * The portable renderer knows nothing about App Content disclosure. It asks Producer's render state
 * for the host-authorized `value` port and emits only bounded, escaped semantic text. Producer owns
 * the outer block wrapper, hidden state, scope, CSS and tree traversal.
 *
 * @since  2.0.0
 */
final readonly class StudioContentFieldBlockRenderer implements BlockRenderer
{
    /**
     * Exact App-owned block types implemented here.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array BLOCK_TYPES = [
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
     * Render one exact Content field value without interpreting stored markup or URLs.
     *
     * @param   stdClass     $node   Schema-admitted Blueprint node.
     * @param   string       $scope  Producer-owned CSS scope (unused by this renderer).
     * @param   RenderState  $state  Per-render Producer services and host binding authority.
     *
     * @return  string  Escaped semantic inner markup, or an empty unavailable baseline.
     *
     * @throws  RenderException  When this renderer is invoked outside its exact host registration.
     *
     * @since   2.0.0
     */
    public function render(stdClass $node, string $scope, RenderState $state): string
    {
        unset($scope);
        $type = $node->type ?? null;
        if (!is_string($type) || !in_array($type, self::BLOCK_TYPES, true)) {
            throw new RenderException('The App Content field renderer received an unregistered block type.');
        }
        $binding = $state->bindingResolution($node, 'value');
        if (!$binding->isAvailable()) {
            return '';
        }
        $kind = substr($type, strlen('core/field-'));
        $text = self::display($kind, $binding->value());
        if ($text === '') {
            return '';
        }

        return sprintf(
            '<%1$s data-studio-part="value">%2$s</%1$s>',
            $kind === 'rich-text' ? 'article' : 'p',
            SafeMarkup::escapeHtml($text),
        );
    }

    /**
     * Coerce only the scalar family declared by the exact field block.
     *
     * @param   string  $kind   Closed field suffix.
     * @param   mixed   $value  Authorized canonical value.
     *
     * @return  string  Bounded plain text.
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
     * Flatten the closed rich-text value to inert text.
     *
     * @param   mixed  $value  Bound rich-text value in its canonical closed shape.
     *
     * @return  string  Concatenated plain text with no markup interpreted.
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
     * Select a non-navigable reference label.
     *
     * @param   mixed  $value  Bound media or resource reference value.
     *
     * @return  string  Plain descriptive label; never a URL or identifier the browser navigates.
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
