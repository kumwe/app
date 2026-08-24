<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Presentation\Preview\CanonicalStudioPreviewRenderer;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioCompositionMarkupRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\App\Studio\Application\Preview\StudioPreviewThemeStylesheet;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Twig\Loader\ArrayLoader;

/**
 * Pins App-authority binding resolution and every owner-safe core preview field renderer.
 *
 * @since  2.0.0
 */
#[CoversClass(CoreStudioPreviewBlockRendererRegistry::class)]
#[CoversClass(CanonicalStudioPreviewRenderer::class)]
#[CoversClass(ContentPageRenderService::class)]
#[CoversClass(StudioCompositionMarkupRenderer::class)]
#[CoversClass(StudioPreviewBindingResolver::class)]
#[CoversClass(StudioPreviewBindingValues::class)]
#[CoversClass(StudioPreviewBlockFragment::class)]
final class StudioPreviewBindingRendererTest extends TestCase
{
    /**
     * Enumerate exact core field block IDs and representative authorized values.
     *
     * @return  iterable<string, array{string, mixed, string}>  Block ID, projected value and expected text.
     *
     * @since   2.0.0
     */
    public static function fieldBlocks(): iterable
    {
        yield 'text' => ['core/field-text', '<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'];
        yield 'rich text' => [
            'core/field-rich-text',
            (object) ['type' => 'doc', 'content' => [(object) ['type' => 'text', 'text' => 'Rich value']]],
            'Rich value',
        ];
        yield 'integer' => ['core/field-integer', 42, '42'];
        yield 'decimal' => ['core/field-decimal', '19.9500', '19.9500'];
        yield 'boolean' => ['core/field-boolean', false, 'false'];
        yield 'date' => ['core/field-date', '2026-08-24', '2026-08-24'];
        yield 'date time' => ['core/field-date-time', '2026-08-24T12:30:00Z', '2026-08-24T12:30:00Z'];
        yield 'media' => [
            'core/field-media',
            (object) ['id' => 'media:hero', 'url' => 'javascript:alert(1)'],
            'media:hero',
        ];
        yield 'resource' => ['core/field-resource', (object) ['label' => 'Referenced page'], 'Referenced page'];
    }

