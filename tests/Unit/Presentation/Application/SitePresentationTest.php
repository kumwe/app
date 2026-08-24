<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Presentation\Application;

use InvalidArgumentException;
use Kumwe\App\Presentation\Application\SitePresentation;
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
        self::assertSame('#07182d', $view['theme_color']);
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
        $unsafeCharacter = SitePresentation::defaults();
        $unsafeCharacter['logo'] = '/media/unsafe"logo.svg';
        $absoluteLogo = SitePresentation::defaults();
        $absoluteLogo['logo'] = 'https://assets.example.test/logo.svg';
        $nonObject = 'not-an-object';
        $wrongString = SitePresentation::defaults();
        $wrongString['logo'] = 17;
        $emptyString = SitePresentation::defaults();
        $emptyString['footer_text'] = '  ';
        $invalidHandle = SitePresentation::defaults();
        $invalidHandle['primary_menu'] = 'Main-Menu';
        $invalidChoice = SitePresentation::defaults();
        $invalidChoice['button_style'] = 'scripted';
        $emptySchemes = SitePresentation::defaults();
        $emptySchemes['schemes'] = [];
        $tooManySchemes = SitePresentation::defaults();
        $tooManySchemes['schemes'] = array_fill(0, 13, $tooManySchemes['schemes'][0]);
        $nonObjectScheme = SitePresentation::defaults();
        $nonObjectScheme['schemes'] = ['not-an-object'];
        $duplicateScheme = SitePresentation::defaults();
        $duplicateScheme['schemes'][] = $duplicateScheme['schemes'][0];
        $nonObjectColors = SitePresentation::defaults();
        $nonObjectColors['schemes'][0]['colors'] = [];
        $invalidColor = SitePresentation::defaults();
        $invalidColor['schemes'][0]['colors']['navy'] = 'navy';

        foreach (
            [
                'unsafe characters' => [$unsafeCharacter, 'unsafe characters'],
                'absolute logo' => [$absoluteLogo, 'root-relative'],
                'non-object settings' => [$nonObject, 'must be an object'],
                'wrong string type' => [$wrongString, 'logo must be a string'],
                'empty required string' => [$emptyString, 'footer_text must contain'],
                'invalid handle' => [$invalidHandle, 'must start with a letter'],
                'invalid choice' => [$invalidChoice, 'button_style must be one of'],
                'empty schemes' => [$emptySchemes, 'between 1 and 12 schemes'],
                'too many schemes' => [$tooManySchemes, 'between 1 and 12 schemes'],
                'non-object scheme' => [$nonObjectScheme, 'scheme must be an object'],
                'duplicate scheme' => [$duplicateScheme, 'is duplicated'],
                'non-object colors' => [$nonObjectColors, 'requires a color map'],
                'invalid color' => [$invalidColor, 'must use #RRGGBB notation'],
            ] as $label => [$values, $message]
        ) {
            try {
                SitePresentation::from($values);
                self::fail(sprintf('The %s presentation was accepted.', $label));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $label);
            }
        }
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
