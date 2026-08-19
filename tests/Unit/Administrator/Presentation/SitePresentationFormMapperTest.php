<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Presentation;

use InvalidArgumentException;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Kumwe\App\Administrator\Presentation\SitePresentationFormMapper;
use Kumwe\App\Presentation\Application\SitePresentation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SitePresentationFormMapper::class)]
#[UsesClass(SitePresentation::class)]
final class SitePresentationFormMapperTest extends TestCase
{
    public function testMapsGraphicalSchemeFieldsWithoutRawJson(): void
    {
        $form = [
            'presentation_logo' => '/media/logo.svg',
            'presentation_footer_text' => 'A managed footer',
            'presentation_primary_menu' => 'corporate',
            'presentation_active_scheme' => 'brand',
            'presentation_button_style' => 'soft',
            'presentation_button_shape' => 'rounded',
            'presentation_header_style' => 'glass',
            'scheme_4_handle' => 'brand',
            'scheme_4_name' => 'Brand scheme',
            'scheme_4_color_mode' => 'light',
        ];
        foreach (SitePresentation::defaults()['schemes'][0]['colors'] as $color => $value) {
            $form['scheme_4_' . $color] = $value;
        }

        $mapped = (new SitePresentationFormMapper(InterfaceTranslation::translator()))->map($form);

        self::assertSame('brand', $mapped['active_scheme']);
        self::assertSame('corporate', $mapped['primary_menu']);
        self::assertSame('Brand scheme', $mapped['schemes'][0]['name']);
        self::assertSame('#0c9189', $mapped['schemes'][0]['colors']['accent']);
    }

    /**
     * A missing presentation field is refused by name, in catalogue wording.
     *
     * The settings screen re-renders this sentence at 422, so it is text an operator reads; the field
     * name inside it is the form key, which is machinery and stays as it is.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingPresentationFieldIsRefusedByNameInCatalogueWording(): void
    {
        $mapper = new SitePresentationFormMapper(InterfaceTranslation::translator());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Presentation field presentation_logo is required.');
        $mapper->map([]);
    }
}