    /**
     * Resolve each core field's `value` port from authorized projected Content and emit no active source data.
     *
     * @param   string  $type      Owner-safe core field block identifier.
     * @param   mixed   $value     Authorized projected Content value.
     * @param   string  $expected  Expected safe text fragment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('fieldBlocks')]
    public function testCoreFieldBlocksResolveAuthorizedEntryValues(
        string $type,
        mixed $value,
        string $expected,
    ): void {
        $document = self::document($type, (object) [
            'source' => (object) ['kind' => 'entry-field', 'fieldPath' => ['field']],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]);
        $html = self::renderer()->render(
            $document,
            ['node:field-node'],
            ['node:field-node' => 'field-node'],
            new StudioPreviewBindingValues((object) ['field' => $value], new stdClass()),
            'expanded',
        );

        self::assertStringContainsString($expected, $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString(' style=', $html);
    }

    /**
     * Unregistered transforms fail closed while explicit fallback and hide policies remain deterministic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnregisteredBindingOperationsNeverReadOrRenderSourceData(): void
    {
        $fallback = self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'query-reference'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'fallback',
            'fallback' => 'Safe fallback',
        ]);
        $identity = StudioPreviewIdentity::forDraft($fallback);
        $html = self::renderer()->render(
            $fallback,
            $identity['markers'],
            $identity['markerMap'],
            new StudioPreviewBindingValues((object) ['secret' => 'must-not-render'], new stdClass()),
            'expanded',
        );

        self::assertStringContainsString('Safe fallback', $html);
        self::assertStringNotContainsString('must-not-render', $html);
    }

    /**
     * Canonical preview output uses the public page renderer and an exact same-origin theme stylesheet.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalPreviewUsesThePublishedTemplateAndStrictThemePath(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'Rendered value'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $draft = new StudioPreviewDraft('default', $document);
        $snapshot = self::snapshot($draft);

        $rendered = $renderer->render(
            $snapshot,
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/canonical-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );

        self::assertStringContainsString('Rendered value', $rendered->html);
        self::assertStringContainsString('|0|core.administrator.content-editor', $rendered->html);
        self::assertStringContainsString('|#07182d', $rendered->html);
        self::assertStringContainsString(
            'href="' . StudioPreviewThemeStylesheet::HREF_PLACEHOLDER . '" data-studio-theme',
            $rendered->html,
        );
        self::assertStringContainsString('--site-accent:#0c9189;', $rendered->themeStylesheet ?? '');
        self::assertStringNotContainsString(' style=', $rendered->html);
        self::assertStringNotContainsString('<style', $rendered->html);
        self::assertCount(1, $rendered->markers);
        self::assertSame('field-node', $rendered->markerMap[$rendered->markers[0]]);
    }

    /**
     * A live public-theme change refuses an immutable preview before any stale markup is rendered.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalRendererRefusesAStalePublishedThemeLock(): void
    {
        $settingsDocument = [
            'site_name' => 'Kumwe',
            'presentation' => \Kumwe\App\Presentation\Application\SitePresentation::defaults(),
            'search_indexing_enabled' => false,
        ];
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            static function () use (&$settingsDocument): array {
                return $settingsDocument;
            },
        );
        [$renderer, $theme] = $this->canonicalRuntime($settings);
        $document = self::lockTheme(self::layoutDocument([
            self::layoutNode('section-node', 'studio.core/section'),
        ]), $theme);
        $draft = new StudioPreviewDraft('default', $document);
        $settingsDocument['presentation']['active_scheme'] = 'ocean';

        $this->expectException(StudioCompositionThemeMismatch::class);
        $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/stale-theme-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );
    }

    /**
     * Core layout output uses defaults, base intent, and active-viewport overrides in deterministic order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLayoutProjectionResolvesDefaultBaseAndViewportIntent(): void
    {
        $document = self::layoutDocument([
            self::layoutNode('section-node', 'studio.core/section', [
                'data-studio-layout-unknown' => 'surprise',
                'style' => 'display:none',
            ]),
            self::layoutNode('stack-node', 'studio.core/stack', [
                'alignment' => 'start',
                'direction' => 'inline',
                'spacing' => 'none',
                'visibility' => 'visible',
            ]),
            self::layoutNode(
                'grid-node',
                'studio.core/grid',
                [
                    'alignment' => 'end',
                    'collapse' => 'preserve',
                    'columns' => 4,
                    'spacing' => 'spacious',
                    'visibility' => 'visible',
                ],
                (object) [
                    'alignment' => (object) ['expanded' => 'center'],
                    'collapse' => (object) ['expanded' => 'wrap'],
                    'columns' => (object) ['expanded' => 12],
                    'spacing' => (object) ['expanded' => 'compact'],
                    'visibility' => (object) ['expanded' => 'hidden'],
                ],
            ),
            self::layoutNode('unknown-node', 'studio.core/unknown'),
        ]);
        $identity = StudioPreviewIdentity::forDraft($document);
        $html = self::renderer()->render(
            $document,
            $identity['markers'],
            $identity['markerMap'],
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
            'expanded',
        );

        self::assertStringContainsString(
            '<section class="studio-preview-section" data-studio-preview-marker="'
                . $identity['markers'][0]
                . '" data-studio-layout-alignment="stretch" data-studio-layout-spacing="comfortable"'
                . ' data-studio-layout-visibility="visible">',
            $html,
        );
        self::assertStringContainsString(
            '<div class="studio-preview-stack" data-studio-preview-marker="'
                . $identity['markers'][1]
                . '" data-studio-layout-alignment="start" data-studio-layout-direction="inline"'
                . ' data-studio-layout-spacing="none" data-studio-layout-visibility="visible">',
            $html,
        );
        self::assertStringContainsString(
            '<div class="studio-preview-grid" data-studio-preview-marker="'
                . $identity['markers'][2]
                . '" data-studio-layout-alignment="center" data-studio-layout-collapse="wrap"'
                . ' data-studio-layout-columns="12" data-studio-layout-spacing="compact"'
                . ' data-studio-layout-visibility="hidden">',
            $html,
        );
        self::assertStringNotContainsString(' style=', $html);
        self::assertStringNotContainsString('display:none', $html);
        self::assertStringNotContainsString('data-studio-layout-unknown', $html);
        self::assertStringContainsString('class="studio-preview-unresolved"', $html);
        self::assertSame(3, substr_count($html, 'data-studio-layout-alignment='));
    }

    /**
     * Column boundaries remain usable while every malformed core layout value is refused before markup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLayoutProjectionAcceptsColumnBoundsAndRefusesInvalidIntent(): void
    {
        foreach ([1, 12] as $columns) {
            $document = self::layoutDocument([
                self::layoutNode('columns-node', 'studio.core/columns', ['columns' => $columns]),
            ]);
            $identity = StudioPreviewIdentity::forDraft($document);
            $html = self::renderer()->render(
                $document,
                $identity['markers'],
                $identity['markerMap'],
                new StudioPreviewBindingValues(new stdClass(), new stdClass()),
                'compact',
            );
            self::assertStringContainsString('data-studio-layout-columns="' . $columns . '"', $html);
        }

        $invalid = [
            ['columns' => 0],
            ['columns' => 13],
            ['columns' => 1.5],
            ['columns' => '2'],
            ['alignment' => 'baseline'],
            ['spacing' => '" style="display:none'],
        ];
        $refusals = 0;
        foreach ($invalid as $properties) {
            $document = self::layoutDocument([
                self::layoutNode('invalid-grid', 'studio.core/grid', $properties),
            ]);
            $identity = StudioPreviewIdentity::forDraft($document);
            try {
                self::renderer()->render(
                    $document,
                    $identity['markers'],
                    $identity['markerMap'],
                    new StudioPreviewBindingValues(new stdClass(), new stdClass()),
                    'compact',
                );
            } catch (InvalidArgumentException) {
                $refusals++;
            }
        }
        $malformedResponsive = self::layoutNode('invalid-responsive', 'studio.core/grid');
        $malformedResponsive->responsive = [];
        $malformedOverride = self::layoutNode('invalid-override', 'studio.core/grid');
        $malformedOverride->responsive = (object) ['columns' => 'not-a-viewport-map'];
        $malformedProperties = self::layoutNode('invalid-properties', 'studio.core/grid');
        $malformedProperties->properties = [];
        foreach ([$malformedResponsive, $malformedOverride, $malformedProperties] as $node) {
            $document = self::layoutDocument([$node]);
            $identity = StudioPreviewIdentity::forDraft($document);
            try {
                self::renderer()->render(
                    $document,
                    $identity['markers'],
                    $identity['markerMap'],
                    new StudioPreviewBindingValues(new stdClass(), new stdClass()),
                    'compact',
                );
            } catch (InvalidArgumentException) {
                $refusals++;
            }
        }
        self::assertSame(count($invalid) + 3, $refusals);
    }

    /**
     * Fragment admission sorts the closed layout vocabulary and refuses arbitrary data or style attributes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLayoutFragmentRefusesUnknownAttributesAndValues(): void
    {
        $fragment = new StudioPreviewBlockFragment('div', 'studio-preview-grid', '', false, [
            'data-studio-layout-visibility' => 'visible',
            'data-studio-layout-columns' => '12',
            'data-studio-layout-alignment' => 'stretch',
        ]);
        self::assertSame([
            'data-studio-layout-alignment' => 'stretch',
            'data-studio-layout-columns' => '12',
            'data-studio-layout-visibility' => 'visible',
        ], $fragment->layoutAttributes);

        $invalid = [
            ['style' => 'display:none'],
            ['data-studio-layout-unknown' => 'value'],
            ['data-studio-layout-columns' => '13'],
            ['data-studio-layout-alignment' => '" onfocus="alert(1)'],
        ];
        $refusals = 0;
        foreach ($invalid as $attributes) {
            try {
                new StudioPreviewBlockFragment('div', 'studio-preview-grid', '', false, $attributes);
            } catch (InvalidArgumentException) {
                $refusals++;
            }
        }
        self::assertSame(count($invalid), $refusals);
    }

    /**
     * The canonical renderer passes the request viewport into responsive layout resolution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalRendererProjectsTheRequestedViewport(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::layoutDocument([
            self::layoutNode(
                'responsive-columns',
                'studio.core/columns',
                ['columns' => 4],
                (object) ['columns' => (object) ['compact' => 1, 'expanded' => 12]],
            ),
        ]), $theme);
        $draft = new StudioPreviewDraft('default', $document);
        $snapshot = self::snapshot($draft);
        $values = new StudioPreviewBindingValues(new stdClass(), new stdClass());

        $render = static fn (string $viewport): string => $renderer->render(
            $snapshot,
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/canonical-' . $viewport,
                $viewport,
            ),
            $values,
        )->html;

        self::assertStringContainsString('data-studio-layout-columns="1"', $render('compact'));
        self::assertStringContainsString('data-studio-layout-columns="12"', $render('expanded'));
    }

    /**
     * Build one schema-shaped Blueprint node around the binding under test.
     *
     * @param   string    $type     Owner-safe core field block identifier.
     * @param   stdClass  $binding  Canonical binding definition.
     *
     * @return  stdClass  Minimal document accepted by the structural renderer.
     *
     * @since   2.0.0
     */
    private static function document(string $type, stdClass $binding): stdClass
    {
        return (object) [
            'kind' => 'blueprint',
            'id' => 'blueprint:binding-test',
            'revision' => 'blueprint-r1',
            'roots' => [(object) [
                'id' => 'field-node',
                'type' => $type,
                'properties' => new stdClass(),
                'bindings' => (object) ['value' => $binding],
                'slots' => new stdClass(),
            ]],
        ];
    }

