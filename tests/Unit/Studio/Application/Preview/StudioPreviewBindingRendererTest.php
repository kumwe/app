<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\ActiveExtensionSet;
use Kumwe\App\Presentation\ContentPageRenderService;
use Kumwe\App\Presentation\SiteRenderer;
use Kumwe\App\Presentation\Twig\SiteTwigEnvironment;
use Kumwe\App\Site\Application\SiteSettings;
use Kumwe\App\Studio\Application\Composition\StudioBuiltInThemeRelease;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Composition\StudioPublishedTheme;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewStylesheet;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Presentation\Preview\CanonicalStudioPreviewRenderer;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderPolicy;
use Kumwe\Producer\Render\RenderState;
use Kumwe\Producer\Schema\StudioContractResources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Twig\Loader\ArrayLoader;

#[CoversClass(CanonicalStudioPreviewRenderer::class)]
#[CoversClass(StudioPreviewBindingResolver::class)]
#[CoversClass(StudioPreviewBindingValues::class)]
#[CoversClass(StudioPreviewStylesheet::class)]
#[UsesClass(StudioBlockRendererRuntime::class)]
#[UsesClass(StudioContentFieldBlockRenderer::class)]
final class StudioPreviewBindingRendererTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, string}> */
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

    #[DataProvider('fieldBlocks')]
    public function testCoreFieldBlocksResolveOnlyAuthorizedValues(
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
        $html = self::render(
            $document,
            new StudioPreviewBindingValues((object) ['field' => $value], new stdClass()),
        );

        self::assertStringContainsString($expected, $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString(' style=', $html);
    }

    public function testUnregisteredBindingOperationsApplyTheDeclaredFallback(): void
    {
        $document = self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'query-reference'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'fallback',
            'fallback' => 'Safe fallback',
        ]);
        $html = self::render(
            $document,
            new StudioPreviewBindingValues((object) ['secret' => 'must-not-render'], new stdClass()),
        );

        self::assertStringContainsString('Safe fallback', $html);
        self::assertStringNotContainsString('must-not-render', $html);
    }

    public function testCanonicalPreviewConsumesProducerHtmlCssAndMarkersThroughOneStylesheet(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'Rendered value'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $draft = new StudioPreviewDraft('default', $document);
        $rendered = $renderer->render(
            self::snapshot($draft),
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
        self::assertStringContainsString('data-studio-block="field-text"', $rendered->html);
        self::assertStringContainsString('|0|core.administrator.content-editor', $rendered->html);
        self::assertStringContainsString(
            'href="' . StudioPreviewStylesheet::HREF_PLACEHOLDER . '" data-studio-composition',
            $rendered->html,
        );
        self::assertStringContainsString('[data-studio-block]', $rendered->stylesheet ?? '');
        self::assertStringContainsString('--site-accent:#0c9189;', $rendered->stylesheet ?? '');
        self::assertStringNotContainsString('<style', $rendered->html);
        self::assertCount(1, $rendered->markers);
        self::assertSame('field-node', $rendered->markerMap[$rendered->markers[0]]);
    }

    public function testStylesheetActivationRequiresExactSameOriginUrlAndInventory(): void
    {
        $placeholder = sprintf('href="%s"', StudioPreviewStylesheet::HREF_PLACEHOLDER);
        $html = '<link ' . $placeholder . ' data-studio-composition>';
        $href = '/administrator/studio/preview/styles.css?grant=preview-1';

        self::assertSame(
            '<link href="' . $href . '" data-studio-composition>',
            StudioPreviewStylesheet::activate($html, $href, true),
        );
        self::assertSame('<main>Preview</main>', StudioPreviewStylesheet::activate(
            '<main>Preview</main>',
            $href,
            false,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Studio preview stylesheet URL is invalid.');
        StudioPreviewStylesheet::activate($html, 'https://example.test/styles.css', true);
    }

    public function testCanonicalRendererRefusesAStalePublishedThemeLock(): void
    {
        $settingsDocument = self::settingsDocument();
        $settings = $this->createStub(SiteSettings::class);
        $settings->method('current')->willReturnCallback(
            static function () use (&$settingsDocument): array {
                return $settingsDocument;
            },
        );
        [$renderer, $theme] = $this->canonicalRuntime($settings);
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'value'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
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

    public function testCanonicalRendererRefusesAnUnregisteredRevisionWithoutDraftFallback(): void
    {
        [$renderer, $theme] = $this->canonicalRuntime();
        $document = self::lockTheme(self::document('core/field-text', (object) [
            'source' => (object) ['kind' => 'static-value', 'value' => 'must not render'],
            'transforms' => [],
            'onNull' => 'empty',
            'onError' => 'error',
        ]), $theme);
        $document->dependencyLock->blocks[0]->revision = 'unregistered-revision';
        $draft = new StudioPreviewDraft('default', $document);

        $this->expectException(RenderException::class);
        $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/unregistered-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );
    }

    public function testCanonicalPreviewRefusesProducerEnhancementsUntilOneCanonicalRuntimeExists(): void
    {
        $enhancingRenderer = new class implements BlockRenderer {
            public function render(stdClass $node, string $scope, RenderState $state): string
            {
                $state->enhance('motion', $node, $scope);

                return '<p>Safe non-JavaScript baseline</p>';
            }
        };
        [$renderer, $theme] = $this->canonicalRuntime(runtime: self::extensionRuntime($enhancingRenderer));
        $document = self::lockTheme(self::document('acme.shop/grid', new stdClass()), $theme);
        $document->dependencyLock->blocks[0]->revision = 'grid-block-r1';
        $document->roots[0]->properties = (object) ['columns' => 3, 'collapse' => 'stack'];
        $document->roots[0]->bindings = new stdClass();
        $document->roots[0]->slots = (object) ['items' => []];
        $draft = new StudioPreviewDraft('default', $document);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('The App has no canonical Producer enhancement runtime.');
        $renderer->render(
            self::snapshot($draft),
            $draft,
            new StudioPreviewRenderRequest(
                $draft->artifactId(),
                $draft->digest(),
                $draft->revision(),
                'requests/enhancing-renderer',
                'expanded',
            ),
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );
    }

    private static function document(string $type, stdClass $binding): stdClass
    {
        return (object) [
            'kind' => 'blueprint',
            'id' => 'blueprint:binding-test',
            'version' => '1.0.0',
            'revision' => 'blueprint-r1',
            'dependencyLock' => (object) ['blocks' => [(object) [
                'type' => $type,
                'version' => '1.0.0',
                'revision' => 'core-block-r1',
            ]]],
            'roots' => [(object) [
                'id' => 'field-node',
                'type' => $type,
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => (object) ['value' => $binding],
                'slots' => new stdClass(),
            ]],
        ];
    }

    private static function render(stdClass $document, StudioPreviewBindingValues $values): string
    {
        $identity = StudioPreviewIdentity::forDraft($document);
        $resolver = new StudioPreviewBindingResolver();

        return (new CompositionRenderer(self::runtime()->registry()))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: static fn (stdClass $node, string $port) => $resolver->resolve(
                    $node,
                    $port,
                    $values,
                ),
                previewMarkerMap: $identity['markerMap'],
                policy: RenderPolicy::RequireRegistered,
            ),
        )->html;
    }

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

    private static function lockTheme(stdClass $document, StudioPublishedTheme $theme): stdClass
    {
        $document->dependencyLock->theme = $theme->reference(SiteContext::default())->document();

        return $document;
    }

    /** @return array{CanonicalStudioPreviewRenderer, StudioPublishedTheme} */
    private function canonicalRuntime(
        ?SiteSettings $settings = null,
        ?StudioBlockRendererRuntime $runtime = null,
    ): array {
        if ($settings === null) {
            $settings = $this->createStub(SiteSettings::class);
            $settings->method('current')->willReturn(self::settingsDocument());
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
            $runtime ?? self::runtime(),
            new StudioPreviewBindingResolver(),
            $theme,
            'default',
        ), $theme];
    }

    private static function runtime(): StudioBlockRendererRuntime
    {
        return new StudioBlockRendererRuntime(
            new ExtensionContributionRegistrySet(),
            new StudioContentFieldBlockRenderer(),
        );
    }

    private static function extensionRuntime(BlockRenderer $renderer): StudioBlockRendererRuntime
    {
        $document = json_decode(
            StudioContractResources::testkitBytes('fixtures/block.grid.example.json'),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);
        $document->type = 'acme.shop/grid';
        $document->owner = (object) ['id' => 'acme.shop/blocks', 'version' => '1.0.0'];
        $document->rendererRequirements = [
            (object) [
                'surface' => 'web',
                'capability' => 'acme.shop/web-grid',
                'versions' => '^1.0.0',
            ],
            (object) [
                'surface' => 'preview',
                'capability' => 'acme.shop/preview-grid',
                'versions' => '^1.0.0',
            ],
        ];
        $canonical = new CanonicalCompositionDocument(
            CanonicalCompositionKind::BlockDefinition,
            CanonicalJson::stringify($document),
        );
        $binding = new CompositionHostBinding(
            CanonicalCompositionKind::BlockDefinition,
            'acme.shop/grid',
            'acme.shop.renderer.grid',
        );
        $owner = ContributionOwner::extension('acme/shop');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registries->canonicalCompositionDocuments()->register($owner, $canonical);
        $registries->compositionHostBindings()->register($owner, $binding);
        $registries->studioPreviewRenderers()->register(
            $owner,
            new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding),
            $renderer,
        );

        return new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());
    }

    /** @return array<string, mixed> */
    private static function settingsDocument(): array
    {
        $presentation = \Kumwe\App\Presentation\Application\SitePresentation::defaults();
        $presentation['schemes']['default']['variables']['--site-accent'] = '#0c9189';

        return [
            'site_name' => 'Kumwe',
            'presentation' => $presentation,
            'search_indexing_enabled' => false,
        ];
    }
}
