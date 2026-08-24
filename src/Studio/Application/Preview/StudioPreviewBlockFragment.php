<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;

/**
 * Fixed safe presentation decision returned by a preview block renderer.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewBlockFragment
{
    /**
     * Exact layout properties and values the canonical renderer may expose to the site theme.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const array LAYOUT_PROPERTIES = [
        'alignment' => ['center', 'end', 'start', 'stretch'],
        'collapse' => ['preserve', 'stack', 'wrap'],
        'columns' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'],
        'direction' => ['block', 'inline'],
        'spacing' => ['comfortable', 'compact', 'none', 'spacious'],
        'visibility' => ['hidden', 'visible'],
    ];

    /**
     * Exact semantic widths a marker-free public fragment may retain.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array PUBLIC_VIEWPORTS = ['compact', 'medium', 'expanded'];

    /**
     * Deterministically ordered safe attributes for one core layout fragment.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public array $layoutAttributes;

    /**
     * Retain only allowlisted element/class names and plain text.
     *
     * @param   string                 $element           Fixed semantic HTML element name.
     * @param   string                 $className         Fixed stylesheet class name.
     * @param   string                 $text              Plain UTF-8 text, never markup.
     * @param   bool                   $hidden            Whether binding policy hides content but retains its marker.
     * @param   array<string, string>  $layoutAttributes  Closed one-width preview or all-width public layout
     *          projection.
     *
     * @throws  InvalidArgumentException  When a renderer tries to emit an unsafe name, value, or attribute.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $element,
        public string $className,
        public string $text,
        public bool $hidden = false,
        array $layoutAttributes = [],
    ) {
        if (
            !in_array($element, ['article', 'div', 'section'], true)
            || preg_match('/^[a-z][a-z0-9-]{0,99}$/D', $className) !== 1
            || mb_strlen($text, 'UTF-8') > 100_000
        ) {
            throw new InvalidArgumentException('A Studio preview block fragment is unsafe.');
        }
        foreach ($layoutAttributes as $name => $value) {
            $property = self::layoutProperty($name);
            if (
                !is_string($name)
                || !is_string($value)
                || $property === null
                || !in_array($value, self::LAYOUT_PROPERTIES[$property], true)
            ) {
                throw new InvalidArgumentException('A Studio preview layout attribute is unsafe.');
            }
        }
        ksort($layoutAttributes, SORT_STRING);
        $this->layoutAttributes = $layoutAttributes;
    }

    /**
     * Resolve a fixed preview or public responsive attribute name to its closed layout property.
     *
     * @param   mixed  $name  Candidate attribute key supplied by a renderer.
     *
     * @return  string|null  Closed property name, or null for an arbitrary attribute.
     *
     * @since   2.0.0
     */
    private static function layoutProperty(mixed $name): ?string
    {
        if (!is_string($name) || !str_starts_with($name, 'data-studio-layout-')) {
            return null;
        }
        $suffix = substr($name, strlen('data-studio-layout-'));
        if (isset(self::LAYOUT_PROPERTIES[$suffix])) {
            return $suffix;
        }
        foreach (self::PUBLIC_VIEWPORTS as $viewport) {
            $prefix = $viewport . '-';
            if (str_starts_with($suffix, $prefix)) {
                $property = substr($suffix, strlen($prefix));

                return isset(self::LAYOUT_PROPERTIES[$property]) ? $property : null;
            }
        }

        return null;
    }
}