    /**
     * Build a minimal Blueprint around schema-shaped core layout nodes.
     *
     * @param   list<stdClass>  $nodes  Root nodes in canonical authoring order.
     *
     * @return  stdClass  Minimal document accepted by the structural renderer.
     *
     * @since   2.0.0
     */
    private static function layoutDocument(array $nodes): stdClass
    {
        return (object) [
            'kind' => 'blueprint',
            'id' => 'blueprint:layout-test',
            'revision' => 'blueprint-r1',
            'roots' => $nodes,
        ];
    }

    /**
     * Build one schema-shaped core layout node with optional responsive intent.
     *
     * @param   string                $id          Stable node identifier.
     * @param   string                $type        Canonical core layout block type.
     * @param   array<string, mixed>  $properties  Base semantic layout properties.
     * @param   stdClass|null         $responsive  Property-to-viewport overrides when present.
     *
     * @return  stdClass  Minimal layout node accepted by the structural renderer.
     *
     * @since   2.0.0
     */
    private static function layoutNode(
        string $id,
        string $type,
        array $properties = [],
        ?stdClass $responsive = null,
    ): stdClass {
        $node = (object) [
            'id' => $id,
            'type' => $type,
            'properties' => (object) $properties,
            'bindings' => new stdClass(),
            'slots' => new stdClass(),
        ];
        if ($responsive !== null) {
            $node->responsive = $responsive;
        }

        return $node;
    }

