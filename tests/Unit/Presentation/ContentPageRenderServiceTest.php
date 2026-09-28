<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Presentation;

use Kumwe\App\Presentation\Application\SitePresentation;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Site\Application\SiteSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Pins how the render service hands a page its palette: as a versioned same-origin stylesheet link.
 *
 * A page rendered with theme variables receives `presentation.theme_stylesheet`, the link the layout
 * puts in `<head>`; a page rendered without them receives neither the link nor the variables; and the
 * exact preview document derives its closed theme stylesheet from the same declarations, so the two
 * paths can never disagree about what the palette says.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentPageRenderService::class)]
final class ContentPageRenderServiceTest extends TestCase
{
    /**
     * A themed page is handed the versioned stylesheet link for its scheme, and no inline style.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAThemedPageLinksTheVersionedStylesheetForItsScheme(): void
    {
        $service = $this->service();

        $html = $service->render(
            'page',
            null,
            '/about',
            '/about',
            'ocean',
            'core.public.page',
        );

        $expected = $service->themeStylesheet('ocean');
        self::assertStringContainsString('--site-accent:#0777af;', $expected);
        self::assertSame(
            '<head></head>page|/presentation/theme.css?scheme=ocean&amp;v=' . hash('sha256', $expected)
                . '|10|#0777af',
            $html,
        );
    }

    /**
     * A page rendered without theme variables carries neither a link nor variables.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPageRenderedWithoutThemeVariablesCarriesNoLink(): void
    {
        $html = $this->service()->render(
            'page',
            null,
            '/about',
            '/about',
            null,
            'core.public.page',
            [],
            [],
            false,
        );

        self::assertSame('<head></head>page|none|0|', $html);
    }

    /**
     * The exact preview document's closed theme stylesheet is the same text the public route serves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testThePreviewThemeStylesheetIsTheSameTextThePublicRouteServes(): void
    {
        $service = $this->service();

        $preview = $service->renderPreview(
            'page',
            null,
            '/about',
            '/about',
            'ocean',
            'core.public.page',
            '/studio/preview.css',
        );

        self::assertSame($service->themeStylesheet('ocean'), $preview['themeStylesheet']);
        self::assertStringStartsWith('body{--site-navy-950:#08152a;', $preview['themeStylesheet']);
        self::assertStringContainsString('page|none|0|', $preview['html']);
        self::assertStringContainsString(
            '<link rel="stylesheet" href="/studio/preview.css" data-studio-composition>',
            $preview['html'],
        );
    }

    /**
     * A stored palette value outside the validated grammar is refused before it can be served as CSS.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOffGrammarPaletteValueIsRefused(): void
    {
        self::assertStringContainsString('--site-accent:#0c9189;', $this->service()->themeStylesheet(null));

        $defaults = SitePresentation::defaults();
        $schemes = $defaults['schemes'] ?? null;
        self::assertIsArray($schemes);
        $scheme = $schemes[0] ?? null;
        self::assertIsArray($scheme);
        $colors = $scheme['colors'] ?? null;
        self::assertIsArray($colors);
        $colors['accent'] = 'url(https://attacker.example/x)';
        $scheme['colors'] = $colors;
        $schemes[0] = $scheme;
        $refusing = self::createStub(SiteSettings::class);
        $refusing->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'presentation' => ['schemes' => $schemes] + $defaults,
        ]);

        try {
            (new ContentPageRenderService(
                $refusing,
                new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([]))),
            ))->themeStylesheet(null);
            self::fail('An off-grammar colour must never be served as CSS.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('#RRGGBB', $refusal->getMessage());
        }
    }

    /**
     * Build the service over the default settings and a template that echoes the theme contract.
     *
     * @return  ContentPageRenderService  Service rendering `page.twig`.
     *
     * @since   2.0.0
     */
    private function service(): ContentPageRenderService
    {
        $settings = self::createStub(SiteSettings::class);
        $settings->method('current')->willReturn([
            'site_name' => 'Kumwe',
            'presentation' => SitePresentation::defaults(),
            'search_indexing_enabled' => false,
        ]);

        return new ContentPageRenderService(
            $settings,
            new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([
                'page.twig' => '<head></head>page|'
                    . '{{ presentation.theme_stylesheet is defined ? presentation.theme_stylesheet : "none" }}|'
                    . '{{ presentation.css_variables|length }}|'
                    . '{{ presentation.css_variables["--site-accent"]|default("") }}',
            ]))),
        );
    }
}
