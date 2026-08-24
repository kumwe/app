<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Composition;

use Kumwe\App\Extension\Contribution\CanonicalCompositionDocument;
use Kumwe\App\Extension\Contribution\CanonicalCompositionKind;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\CompositionHostBinding;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\CoreStudioCompositionContributions;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\ContributedStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionProjection;
use Kumwe\App\Studio\Application\Composition\StudioCompositionLockMismatch;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the authoring catalog retains trusted ownership and an exact immutable block lock.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCompositionContributionCatalog::class)]
#[CoversClass(StudioCompositionContributionProjection::class)]
#[CoversClass(StudioCompositionLockMismatch::class)]
#[CoversClass(CoreStudioCompositionContributions::class)]
#[UsesClass(CanonicalCompositionDocument::class)]
#[UsesClass(CompositionHostBinding::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
#[UsesClass(OwnedRuntimeContributionRegistry::class)]
#[UsesClass(StudioPreviewRendererContribution::class)]
#[UsesClass(ContributedStudioPreviewBlockRendererRegistry::class)]
#[UsesClass(CoreStudioPreviewBlockRendererRegistry::class)]
final class StudioCompositionContributionCatalogTest extends TestCase
{
    /**
     * The exact host-supported catalog, rather than a copied list, becomes the initial lock.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testSupportedBlocksProduceADeterministicTrustedLock(): void
    {
        $catalog = new StudioCompositionContributionCatalog(new ExtensionContributionRegistrySet());

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
        $catalog = new StudioCompositionContributionCatalog(new ExtensionContributionRegistrySet());
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
        $catalog = new StudioCompositionContributionCatalog(new ExtensionContributionRegistrySet());

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
        $catalog = new StudioCompositionContributionCatalog(new ExtensionContributionRegistrySet());

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
        $catalog = new StudioCompositionContributionCatalog(new ExtensionContributionRegistrySet());
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
            (string) file_get_contents(
                dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/fixtures/block.grid.example.json',
            ),
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
            'acme.shop.renderer.grid',
            'acme.shop.catalog.edit',
        );
        $capability = new CapabilityDefinition(
            'acme.shop.catalog.edit',
            'Edit shop catalog',
            'Offer the shop catalog composition blocks to an author.',
        );
        $declared = new ManifestContributionSet(
            $owner,
            spiVersion: ManifestContributionSet::CANONICAL_COMPOSITION_SPI_VERSION,
            capabilities: [$capability],
            canonicalDocuments: [$canonical],
            compositionHostBindings: [$binding],
        );
        $registries = new ExtensionContributionRegistrySet();
        $registrar = $registries->registrar($owner, $declared);
        $registrar->capability($capability);
        $registrar->canonicalCompositionDocument($canonical);
        $registrar->complete();
        $withoutRuntime = new StudioCompositionContributionCatalog($registries);
        $unsupported = $withoutRuntime->project([], ['core.renderer/field', 'core.renderer/layout']);
        self::assertNotContains('acme.shop/grid', self::blockTypes($unsupported));
        self::assertArrayNotHasKey('acme.shop/grid', $unsupported->blockRenderers);

        $runtimeDefinition = new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding);
        $registries->studioPreviewRenderers()->register(
            $owner,
            $runtimeDefinition,
            new class implements StudioPreviewBlockRenderer {
                /**
                 * Return one inert safe fragment for the exact contributed block.
                 *
                 * @param   StudioPreviewBlock          $block     Admitted block input.
                 * @param   StudioPreviewBindingResult  $binding   Authorized binding result.
                 * @param   string                      $viewport  Active semantic viewport.
                 *
                 * @return  StudioPreviewBlockFragment  Closed safe fragment.
                 *
                 * @since   2.0.0
                 */
                public function render(
                    StudioPreviewBlock $block,
                    StudioPreviewBindingResult $binding,
                    string $viewport,
                ): StudioPreviewBlockFragment {
                    return new StudioPreviewBlockFragment('div', 'acme-shop-grid', '');
                }
            },
        );
        $runtime = new ContributedStudioPreviewBlockRendererRegistry(
            new CoreStudioPreviewBlockRendererRegistry(),
            $registries->studioPreviewRenderers(),
        );
        $catalog = new StudioCompositionContributionCatalog($registries, $runtime);

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
}
