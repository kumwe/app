<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\CanonicalCompositionDocument;
use Kumwe\App\Extension\Contribution\CanonicalCompositionKind;
use Kumwe\App\Extension\Contribution\CompositionHostBinding;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\App\Extension\Runtime\ContributedStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockReference;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(ContributedStudioPreviewBlockRendererRegistry::class)]
#[CoversClass(StudioPreviewRendererContribution::class)]
#[CoversClass(StudioPreviewBlock::class)]
#[UsesClass(OwnedRuntimeContributionRegistry::class)]
/**
 * Pins exact extension renderer resolution and its fail-closed paths without lifecycle infrastructure.
 *
 * @since  2.0.0
 */
final class ContributedStudioPreviewBlockRendererRegistryTest extends TestCase
{
    /**
     * Resolve one exact executable entry and refuse every coordinate or implementation failure around it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExactExecutableEntryRendersAndEveryMismatchStaysUnresolved(): void
    {
        $owner = ContributionOwner::extension('kumwe/contract-manifest-six');
        $definition = self::definition($owner);
        self::assertSame('kumwe.contract-manifest-six/grid', $definition->previewCapability);
        self::assertSame('kumwe.contract-manifest-six.renderer.grid', $definition->renderer);
        $extensions = new OwnedRuntimeContributionRegistry(
            'studio preview renderer',
            StudioPreviewBlockRenderer::class,
        );
        $extensions->register($owner, $definition, new class implements StudioPreviewBlockRenderer {
            /**
             * Return a visible fixture fragment carrying the copied property proof.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied block input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding result.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Fixture fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                $columns = $block->property('columns');

                return new StudioPreviewBlockFragment(
                    'section',
                    'fixture-grid',
                    is_int($columns)
                        ? sprintf('%s:%s:%d', $block->type, $viewport, $columns)
                        : '',
                    $binding->hidden,
                );
            }
        });
        $registry = new ContributedStudioPreviewBlockRendererRegistry(
            new CoreStudioPreviewBlockRendererRegistry(),
            $extensions,
        );
        $exact = new StudioPreviewBlockReference(
            'kumwe.contract-manifest-six/grid',
            '1.0.0',
            'grid-block-r1',
        );
        $node = self::node();
        self::assertTrue($registry->supports($exact));
        $fragment = $registry->render($node, $exact, StudioPreviewBindingResult::unavailable(), 'tablet');
        self::assertSame('fixture-grid', $fragment->className);
        self::assertSame('kumwe.contract-manifest-six/grid:tablet:4', $fragment->text);

        $mismatches = [
            new StudioPreviewBlockReference('kumwe.contract-manifest-six/grid', '1.0.0', null),
            new StudioPreviewBlockReference('kumwe.contract-manifest-six/grid', '1.0.0', 'grid-block-r2'),
            new StudioPreviewBlockReference('kumwe.contract-manifest-six/grid', '2.0.0', 'grid-block-r1'),
        ];
        foreach ($mismatches as $mismatch) {
            self::assertFalse($registry->supports($mismatch));
            self::assertSame(
                'studio-preview-unresolved',
                $registry->render($node, $mismatch, StudioPreviewBindingResult::unavailable(), 'tablet')->className,
            );
        }

        $throwing = new OwnedRuntimeContributionRegistry(
            'studio preview renderer',
            StudioPreviewBlockRenderer::class,
        );
        $throwing->register($owner, $definition, new class implements StudioPreviewBlockRenderer {
            /**
             * Exercise the registry's renderer-exception refusal.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied block input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding result.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Never returned.
             *
             * @throws  RuntimeException  Deliberate fixture failure.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                throw new RuntimeException('Fixture renderer failure.');
            }
        });
        $throwingRegistry = new ContributedStudioPreviewBlockRendererRegistry(
            new CoreStudioPreviewBlockRendererRegistry(),
            $throwing,
        );
        self::assertTrue($throwingRegistry->supports($exact));
        self::assertSame(
            'studio-preview-unresolved',
            $throwingRegistry->render(
                $node,
                $exact,
                StudioPreviewBindingResult::unavailable(),
                'tablet',
            )->className,
        );
    }

    /**
     * Require exactly one signed preview capability, separate from the owner-local service binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMissingOrAmbiguousPreviewRequirementCannotActivate(): void
    {
        $owner = ContributionOwner::extension('kumwe/contract-manifest-six');
        $canonical = self::canonicalDocument()->document;
        $requirements = $canonical->rendererRequirements ?? null;
        self::assertIsArray($requirements);
        $canonical->rendererRequirements = array_values(array_filter(
            $requirements,
            static fn (mixed $requirement): bool => !$requirement instanceof stdClass
                || $requirement->surface !== 'preview',
        ));
        try {
            self::definition($owner, CanonicalJson::stringify($canonical));
            self::fail('A block without a preview renderer capability must remain inert.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('exactly one preview renderer capability', $exception->getMessage());
        }

        $canonical = self::canonicalDocument()->document;
        $requirements = $canonical->rendererRequirements ?? null;
        self::assertIsArray($requirements);
        foreach ($requirements as $requirement) {
            if ($requirement instanceof stdClass && $requirement->surface === 'preview') {
                $requirements[] = clone $requirement;
                break;
            }
        }
        $canonical->rendererRequirements = $requirements;
        try {
            self::definition($owner, CanonicalJson::stringify($canonical));
            self::fail('An ambiguous preview renderer capability must remain inert.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('exactly one preview renderer capability', $exception->getMessage());
        }
    }

    /**
     * Build the derived executable definition from the committed signed declaration.
     *
     * @param   ContributionOwner  $owner      Runtime package owner.
     * @param   string|null        $canonical  Optional altered canonical block bytes.
     *
     * @return  StudioPreviewRendererContribution  Exact derived definition.
     *
     * @since   2.0.0
     */
    private static function definition(
        ContributionOwner $owner,
        ?string $canonical = null,
    ): StudioPreviewRendererContribution {
        $document = $canonical === null
            ? self::canonicalDocument()
            : new CanonicalCompositionDocument(CanonicalCompositionKind::BlockDefinition, $canonical);

        return new StudioPreviewRendererContribution(
            $owner,
            '1.0.0',
            $document,
            new CompositionHostBinding(
                CanonicalCompositionKind::BlockDefinition,
                'kumwe.contract-manifest-six/grid',
                'kumwe.contract-manifest-six.renderer.grid',
            ),
        );
    }

