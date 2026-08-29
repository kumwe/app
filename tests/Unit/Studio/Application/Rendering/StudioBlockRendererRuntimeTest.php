<?php

declare(strict_types=1);

namespace KumweTest\Unit\Studio\Application\Rendering;

use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Studio\Application\Rendering\StudioRenderResultAdmission;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Render\BindingResolution;
use Kumwe\Producer\Render\BlockRenderer;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\Enhancement;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderPolicy;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderResult;
use Kumwe\Producer\Render\RenderState;
use Kumwe\Producer\Schema\StudioContractResources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

#[CoversClass(StudioBlockRendererRuntime::class)]
#[CoversClass(StudioContentFieldBlockRenderer::class)]
#[CoversClass(StudioRenderResultAdmission::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
final class StudioBlockRendererRuntimeTest extends TestCase
{
    public function testItBindsCoreCoordinatesDirectlyAndReturnsCompleteProducerOutput(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());
        $registry = $runtime->registry();
        self::assertTrue($registry->supports(new BlockCoordinate(
            'studio.core/section',
            '1.0.0',
            'layout-section-r1',
        )));
        self::assertTrue($registry->supports(new BlockCoordinate(
            'core/field-text',
            '1.0.0',
            'core-block-r1',
        )));

        $document = (object) [
            'dependencyLock' => (object) ['blocks' => [
                (object) [
                    'type' => 'studio.core/section',
                    'version' => '1.0.0',
                    'revision' => 'layout-section-r1',
                ],
                (object) [
                    'type' => 'core/field-text',
                    'version' => '1.0.0',
                    'revision' => 'core-block-r1',
                ],
            ]],
            'roots' => [(object) [
                'id' => 'root',
                'type' => 'studio.core/section',
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => new stdClass(),
                'slots' => (object) ['content' => [(object) [
                    'id' => 'field',
                    'type' => 'core/field-text',
                    'version' => '1.0.0',
                    'properties' => new stdClass(),
                    'bindings' => new stdClass(),
                    'slots' => new stdClass(),
                ]]],
            ]],
        ];
        $result = (new CompositionRenderer($registry))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: static fn (stdClass $node, string $port): BindingResolution =>
                    $node->id === 'field' && $port === 'value'
                        ? BindingResolution::available('<Current>')
                        : BindingResolution::unavailable(),
                policy: RenderPolicy::RequireRegistered,
            ),
        );

        self::assertStringContainsString('data-studio-block="section"', $result->html);
        self::assertStringContainsString('data-studio-layout="section"', $result->html);
        self::assertStringContainsString('&lt;Current&gt;', $result->html);
        self::assertStringNotContainsString('<Current>', $result->html);
        self::assertNotSame('', $result->css);
        self::assertSame([], $result->enhancements);
    }

    public function testEachRegistryReflectsCurrentOwnerAuthorityWithoutSnapshotReuse(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());
        $coordinate = new BlockCoordinate('core/field-text', '1.0.0', 'core-block-r1');

        self::assertTrue($runtime->registry()->supports($coordinate));
        $registries->canonicalCompositionDocuments()->remove(ContributionOwner::core());
        self::assertFalse($runtime->registry()->supports($coordinate));
    }

    public function testHiddenBindingIsRetainedOnlyAsProducerWrapperState(): void
    {
        $runtime = new StudioBlockRendererRuntime(
            new ExtensionContributionRegistrySet(),
            new StudioContentFieldBlockRenderer(),
        );
        $document = (object) [
            'dependencyLock' => (object) ['blocks' => [(object) [
                'type' => 'core/field-text',
                'version' => '1.0.0',
                'revision' => 'core-block-r1',
            ]]],
            'roots' => [(object) [
                'id' => 'hidden-field',
                'type' => 'core/field-text',
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => new stdClass(),
                'slots' => new stdClass(),
            ]],
        ];
        $result = (new CompositionRenderer($runtime->registry()))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: static fn (): BindingResolution => BindingResolution::hidden(),
                policy: RenderPolicy::RequireRegistered,
            ),
        );

        self::assertStringContainsString('data-studio-node="hidden-field"', $result->html);
        self::assertStringContainsString(' hidden>', $result->html);
        self::assertStringNotContainsString('hidden-field</', $result->html);
    }

    public function testAppRefusesAProducerEnhancementInsteadOfDiscardingIt(): void
    {
        $result = new RenderResult(
            '<div>Safe baseline</div>',
            '[data-studio-block]{display:block}',
            [new Enhancement('motion', 'node-one', 's6e6f64652d6f6e65')],
        );

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('The App has no canonical Producer enhancement runtime.');
        StudioRenderResultAdmission::assertSupported($result);
    }

    public function testExtensionCoordinateReturnsTheExactDirectProducerRenderer(): void
    {
        [$registries, $coordinate, $renderer] = self::extensionRuntime();
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

        self::assertSame($renderer, $runtime->registry()->rendererFor($coordinate));
    }

    public function testDuplicateTrustedExtensionCoordinateFailsClosed(): void
    {
        [$registries] = self::extensionRuntime();
        $surface = $registries->canonicalCompositionDocuments();
        $entriesProperty = new ReflectionProperty($surface, 'entries');
        $entries = $entriesProperty->getValue($surface);
        self::assertIsArray($entries);
        $first = reset($entries);
        self::assertIsArray($first);
        $entries['hostile duplicate key'] = $first;
        $entriesProperty->setValue($surface, $entries);

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('A trusted extension block coordinate is ambiguous.');
        (new StudioBlockRendererRuntime(
            $registries,
            new StudioContentFieldBlockRenderer(),
        ))->registry();
    }

    /**
     * @return array{ExtensionContributionRegistrySet, BlockCoordinate, BlockRenderer}
     */
    private static function extensionRuntime(): array
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
        $renderer = new class implements BlockRenderer {
            public function render(stdClass $node, string $scope, RenderState $state): string
            {
                unset($node, $scope, $state);

                return '<p>Exact extension renderer</p>';
            }
        };
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registries->canonicalCompositionDocuments()->register($owner, $canonical);
        $registries->compositionHostBindings()->register($owner, $binding);
        $registries->studioPreviewRenderers()->register(
            $owner,
            new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding),
            $renderer,
        );

        return [
            $registries,
            new BlockCoordinate('acme.shop/grid', '1.0.0', 'grid-block-r1'),
            $renderer,
        ];
    }
}
