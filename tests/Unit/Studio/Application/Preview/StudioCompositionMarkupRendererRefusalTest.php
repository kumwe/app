<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioCompositionMarkupRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioPublishedBlockRendererUnavailable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Exercises every fail-closed tree, marker and immutable block-lock path in composition markup rendering.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioCompositionMarkupRenderer::class)]
#[UsesClass(CoreStudioPreviewBlockRendererRegistry::class)]
#[UsesClass(StudioPreviewBindingResolver::class)]
#[UsesClass(StudioPreviewBindingValues::class)]
#[UsesClass(StudioPreviewBlockFragment::class)]
#[UsesClass(StudioPublishedBlockRendererUnavailable::class)]
final class StudioCompositionMarkupRendererRefusalTest extends TestCase
{
    /**
     * Reject missing roots, divergent marker inventories, malformed nodes and invalid slot children.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedPreviewTreesAndMarkerInventoriesAreRejected(): void
    {
        $renderer = self::renderer();
        $values = self::values();
        $cases = [
            'missing roots' => static fn () => $renderer->render(
                new stdClass(),
                [],
                [],
                $values,
                'expanded',
            ),
            'extra marker' => static fn () => $renderer->render(
                (object) ['roots' => []],
                ['markers/one'],
                ['markers/one' => 'nodes/one'],
                $values,
                'expanded',
            ),
            'invalid node' => static fn () => $renderer->render(
                (object) ['roots' => [new stdClass()]],
                [],
                [],
                $values,
                'expanded',
            ),
            'wrong marker mapping' => static fn () => $renderer->render(
                (object) ['roots' => [self::node()]],
                ['markers/one'],
                ['markers/one' => 'nodes/other'],
                $values,
                'expanded',
            ),
            'invalid slot' => static fn () => $renderer->render(
                (object) ['roots' => [self::node((object) ['main' => new stdClass()])]],
                ['markers/one'],
                ['markers/one' => 'nodes/one'],
                $values,
                'expanded',
            ),
        ];

        foreach ($cases as $case => $operation) {
            self::assertInvalid($operation, $case);
        }
    }

    /**
     * Reject malformed, ambiguous and unavailable immutable block dependency locks before public rendering.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testMalformedAndUnavailablePublishedLocksAreRejected(): void
    {
        $values = self::values();
        self::assertInvalid(
            static fn () => self::renderer()->renderPublished(new stdClass(), $values),
            'missing published roots',
        );
        self::assertInvalid(
            static fn () => self::renderer()->renderPublished((object) [
                'dependencyLock' => new stdClass(),
                'roots' => [],
            ], $values),
            'invalid dependency lock',
        );
        self::assertInvalid(
            static fn () => self::renderer()->renderPublished((object) [
                'dependencyLock' => (object) ['blocks' => [new stdClass()]],
                'roots' => [],
            ], $values),
            'invalid block lock',
        );

        $duplicate = self::lock('extension/card', '1.0.0', 'revision-1');
        self::assertUnavailable(
            static fn () => self::renderer()->renderPublished((object) [
                'dependencyLock' => (object) ['blocks' => [$duplicate, clone $duplicate]],
                'roots' => [],
            ], $values),
            'ambiguous lock',
        );

        $unsupported = self::createStub(StudioPreviewBlockRendererRegistry::class);
        $unsupported->method('supports')->willReturn(false);
        self::assertUnavailable(
            static fn () => (new StudioCompositionMarkupRenderer(
                new StudioPreviewBindingResolver(),
                $unsupported,
            ))->renderPublished((object) [
                'dependencyLock' => (object) [
                    'blocks' => [self::lock('extension/card', '1.0.0', 'revision-1')],
                ],
                'roots' => [],
            ], $values),
            'unsupported lock',
        );
    }

    /**
     * Refuse node-to-lock drift and fail-closed fragments even when the registry advertises support.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testPublishedNodeMustMatchALiveResolvedRenderer(): void
    {
        $registry = self::createStub(StudioPreviewBlockRendererRegistry::class);
        $registry->method('supports')->willReturn(true);
        $renderer = new StudioCompositionMarkupRenderer(new StudioPreviewBindingResolver(), $registry);
        $values = self::values();
        $lock = self::lock('extension/card', '1.0.0', 'revision-1');

        self::assertUnavailable(
            static fn () => $renderer->renderPublished((object) [
                'dependencyLock' => (object) ['blocks' => [$lock]],
                'roots' => [self::node(type: 'extension/card', version: '2.0.0')],
            ], $values),
            'node lock drift',
        );

        $registry = self::createStub(StudioPreviewBlockRendererRegistry::class);
        $registry->method('supports')->willReturn(true);
        $registry->method('render')->willReturn(new StudioPreviewBlockFragment(
            'div',
            'studio-preview-unresolved',
            '',
        ));
        $renderer = new StudioCompositionMarkupRenderer(new StudioPreviewBindingResolver(), $registry);
        self::assertUnavailable(
            static fn () => $renderer->renderPublished((object) [
                'dependencyLock' => (object) ['blocks' => [$lock]],
                'roots' => [self::node(type: 'extension/card', version: '1.0.0')],
            ], $values),
            'unresolved renderer',
        );
    }

    /**
     * Compose the production renderer around the core fixed registry.
     *
     * @return  StudioCompositionMarkupRenderer  Renderer under test.
     *
     * @since  2.0.0
     */
    private static function renderer(): StudioCompositionMarkupRenderer
    {
        return new StudioCompositionMarkupRenderer(
            new StudioPreviewBindingResolver(),
            new CoreStudioPreviewBlockRendererRegistry(),
        );
    }

