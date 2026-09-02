<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\CoreStudioCompositionContributions;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\TrustEnforcingStudioPreviewBlockRenderer;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionProjection;
use Kumwe\App\Studio\Application\Composition\StudioCompositionLockMismatch;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioContractResources;
use Kumwe\App\Tests\Support\TrustFencedStudioPreviewRenderers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Proves the authoring catalog retains trusted ownership and an exact immutable block lock.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCompositionContributionCatalog::class)]
#[CoversClass(StudioCompositionContributionProjection::class)]
#[CoversClass(StudioCompositionLockMismatch::class)]
#[CoversClass(CoreStudioCompositionContributions::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
#[UsesClass(OwnedRuntimeContributionRegistry::class)]
#[UsesClass(StudioPreviewRendererContribution::class)]
#[UsesClass(StudioBlockRendererRuntime::class)]
#[UsesClass(StudioContentFieldBlockRenderer::class)]
#[UsesClass(TrustEnforcingStudioPreviewBlockRenderer::class)]
#[UsesClass(TrustStore::class)]
final class StudioCompositionContributionCatalogTest extends TestCase
{
    use TrustFencedStudioPreviewRenderers;

    /**
     * The exact host-supported catalog, rather than a copied list, becomes the initial lock.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testSupportedBlocksProduceADeterministicTrustedLock(): void
    {
        $catalog = self::catalog(new ExtensionContributionRegistrySet());

        $first = $catalog->project([], ['core.renderer/field', 'core.renderer/layout']);
        $second = $catalog->project([], ['core.renderer/layout', 'core.renderer/field']);

        self::assertEquals($first->blockLocks, $second->blockLocks);
        self::assertCount(13, $first->blockLocks);
        self::assertSame(
            [
                'core/field-boolean',
                'core/field-date',
                'core/field-date-time',
                'core/field-decimal',
                'core/field-integer',
                'core/field-media',
                'core/field-resource',
                'core/field-rich-text',
                'core/field-text',
                'studio.core/columns',
                'studio.core/grid',
                'studio.core/section',
                'studio.core/stack',
            ],
            array_map(static fn (\stdClass $lock): string => $lock->type, $first->blockLocks),
        );
        self::assertSame('core', $first->owners['block-definition studio.core/section']);
        self::assertSame('core.renderer/layout', $first->blockRenderers['studio.core/section']);
    }

    /**
     * An existing Blueprint intersects the active catalog without widening its palette or patterns.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testExistingLocksIntersectBlocksAndPatternsExactly(): void
    {
        $catalog = self::catalog(new ExtensionContributionRegistrySet());
        $exactSection = (object) [
            'type' => 'studio.core/section',
            'version' => '1.0.0',
            'revision' => 'layout-section-r1',
        ];

        $exact = $catalog->project([], ['core.renderer/field', 'core.renderer/layout'], [$exactSection]);
        self::assertSame(['studio.core/section'], self::blockTypes($exact));
        self::assertContains('core/pattern-empty-section', self::documentIds($exact, 'pattern'));
    }

    /**
     * An active renderer-supported definition cannot replace a locked revision under the same type.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAnActiveBlockWithAMismatchedLockedRevisionFailsProjection(): void
    {
        $catalog = self::catalog(new ExtensionContributionRegistrySet());

        $this->expectException(StudioCompositionLockMismatch::class);
        $this->expectExceptionMessage('studio.core/section');
        $catalog->project([], ['core.renderer/field', 'core.renderer/layout'], [(object) [
            'type' => 'studio.core/section',
            'version' => '1.0.0',
            'revision' => 'layout-section-r0',
        ]]);
    }

    /**
     * An active renderer-supported definition cannot replace a locked version under the same type.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAnActiveBlockWithAMismatchedLockedVersionFailsProjection(): void
    {
        $catalog = self::catalog(new ExtensionContributionRegistrySet());

        $this->expectException(StudioCompositionLockMismatch::class);
        $this->expectExceptionMessage('studio.core/section');
        $catalog->project([], ['core.renderer/field', 'core.renderer/layout'], [(object) [
            'type' => 'studio.core/section',
            'version' => '0.9.0',
            'revision' => 'layout-section-r1',
        ]]);
    }

    /**
     * A locked type with no active definition remains an unresolved contribution instead of a boot failure.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAMissingLockedDefinitionRemainsOmittedAndRepresentable(): void
    {
        $catalog = self::catalog(new ExtensionContributionRegistrySet());
        $missing = $catalog->project([], ['core.renderer/field', 'core.renderer/layout'], [(object) [
            'type' => 'withdrawn.vendor/card',
            'version' => '1.0.0',
            'revision' => 'card-r1',
        ]]);

        self::assertSame([], self::blockTypes($missing));
        self::assertNotEmpty(self::documentIds($missing, 'field-adapter'));
    }

    /**
     * Provisioning is deployment-derived while two actors receive capability-filtered authoring catalogs.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testTwoActorsShareADeploymentLockWithoutWideningAuthoringAvailability(): void
    {
        $owner = ContributionOwner::extension('acme/shop');
        $document = json_decode(
            StudioContractResources::testkitBytes('fixtures/block.grid.example.json'),
            false,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(\stdClass::class, $document);
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
            'acme.shop/grid-preview',
            'acme.shop.catalog.edit',
        );
        $registries = new ExtensionContributionRegistrySet();
        $registries->canonicalCompositionDocuments()->register($owner, $canonical);
        $registries->compositionHostBindings()->register($owner, $binding);
        $withoutRuntime = self::catalog($registries);
        $unsupported = $withoutRuntime->project([], ['core.renderer/field', 'core.renderer/layout']);
        self::assertNotContains('acme.shop/grid', self::blockTypes($unsupported));
        self::assertArrayNotHasKey('acme.shop/grid', $unsupported->blockRenderers);

        $runtimeDefinition = new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding);
        $preview = new class implements StudioPreviewBlockRenderer {
            /**
             * Emit a fixed grid placeholder fragment regardless of block, binding or viewport.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed grid input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Constant placeholder fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);

                return new StudioPreviewBlockFragment('div', 'acme-shop-grid', '');
            }
        };
        $registries->studioPreviewRenderers()->register(
            $owner,
            $runtimeDefinition,
            self::trustFencedPreviewRenderer($preview, 'acme/shop'),
        );
        $catalog = self::catalog($registries);

        $restricted = $catalog->project([], [
            'core.renderer/field',
            'core.renderer/layout',
        ]);
        self::assertNotContains('acme.shop/grid', self::blockTypes($restricted));
        self::assertArrayNotHasKey('block-definition acme.shop/grid', $restricted->owners);
        self::assertSame('acme.shop/preview-grid', $restricted->blockRenderers['acme.shop/grid']);
        $extensionLock = array_values(array_filter(
            $restricted->blockLocks,
            static fn (\stdClass $lock): bool => $lock->type === 'acme.shop/grid',
        ));
        self::assertCount(1, $extensionLock);

        $privileged = $catalog->project(
            ['acme.shop.catalog.edit' => true],
            ['core.renderer/field', 'core.renderer/layout'],
            $restricted->blockLocks,
        );
        self::assertContains('acme.shop/grid', self::blockTypes($privileged));
        self::assertSame('acme/shop', $privileged->owners['block-definition acme.shop/grid']);
        self::assertEquals($restricted->blockLocks, $privileged->blockLocks);
        self::assertSame($restricted->blockRenderers, $privileged->blockRenderers);

        $registries->remove($owner);
        $withdrawn = $catalog->project([], [
            'core.renderer/field',
            'core.renderer/layout',
        ], $extensionLock);

        self::assertNotContains('acme.shop/grid', self::blockTypes($withdrawn));
        self::assertArrayNotHasKey('block-definition acme.shop/grid', $withdrawn->owners);
        self::assertArrayNotHasKey('acme.shop/grid', $withdrawn->blockRenderers);
    }

    /**
     * Return the exact block types present in one projected authoring catalogue.
     *
     * @param   StudioCompositionContributionProjection  $projection  Active contribution snapshot.
     *
     * @return  list<string>  Exact projected block types.
     *
     * @since   2.0.0
     */
    private static function blockTypes(StudioCompositionContributionProjection $projection): array
    {
        return array_values(array_map(
            static fn (\stdClass $document): string => $document->type,
            array_filter(
                $projection->documents,
                static fn (\stdClass $document): bool => ($document->kind ?? null) === 'block-definition',
            ),
        ));
    }

    /**
     * Return exact identifiers for one canonical contribution kind.
     *
     * @param   StudioCompositionContributionProjection  $projection  Active contribution snapshot.
     * @param   string                                    $kind        Canonical document kind.
     *
     * @return  list<string>  Exact projected document identifiers.
     *
     * @since   2.0.0
     */
    private static function documentIds(
        StudioCompositionContributionProjection $projection,
        string $kind,
    ): array {
        return array_values(array_map(
            static fn (\stdClass $document): string => $document->id,
            array_filter(
                $projection->documents,
                static fn (\stdClass $document): bool => ($document->kind ?? null) === $kind,
            ),
        ));
    }

    /**
     * Build the catalogue under test over the given registries and a real block renderer runtime.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Live contribution registries to project from.
     *
     * @return  StudioCompositionContributionCatalog  Catalogue under test.
     *
     * @since   2.0.0
     */
    private static function catalog(ExtensionContributionRegistrySet $registries): StudioCompositionContributionCatalog
    {
        return new StudioCompositionContributionCatalog(
            $registries,
            new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer()),
        );
    }
}
