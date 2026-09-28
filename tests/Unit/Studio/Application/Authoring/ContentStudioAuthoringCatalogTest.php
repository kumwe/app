<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Authoring;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringCatalog;
use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\App\Tests\Support\TrustFencedStudioPreviewRenderers;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioContractResources;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;

/**
 * Proves the authoring catalogue's block locks are the pinned core coordinates plus the App's active
 * contributions, and that a contribution declaring a core block at another coordinate is refused rather
 * than silently shadowing what the pinned Studio release compiles in.
 *
 * @since  2.0.0
 */
#[CoversClass(ContentStudioAuthoringCatalog::class)]
final class ContentStudioAuthoringCatalogTest extends TestCase
{
    use TrustFencedStudioPreviewRenderers;

    /**
     * The core coordinates come first and an active extension block joins them exactly once, while the core
     * blocks never become contribution payloads because the pinned release compiles them in already.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreCoordinatesAndActiveContributionsFormTheLocks(): void
    {
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        self::contributeBlock($registries, 'acme.shop/grid', '1.0.0');
        $catalog = self::catalog($registries);

        $locks = $catalog->blockLocks();
        $types = array_map(static fn (stdClass $lock): string => $lock->type, $locks);
        self::assertSame($types, array_values(array_unique($types)));
        self::assertContains('studio.core/accordion', $types);
        self::assertContains('acme.shop/grid', $types, json_encode($types, JSON_THROW_ON_ERROR));
        $payloadTypes = array_map(
            static fn (stdClass $payload): string => $payload->type ?? '',
            array_values(array_filter(
                $catalog->contributionPayloads(),
                static fn (stdClass $payload): bool => ($payload->kind ?? null) === 'block-definition',
            )),
        );
        self::assertNotContains('studio.core/accordion', $payloadTypes);
        self::assertNotContains('studio.core/section', $payloadTypes);
    }

    /**
     * One authoring operation derives every catalogue member from a single projection, even when the
     * owner's generation is withdrawn while it runs; outside an operation each member re-projects.
     *
     * This is the property the deployment document and `authoring/start` rely on: payloads, locks,
     * dependencies and generation must never mix a projection that saw the renderer with one that did not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOneOperationDerivesEveryMemberFromASingleProjection(): void
    {
        $current = true;
        $execution = $this->createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturnCallback(static function () use (&$current): bool {
            return $current;
        });
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        self::contributeBlock($registries, 'acme.shop/grid', '1.0.0', $execution);
        $catalog = self::catalog($registries);
        $trusted = $catalog->contributionGeneration();
        $trustedLocks = self::types($catalog->renderableBlockLocks());
        self::assertContains('acme.shop/grid', $trustedLocks);

        [$generation, $locks] = $catalog->consistently(
            static function () use ($catalog, &$current): array {
                $catalog->contributionPayloads();
                $current = false;

                return [$catalog->contributionGeneration(), self::types($catalog->renderableBlockLocks())];
            },
        );

        self::assertSame($trusted, $generation, 'A withdrawal inside the operation must not split its projection.');
        self::assertSame($trustedLocks, $locks);
        self::assertNotSame($trusted, $catalog->contributionGeneration(), 'The next operation must see it.');
        self::assertNotContains('acme.shop/grid', self::types($catalog->renderableBlockLocks()));
    }

    /**
     * The App's own core contribution declaring a core block at a coordinate the pinned release does not
     * compile in contradicts the immutable lock and is refused before any lock is handed out: the two
     * App-owned records must agree, and drift between them is a build failure, not a silent shadow.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACoreBlockDeclaredAtAnotherCoordinateThanThePinnedReleaseIsRefused(): void
    {
        $path = dirname(__DIR__, 5) . '/resources/studio-contract/core-catalog.json';
        $record = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $drifted = false;
        foreach ($record['blocks'] as $index => $block) {
            if (($block['type'] ?? null) === 'studio.core/section') {
                $record['blocks'][$index]['version'] = '9.9.9';
                $drifted = true;
            }
        }
        self::assertTrue($drifted, 'The pinned core catalogue must list the section block.');
        $file = tempnam(sys_get_temp_dir(), 'kumwe-core-catalog-');
        self::assertIsString($file);
        self::assertNotFalse(file_put_contents($file, json_encode($record, JSON_THROW_ON_ERROR)));

        try {
            $catalog = self::catalog(
                new ExtensionContributionRegistrySet(
                    new DeterministicCanonicalEncoder(),
                    new SdkFieldConfigurationAdmission(),
                ),
                $file,
            );
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('studio.core/section is declared at a coordinate');
            $catalog->blockLocks();
        } finally {
            unlink($file);
        }
    }

    /**
     * Compose the authoring catalogue over the given registries and a core catalogue record.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Live contribution registries.
     * @param   ?string                           $record      Core catalogue path; the pinned record by default.
     *
     * @return  ContentStudioAuthoringCatalog  Catalogue under test.
     *
     * @since   2.0.0
     */
    private static function catalog(
        ExtensionContributionRegistrySet $registries,
        ?string $record = null,
    ): ContentStudioAuthoringCatalog {
        $runtime = new StudioBlockRendererRuntime($registries, new StudioContentFieldBlockRenderer());

        return new ContentStudioAuthoringCatalog(
            new StudioCompositionContributionCatalog($registries, $runtime),
            StudioCoreCatalog::fromFile(
                $record ?? dirname(__DIR__, 5) . '/resources/studio-contract/core-catalog.json',
                '0.1.0-beta.3',
            ),
            $runtime,
        );
    }