    /**
     * Reuse one live trusted session snapshot for canonical renderer tests.
     *
     * @param   StudioPreviewDraft  $draft  Exact draft the session owns.
     *
     * @return  StudioHostSessionSnapshot  Live Blueprint authority for the default site.
     *
     * @since   2.0.0
     */
    private static function snapshot(StudioPreviewDraft $draft): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            'contexts/canonical-renderer',
            'actor-renderer',
            'default',
            null,
            null,
            'administrator',
            hash('sha256', 'canonical-renderer-session'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $draft->artifactId(),
            'session-canonical-renderer',
        );

        return new StudioHostSessionSnapshot(
            $session,
            ['studio.permission/read'],
            $session->sessionGeneration,
            true,
            false,
            false,
        );
    }

    /**
     * Attach the exact live public-theme lock expected by the canonical renderer.
     *
     * @param   stdClass              $document  Minimal Blueprint document.
     * @param   StudioPublishedTheme  $theme     Live public-theme authority.
     *
     * @return  stdClass  The same Blueprint with its exact dependency lock.
     *
     * @since   2.0.0
     */
    private static function lockTheme(stdClass $document, StudioPublishedTheme $theme): stdClass
    {
        $document->dependencyLock = (object) [
            'theme' => $theme->reference(SiteContext::default())->document(),
            'blocks' => [],
        ];

        return $document;
    }

    /**
     * Compose the canonical site page renderer and the exact theme authority it re-resolves.
     *
     * @param   ?SiteSettings  $settings  Optional mutable settings source for drift tests.
     *
     * @return  array{CanonicalStudioPreviewRenderer, StudioPublishedTheme}  Renderer and shared theme authority.
     *
     * @since   2.0.0
     */
    private function canonicalRuntime(?SiteSettings $settings = null): array
    {
        if ($settings === null) {
            $settings = $this->createStub(SiteSettings::class);
            $settings->method('current')->willReturn([
                'site_name' => 'Kumwe',
                'presentation' => \Kumwe\App\Presentation\Application\SitePresentation::defaults(),
                'search_indexing_enabled' => false,
            ]);
        }
        $theme = new StudioPublishedTheme(
            $settings,
            new ActiveExtensionSet(new ExtensionContributionRegistrySet(withCore: false)),
            new StudioBuiltInThemeRelease(str_repeat('a', 64)),
        );

        return [new CanonicalStudioPreviewRenderer(
            new ContentPageRenderService(
                $settings,
                new SiteRenderer(new SiteTwigEnvironment(new ArrayLoader([
                    'page.twig' => '<!doctype html><html><head><title>{{ entry.title }}</title></head><body>'
                        . '{{ entry.body_html|raw }}|{{ presentation.css_variables|length }}|{{ surface_id }}'
                        . '|{{ presentation.theme_color }}'
                        . '</body></html>',
                ]))),
            ),
            self::renderer(),
            $theme,
            'default',
        ), $theme];
    }

    /**
     * Compose the production closed resolver and core renderer registry.
     *
     * @return  StudioCompositionMarkupRenderer  Safe renderer under test.
     *
     * @since   2.0.0
     */
    private static function renderer(): StudioCompositionMarkupRenderer
    {
        return new StudioCompositionMarkupRenderer(
            new StudioPreviewBindingResolver(),
            new CoreStudioPreviewBlockRendererRegistry(),
        );
    }
}
