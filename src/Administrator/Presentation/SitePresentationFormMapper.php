<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Application\SitePresentation;

/** Maps the graphical site-settings controls to the shared presentation contract. */
final readonly class SitePresentationFormMapper
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
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    public function map(array $form): array
    {
        $indices = [];
        foreach (array_keys($form) as $key) {
            if (preg_match('/^scheme_([0-9]+)_handle$/D', $key, $matches) === 1) {
                $indices[] = (int) $matches[1];
            }
        }
        $indices = array_values(array_unique($indices));
        sort($indices, SORT_NUMERIC);

        $schemes = [];
        foreach ($indices as $index) {
            $colors = [];
            foreach (self::COLOR_KEYS as $key) {
                $colors[$key] = $this->value($form, sprintf('scheme_%d_%s', $index, $key));
            }
            $schemes[] = [
                'handle' => $this->value($form, sprintf('scheme_%d_handle', $index)),
                'name' => $this->value($form, sprintf('scheme_%d_name', $index)),
                'color_mode' => $this->value($form, sprintf('scheme_%d_color_mode', $index)),
                'colors' => $colors,
            ];
        }

        return SitePresentation::from([
            'logo' => $this->value($form, 'presentation_logo', false),
            'footer_text' => $this->value($form, 'presentation_footer_text'),
            'primary_menu' => $this->value($form, 'presentation_primary_menu'),
            'active_scheme' => $this->value($form, 'presentation_active_scheme'),
            'button_style' => $this->value($form, 'presentation_button_style'),
            'button_shape' => $this->value($form, 'presentation_button_shape'),
            'header_style' => $this->value($form, 'presentation_header_style'),
            'schemes' => $schemes,
        ])->toArray();
    }

    /** @param array<string, mixed> $form */
    private function value(array $form, string $key, bool $required = true): string
    {
        $value = $form[$key] ?? null;
        if (!is_string($value) || ($required && trim($value) === '')) {
            throw new InvalidArgumentException(sprintf('Presentation field %s is required.', $key));
        }

        return trim($value);
    }
}