    /**
     * Register one extension block definition with a preview renderer so it is an active contribution.
     *
     * @param   ExtensionContributionRegistrySet  $registries  Registries to contribute into.
     * @param   string                            $type        Block type the contribution declares.
     * @param   string                            $version     Block version the contribution declares.
     * @param   ?ExtensionExecutionGate           $execution   Generation gate the owner's renderer is
     *          fenced by; null for one that stays current.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function contributeBlock(
        ExtensionContributionRegistrySet $registries,
        string $type,
        string $version,
        ?ExtensionExecutionGate $execution = null,
    ): void {
        $owner = ContributionOwner::extension('acme/shop');
        $document = json_decode(
            StudioContractResources::testkitBytes('fixtures/block.grid.example.json'),
            false,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);
        $document->type = $type;
        $document->version = $version;
        $document->owner = (object) ['id' => 'acme.shop/blocks', 'version' => '1.0.0'];
        $document->rendererRequirements = [
            (object) ['surface' => 'web', 'capability' => 'acme.shop/web-grid', 'versions' => '^1.0.0'],
            (object) ['surface' => 'preview', 'capability' => 'acme.shop/preview-grid', 'versions' => '^1.0.0'],
        ];
        $canonical = new CanonicalCompositionDocument(
            CanonicalCompositionKind::BlockDefinition,
            CanonicalJson::stringify($document),
        );
        $binding = new CompositionHostBinding(
            CanonicalCompositionKind::BlockDefinition,
            $type,
            'acme.shop/grid-preview',
            'acme.shop.catalog.edit',
        );
        $registries->canonicalCompositionDocuments()->register($owner, $canonical);
        $registries->compositionHostBindings()->register($owner, $binding);
        $preview = new class implements StudioPreviewBlockRenderer {
            /**
             * Emit a fixed placeholder fragment regardless of block, binding or viewport.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed input.
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
            new StudioPreviewRendererContribution($owner, '1.0.0', $canonical, $binding),
            self::trustFencedPreviewRenderer($preview, 'acme/shop', $execution),
        );
    }

    /**
     * Project block locks to their type names, in catalogue order.
     *
     * @param   list<stdClass>  $locks  `{type, version, revision}` locks.
     *
     * @return  list<mixed>  The type of each lock.
     *
     * @since   2.0.0
     */
    private static function types(array $locks): array
    {
        return array_map(static fn (stdClass $lock): mixed => $lock->type ?? null, $locks);
    }
}
