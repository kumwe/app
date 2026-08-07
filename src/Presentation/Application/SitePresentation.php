<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use InvalidArgumentException;

/**
 * Validates the database-backed public presentation contract and exposes safe CSS design tokens.
 */
final readonly class SitePresentation
{
    /** @var list<string> */
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
     * @param list<array{handle: string, name: string, color_mode: string, colors: array<string, string>}> $schemes
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

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'logo' => '/media/00000000-0000-7000-8000-000000000901/kumwe-symbol.svg',
            'footer_text' => 'Powered by Kumwe CMS',
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

    public static function from(mixed $value): self
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Presentation settings must be an object.');
        }

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

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
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

    public function primaryMenu(): string
    {
        return $this->primaryMenu;
    }

    /** @return array{handle: string, name: string, color_mode: string, colors: array<string, string>} */
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
     * @return list<array{handle: string, name: string, color_mode: string, colors: array<string, string>}>
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
            $handle = self::handle($candidate, 'handle', 'Scheme handle');
            if (isset($handles[$handle])) {
                throw new InvalidArgumentException(sprintf('Presentation scheme %s is duplicated.', $handle));
            }
            $handles[$handle] = true;
            $colors = $candidate['colors'] ?? null;
            if (!is_array($colors) || array_is_list($colors)) {
                throw new InvalidArgumentException(sprintf('Presentation scheme %s requires a color map.', $handle));
            }
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

    /** @param array<string, string> $colors */
    private static function assertAccessibleColors(string $handle, array $colors): void
    {
        foreach ([
            ['ink', 'canvas'],
            ['navy', 'canvas'],
            ['navy', 'surface'],
            ['muted', 'canvas'],
            ['accent_strong', 'surface'],
            ['accent_strong', 'accent_soft'],
            ['on_accent', 'navy'],
        ] as [$foreground, $background]) {
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

    private static function contrast(string $first, string $second): float
    {
        $firstLuminance = self::luminance($first);
        $secondLuminance = self::luminance($second);

        return (max($firstLuminance, $secondLuminance) + 0.05)
            / (min($firstLuminance, $secondLuminance) + 0.05);
    }

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

    /** @param array<string, mixed> $values */
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

    /** @param array<string, mixed> $values */
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

    /** @param array<string, mixed> $values @param list<string> $allowed */
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
