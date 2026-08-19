<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation\Application;

use InvalidArgumentException;

/**
 * Validated public presentation contract: the branding, layout, and colour choices a site renders with.
 *
 * Presentation settings are operator input stored as JSON, yet they end up inside `style` attributes and
 * link targets, so they cannot be trusted the way a template constant can. Every value that reaches a
 * layout passes through `from()`, which pins the logo to a root-relative URL free of quoting characters,
 * restricts the style choices to fixed vocabularies, forces each scheme colour to `#RRGGBB`, and rejects
 * a palette whose text pairings fall below the WCAG AA contrast floor. `DoctrineSiteSettings` validates
 * on read as well as on write, so a settings row edited outside the application cannot smuggle unsafe
 * CSS — or an unreadable palette — into a page.
 *
 * @since  2.0.0
 */
final readonly class SitePresentation
{
    /**
     * Colour roles every scheme must define, and the order their CSS custom properties are emitted in.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array COLOR_KEYS = [
        'navy',
        'ink',
        'muted',
        'canvas',
        'surface',
        'border',
        'accent',
        'accent_strong',
        'accent_soft',
        'on_accent',
    ];

    /**
     * Capture an already-validated presentation contract.
     *
     * Private on purpose: `from()` is the only way in, so every field an instance carries has passed the
     * safety rules and downstream code may treat it as trusted.
     *
     * @param string $logo Root-relative logo URL, empty when the site shows no logo.
     * @param string $footerText Short line rendered in the site footer.
     * @param string $primaryMenu Handle of the menu the chrome renders as primary navigation.
     * @param string $activeScheme Handle of the scheme in `$schemes` whose palette is in force.
     * @param string $buttonStyle How buttons are filled: solid, soft, or outline.
     * @param string $buttonShape How button corners are cut: square, rounded, or pill.
     * @param string $headerStyle How the site header is drawn: solid, glass, or borderless.
     * @param  list<array{handle: string, name: string, color_mode: string, colors: array<string, string>}>  $schemes
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $logo,
        private string $footerText,
        private string $primaryMenu,
        private string $activeScheme,
        private string $buttonStyle,
        private string $buttonShape,
        private string $headerStyle,
        private array $schemes,
    ) {
    }

    /**
     * Return the presentation contract a site uses until an operator saves one of their own.
     *
     * The three shipped schemes already satisfy every rule `from()` enforces, so the defaults survive a
     * round trip through validation unchanged and can be stored as-is when a site is created.
     *
     * @return  array<string, mixed>  Raw settings in the shape `from()` accepts and `toArray()` emits.
     *
     * @since   2.0.0
     */
    public static function defaults(): array
    {
        return [
            'logo' => '/media/00000000-0000-7000-8000-000000000901/kumwe-symbol.svg',
            'footer_text' => 'Powered by Kumwe App',
            'primary_menu' => 'main',
            'active_scheme' => 'corporate',
            'button_style' => 'solid',
            'button_shape' => 'rounded',
            'header_style' => 'glass',
            'schemes' => [
                [
                    'handle' => 'corporate',
                    'name' => 'Corporate Navy & Teal',
                    'color_mode' => 'light',
                    'colors' => [
                        'navy' => '#07182d',
                        'ink' => '#13233a',
                        'muted' => '#5c6f84',
                        'canvas' => '#f5f8fb',
                        'surface' => '#ffffff',
                        'border' => '#dce5ed',
                        'accent' => '#0c9189',
                        'accent_strong' => '#08726d',
                        'accent_soft' => '#dff6f4',
                        'on_accent' => '#ffffff',
                    ],
                ],
                [
                    'handle' => 'ocean',
                    'name' => 'Ocean Blue',
                    'color_mode' => 'light',
                    'colors' => [
                        'navy' => '#08152a',
                        'ink' => '#172439',
                        'muted' => '#647386',
                        'canvas' => '#f7f9fc',
                        'surface' => '#ffffff',
                        'border' => '#d7e1e6',
                        'accent' => '#0777af',
                        'accent_strong' => '#056b9f',
                        'accent_soft' => '#e2f3fb',
                        'on_accent' => '#ffffff',
                    ],
                ],
                [
                    'handle' => 'graphite',
                    'name' => 'Graphite & Silver',
                    'color_mode' => 'light',
                    'colors' => [
                        'navy' => '#17202b',
                        'ink' => '#202833',
                        'muted' => '#66717f',
                        'canvas' => '#f4f5f7',
                        'surface' => '#ffffff',
                        'border' => '#d8dde3',
                        'accent' => '#3c526b',
                        'accent_strong' => '#25394f',
                        'accent_soft' => '#e8edf2',
                        'on_accent' => '#ffffff',
                    ],
                ],
            ],
        ];
    }

    /**
     * Validate raw settings and return the contract templates are allowed to render.
     *
     * This is where every rule the class exists for is applied: the logo must be root-relative and free
     * of characters that could escape an attribute, each style choice must come from its vocabulary, the
     * one to twelve schemes must carry unique handles and complete `#RRGGBB` palettes that clear WCAG AA
     * text contrast, and the active scheme must be one of them. Callers validate on the way in and on
     * the way out of storage, so an unchecked value never reaches a layout.
     *
     * @param   mixed  $value  Decoded settings object, normally the `presentation` key of a settings row.
     *
     * @return  self  A contract whose every field is safe to interpolate into markup.
     *
     * @throws  InvalidArgumentException  When the value is not an object or breaks any rule above.
     *
     * @since   2.0.0
     */
    public static function from(mixed $value): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Presentation settings must be an object.');
        }
        /** @var array<string, mixed> $value */

        $logo = self::string($value, 'logo', 2_048, true);
        self::assertUrl($logo);
        $footerText = self::string($value, 'footer_text', 255);
        $primaryMenu = self::handle($value, 'primary_menu', 'Primary menu');
        $activeScheme = self::handle($value, 'active_scheme', 'Active scheme');
        $buttonStyle = self::choice($value, 'button_style', ['solid', 'soft', 'outline']);
        $buttonShape = self::choice($value, 'button_shape', ['square', 'rounded', 'pill']);
        $headerStyle = self::choice($value, 'header_style', ['solid', 'glass', 'borderless']);
        $schemes = self::schemes($value['schemes'] ?? null);

        if (!in_array($activeScheme, array_column($schemes, 'handle'), true)) {
            throw new InvalidArgumentException('The active presentation scheme must exist in the scheme list.');
        }

        return new self(
            $logo,
            $footerText,
            $primaryMenu,
            $activeScheme,
            $buttonStyle,
            $buttonShape,
            $headerStyle,
            $schemes,
        );
    }

    /**
     * Export the contract in the storage shape, ready to be persisted or re-validated.
     *
     * @return  array<string, mixed>  The eight settings keys, with every scheme colour lowercased.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'logo' => $this->logo,
            'footer_text' => $this->footerText,
            'primary_menu' => $this->primaryMenu,
            'active_scheme' => $this->activeScheme,
            'button_style' => $this->buttonStyle,
            'button_shape' => $this->buttonShape,
            'header_style' => $this->headerStyle,
            'schemes' => $this->schemes,
        ];
    }

    /**
     * Export the contract for a template, with the active scheme flattened into CSS custom properties.
     *
     * The `css_variables` map is the whole of what a layout needs to theme itself, and its values are
     * validated hex literals, so a template may write them straight into a `style` attribute. Use this
     * rather than `toArray()` whenever the result is handed to Twig.
     *
     * @return  array<string, mixed>  Everything `toArray()` returns, plus `color_mode` and `css_variables`.
     *
     * @since   2.0.0
     */
    public function toView(): array
    {
        $scheme = $this->active();
        $colors = $scheme['colors'];

        return $this->toArray() + [
            'color_mode' => $scheme['color_mode'],
            'css_variables' => [
                '--site-navy-950' => $colors['navy'],
                '--site-ink' => $colors['ink'],
                '--site-muted' => $colors['muted'],
                '--site-canvas' => $colors['canvas'],
                '--site-surface' => $colors['surface'],
                '--site-border' => $colors['border'],
                '--site-accent' => $colors['accent'],
                '--site-accent-strong' => $colors['accent_strong'],
                '--site-accent-soft' => $colors['accent_soft'],
                '--site-on-accent' => $colors['on_accent'],
            ],
        ];
    }

    /**
     * Return the menu the site chrome renders as its primary navigation.
     *
     * @return  string  Lowercase menu handle; `PublicPageLocator` resolves paths against this menu.
     *
     * @since   2.0.0
     */
    public function primaryMenu(): string
    {
        return $this->primaryMenu;
    }

    /**
     * Expose the validated colour schemes for screens that offer them as choices.
     *
     * @return  list<array{handle: string, name: string, color_mode: string, colors: array<string, string>}>
     *          Every scheme the contract carries, in stored order.
     *
     * @since   2.0.0
     */
    public function schemeCatalog(): array
    {
        return $this->schemes;
    }

    /**
     * Return a copy whose active scheme is the named one, or this instance when the name misses.
     *
     * This is how a per-menu colour-scheme binding takes effect: the handle stored on a menu item is
     * offered here, and only a handle naming one of the validated schemes changes anything. A null,
     * blank, or unknown handle keeps the site's own decision, so a scheme that was renamed or removed
     * after being bound degrades to the default instead of failing the page.
     *
     * @param   ?string  $handle  Candidate scheme handle, or null when no override is bound.
     *
     * @return  self  A presentation whose palette reflects the override, or this very instance.
     *
     * @since   2.0.0
     */
    public function withSchemeOverride(?string $handle): self
    {
        if ($handle === null || $handle === '' || $handle === $this->activeScheme) {
            return $this;
        }
        foreach ($this->schemes as $scheme) {
            if ($scheme['handle'] === $handle) {
                return new self(
                    $this->logo,
                    $this->footerText,
                    $this->primaryMenu,
                    $handle,
                    $this->buttonStyle,
                    $this->buttonShape,
                    $this->headerStyle,
                    $this->schemes,
                );
            }
        }

        return $this;
    }

    /**
     * Find the scheme the contract marks as in force.
     *
     * @return  array{handle: string, name: string, color_mode: string, colors: array<string, string>}
     *
     * @throws  \LogicException  When the active handle matches no scheme, which `from()` rules out.
     *
     * @since   2.0.0
     */
    private function active(): array
    {
        foreach ($this->schemes as $scheme) {
            if ($scheme['handle'] === $this->activeScheme) {
                return $scheme;
            }
        }

        throw new \LogicException('The validated active presentation scheme is missing.');
    }

    /**
     * Validate the scheme list and normalise every palette to lowercase hex.
     *
     * Handles are required to be unique so `active()` can never be ambiguous, and each palette is put
     * through the contrast check before it is accepted, which is what makes the CSS variables emitted
     * later safe to trust without re-inspecting them.
     *
     * @param   mixed  $value  Raw `schemes` value taken from the settings object.
     *
     * @return  list<array{handle: string, name: string, color_mode: string, colors: array<string, string>}>
     *
     * @throws  InvalidArgumentException  When the list is empty or too long, or a scheme is duplicated.
     *
     * @since   2.0.0
     */
    private static function schemes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 12) {
            throw new InvalidArgumentException('Presentation settings require between 1 and 12 schemes.');
        }

        $schemes = [];
        $handles = [];
        foreach ($value as $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new InvalidArgumentException('Every presentation scheme must be an object.');
            }
            /** @var array<string, mixed> $candidate */
            $handle = self::handle($candidate, 'handle', 'Scheme handle');
            if (isset($handles[$handle])) {
                throw new InvalidArgumentException(sprintf('Presentation scheme %s is duplicated.', $handle));
            }
            $handles[$handle] = true;
            $colors = $candidate['colors'] ?? null;
            if (!is_array($colors) || array_is_list($colors)) {
                throw new InvalidArgumentException(sprintf('Presentation scheme %s requires a color map.', $handle));
            }
            /** @var array<string, mixed> $colors */
            $normalizedColors = [];
            foreach (self::COLOR_KEYS as $key) {
                $color = $colors[$key] ?? null;
                if (!is_string($color) || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
                    throw new InvalidArgumentException(sprintf(
                        'Presentation scheme %s color %s must use #RRGGBB notation.',
                        $handle,
                        $key,
                    ));
                }
                $normalizedColors[$key] = strtolower($color);
            }
            self::assertAccessibleColors($handle, $normalizedColors);
            $schemes[] = [
                'handle' => $handle,
                'name' => self::string($candidate, 'name', 80),
                'color_mode' => self::choice($candidate, 'color_mode', ['light', 'dark']),
                'colors' => $normalizedColors,
            ];
        }

        return $schemes;
    }

    /**
     * Reject a palette whose text pairings fall below the WCAG AA 4.5:1 contrast ratio.
     *
     * The pairs checked here are the combinations the shipped layouts actually place text in, so a
     * scheme that passes cannot produce unreadable body copy, headings, muted text, or button labels —
     * which is the failure an operator would otherwise only discover after publishing.
     *
     * @param   string                 $handle  Scheme handle, named in the error so an operator knows it.
     * @param   array<string, string>  $colors  Normalised palette keyed by the roles in `COLOR_KEYS`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any checked pair falls below the ratio.
     *
     * @since   2.0.0
     */
    private static function assertAccessibleColors(string $handle, array $colors): void
    {
        foreach (
            [
            ['ink', 'canvas'],
            ['navy', 'canvas'],
            ['navy', 'surface'],
            ['muted', 'canvas'],
            ['accent_strong', 'surface'],
            ['accent_strong', 'accent_soft'],
            ['on_accent', 'navy'],
            ] as [$foreground, $background]
        ) {
            if (self::contrast($colors[$foreground], $colors[$background]) < 4.5) {
                throw new InvalidArgumentException(sprintf(
                    'Presentation scheme %s colors %s and %s must meet WCAG AA text contrast.',
                    $handle,
                    $foreground,
                    $background,
                ));
            }
        }
    }

    /**
     * Compute the WCAG contrast ratio between two colours.
     *
     * @param   string  $first   Six-digit hex colour, leading `#` included.
     * @param   string  $second  Six-digit hex colour, leading `#` included.
     *
     * @return  float  Ratio from 1.0 for identical colours to 21.0; argument order does not matter.
     *
     * @since   2.0.0
     */
    private static function contrast(string $first, string $second): float
    {
        $firstLuminance = self::luminance($first);
        $secondLuminance = self::luminance($second);

        return (max($firstLuminance, $secondLuminance) + 0.05)
            / (min($firstLuminance, $secondLuminance) + 0.05);
    }

    /**
     * Compute the relative luminance of a colour under the WCAG sRGB formula.
     *
     * @param   string  $color  Six-digit hex colour, leading `#` included.
     *
     * @return  float  Relative luminance, 0.0 for black through 1.0 for white.
     *
     * @since   2.0.0
     */
    private static function luminance(string $color): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $channel = hexdec(substr($color, $offset, 2)) / 255;
            $channels[] = $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    /**
     * Read one string field from the settings object and hold it to a length budget.
     *
     * The value is trimmed before it is measured and returned, so surrounding whitespace never reaches
     * storage or a template.
     *
     * @param   array<string, mixed>  $values      Settings object the field is read from.
     * @param   string                $key         Field name, also quoted in the error message.
     * @param   int                   $maximum     Longest accepted value, counted in characters.
     * @param   bool                  $allowEmpty  Whether an empty string is a legitimate answer.
     *
     * @return  string  The trimmed value.
     *
     * @throws  InvalidArgumentException  When the field is absent, is not a string, or breaks the budget.
     *
     * @since   2.0.0
     */
    private static function string(array $values, string $key, int $maximum, bool $allowEmpty = false): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Presentation %s must be a string.', $key));
        }
        $value = trim($value);
        if ((!$allowEmpty && $value === '') || mb_strlen($value) > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'Presentation %s must contain %s to %d characters.',
                $key,
                $allowEmpty ? '0' : '1',
                $maximum,
            ));
        }

        return $value;
    }

    /**
     * Read a field that has to be a machine handle rather than free text.
     *
     * Handles are used as scheme identifiers and menu keys, so they are held to a lowercase, underscore
     * shape that stays safe when it appears in a lookup or a generated identifier.
     *
     * @param   array<string, mixed>  $values  Settings object the field is read from.
     * @param   string                $key     Field name to read.
     * @param   string                $label   Human-readable field name for the error message.
     *
     * @return  string  The validated handle.
     *
     * @throws  InvalidArgumentException  When the value is absent, over 64 characters, or misshapen.
     *
     * @since   2.0.0
     */
    private static function handle(array $values, string $key, string $label): string
    {
        $handle = self::string($values, $key, 64);
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $handle) !== 1) {
            throw new InvalidArgumentException(
                $label . ' must start with a letter and use lowercase letters, numbers, or underscores.',
            );
        }

        return $handle;
    }

    /**
     * Read a field that has to name one of a fixed vocabulary.
     *
     * Style fields end up as CSS class suffixes, so restricting them to a closed set keeps operator
     * input out of the class names a layout emits.
     *
     * @param   array<string, mixed>  $values   Settings object the field is read from.
     * @param   string                $key      Field name to read.
     * @param   list<string>          $allowed  The only values this field accepts, listed in the error message.
     *
     * @return  string  The chosen value, guaranteed to appear in `$allowed`.
     *
     * @throws  InvalidArgumentException  When the value is absent or outside the vocabulary.
     *
     * @since   2.0.0
     */
    private static function choice(array $values, string $key, array $allowed): string
    {
        $value = self::string($values, $key, 32);
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'Presentation %s must be one of %s.',
                $key,
                implode(', ', $allowed),
            ));
        }

        return $value;
    }

    /**
     * Reject a logo URL that could break out of the attribute it is rendered into.
     *
     * Only root-relative paths pass: an absolute or protocol-relative URL would let a settings row point
     * the site logo at a third-party host, and control or quoting characters would let it escape the
     * attribute it is written into. An empty URL is allowed and means the site shows no logo.
     *
     * @param   string  $url  Candidate logo URL as supplied by the operator.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the URL carries unsafe characters or is not root-relative.
     *
     * @since   2.0.0
     */
    private static function assertUrl(string $url): void
    {
        if ($url === '') {
            return;
        }
        if (preg_match('/[\x00-\x20"\'<>]/', $url) === 1) {
            throw new InvalidArgumentException('Presentation logo URL contains unsafe characters.');
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return;
        }
        throw new InvalidArgumentException('Presentation logo URL must be root-relative.');
    }
}