    /**
     * Read the canonical block fixture exactly as schema six declares it.
     *
     * @return  CanonicalCompositionDocument  Committed block definition.
     *
     * @since   2.0.0
     */
    private static function canonicalDocument(): CanonicalCompositionDocument
    {
        $path = dirname(__DIR__, 4) . '/tests/Fixtures/ExtensionApi/generations/manifest-6/kumwe.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $contributions = $manifest['contributions'] ?? null;
        self::assertIsArray($contributions);
        $composition = $contributions['composition'] ?? null;
        self::assertIsArray($composition);
        $documents = $composition['documents'] ?? null;
        self::assertIsArray($documents);
        $first = $documents[0] ?? null;
        self::assertIsArray($first);
        $canonical = $first['canonical'] ?? null;
        self::assertIsString($canonical);

        return new CanonicalCompositionDocument(CanonicalCompositionKind::BlockDefinition, $canonical);
    }

    /**
     * Build one exact fixture node for direct registry resolution.
     *
     * @return  stdClass  Schema-shaped node.
     *
     * @since   2.0.0
     */
    private static function node(): stdClass
    {
        return (object) [
            'id' => 'grid-node',
            'type' => 'kumwe.contract-manifest-six/grid',
            'version' => '1.0.0',
            'properties' => (object) ['columns' => 4, 'collapse' => 'stack'],
            'bindings' => new stdClass(),
            'slots' => (object) ['items' => []],
        ];
    }
}
