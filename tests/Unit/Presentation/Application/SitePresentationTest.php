<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\Application\SitePresentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SitePresentation::class)]
final class SitePresentationTest extends TestCase
{
    public function testCorporateDefaultsProduceSafePublicDesignTokens(): void
    {
        $presentation = SitePresentation::from(SitePresentation::defaults());
        $view = $presentation->toView();

        self::assertSame('corporate', $view['active_scheme']);
        self::assertSame('main', $presentation->primaryMenu());
        self::assertSame('#07182d', $view['css_variables']['--site-navy-950']);
        self::assertSame('#0c9189', $view['css_variables']['--site-accent']);
        self::assertSame('light', $view['color_mode']);
    }

    public function testCustomSchemeAndInteractionStylesAreNormalized(): void
    {
        $values = SitePresentation::defaults();
        $values['active_scheme'] = 'custom';
        $values['button_style'] = 'outline';
        $values['button_shape'] = 'pill';
        $values['header_style'] = 'solid';
        $colors = SitePresentation::defaults()['schemes'][0]['colors'];
        self::assertIsArray($colors);
        $values['schemes'][] = [
            'handle' => 'custom',
            'name' => 'Custom',
            'color_mode' => 'dark',
            'colors' => array_map('strtoupper', $colors),
        ];

        $presentation = SitePresentation::from($values)->toView();

        self::assertSame('outline', $presentation['button_style']);
        self::assertSame('pill', $presentation['button_shape']);
        self::assertSame('dark', $presentation['color_mode']);
        self::assertSame('#0c9189', $presentation['css_variables']['--site-accent']);
    }

    public function testRejectsAnUnknownActiveScheme(): void
    {
        $values = SitePresentation::defaults();
        $values['active_scheme'] = 'missing';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('active presentation scheme');
        SitePresentation::from($values);
    }

    public function testRejectsUnsafeLogoUrls(): void
    {
        $values = SitePresentation::defaults();
        $values['logo'] = 'javascript:alert(1)';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root-relative');
        SitePresentation::from($values);
    }

    public function testRejectsAColorSchemeThatCannotMeetTextContrast(): void
    {
        $values = SitePresentation::defaults();
        $values['schemes'][0]['colors']['ink'] = '#ffffff';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WCAG AA text contrast');
        SitePresentation::from($values);
    }

    public function testSchemeOverrideSwitchesOnlyToAValidatedScheme(): void
    {
        $presentation = SitePresentation::from(SitePresentation::defaults());

        $ocean = $presentation->withSchemeOverride('ocean')->toView();
        self::assertSame('ocean', $ocean['active_scheme']);

        $unknown = $presentation->withSchemeOverride('sunset')->toView();
        self::assertSame('corporate', $unknown['active_scheme']);

        self::assertSame($presentation, $presentation->withSchemeOverride(null));
        self::assertSame($presentation, $presentation->withSchemeOverride(''));
        self::assertSame($presentation, $presentation->withSchemeOverride('corporate'));
    }
}