    /**
     * Build empty authorized binding namespaces.
     *
     * @return  StudioPreviewBindingValues  Empty values.
     *
     * @since  2.0.0
     */
    private static function values(): StudioPreviewBindingValues
    {
        return new StudioPreviewBindingValues(new stdClass(), new stdClass());
    }

    /**
     * Build a minimal structurally valid block node.
     *
     * @param   stdClass|null  $slots    Optional slot object.
     * @param   string         $type     Block type.
     * @param   string|null    $version  Optional immutable block version.
     *
     * @return  stdClass  Blueprint node.
     *
     * @since  2.0.0
     */
    private static function node(
        ?stdClass $slots = null,
        string $type = 'core/container',
        ?string $version = null,
    ): stdClass {
        $node = (object) [
            'bindings' => new stdClass(),
            'id' => 'nodes/one',
            'properties' => new stdClass(),
            'slots' => $slots ?? new stdClass(),
            'type' => $type,
        ];
        if ($version !== null) {
            $node->version = $version;
        }

        return $node;
    }

    /**
     * Build one immutable block dependency-lock entry.
     *
     * @param   string  $type      Block type.
     * @param   string  $version   Semantic block version.
     * @param   string  $revision  Immutable implementation revision.
     *
     * @return  stdClass  Lock entry.
     *
     * @since  2.0.0
     */
    private static function lock(string $type, string $version, string $revision): stdClass
    {
        return (object) [
            'revision' => $revision,
            'type' => $type,
            'version' => $version,
        ];
    }

    /**
     * Assert malformed tree state is rejected.
     *
     * @param   callable  $operation  Render expected to fail.
     * @param   string    $case       Scenario label.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertInvalid(callable $operation, string $case): void
    {
        try {
            $operation();
            self::fail('The invalid Studio composition was rendered: ' . $case);
        } catch (InvalidArgumentException $failure) {
            self::assertNotSame('', $failure->getMessage(), $case);
        }
    }

    /**
     * Assert immutable public rendering fails when no exact live block renderer exists.
     *
     * @param   callable  $operation  Published render expected to fail.
     * @param   string    $case       Scenario label.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    private static function assertUnavailable(callable $operation, string $case): void
    {
        try {
            $operation();
            self::fail('The unresolved Studio composition was published: ' . $case);
        } catch (StudioPublishedBlockRendererUnavailable $failure) {
            self::assertNotSame('', $failure->getMessage(), $case);
        }
    }
}
