<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioCompositionMarkupRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBlockFragment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Pins the safe responsive intent retained by immutable marker-free public Studio markup.
 *
 * @since  2.0.0
 */
#[CoversClass(CoreStudioPreviewBlockRendererRegistry::class)]
#[CoversClass(StudioCompositionMarkupRenderer::class)]
#[CoversClass(StudioPreviewBlockFragment::class)]
final class StudioPublishedResponsiveMarkupTest extends TestCase
{
    /**
     * Published layout markup retains every bounded width while exposing no preview or execution surface.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedMarkupRetainsEverySafeResponsiveOverrideWithoutAuthoringMarkers(): void
    {
        $document = self::document([
            self::node(
                'grid-node',
                'studio.core/grid',
                [
                    'alignment' => 'start',
                    'collapse' => 'preserve',
                    'columns' => 2,
                    'spacing' => 'comfortable',
                    'style' => 'display:none',
                    'visibility' => 'visible',
                ],
                (object) [
                    'alignment' => (object) [
                        'compact' => 'center',
                        'medium' => 'end',
                        'expanded' => 'stretch',
                    ],
                    'collapse' => (object) [
                        'compact' => 'stack',
                        'medium' => 'wrap',
                        'expanded' => 'preserve',
                    ],
                    'columns' => (object) ['compact' => 4, 'medium' => 3, 'expanded' => 4],
                    'spacing' => (object) [
                        'compact' => 'compact',
                        'medium' => 'none',
                        'expanded' => 'spacious',
                    ],
                    'visibility' => (object) [
                        'compact' => 'visible',
                        'medium' => 'visible',
                        'expanded' => 'visible',
                    ],
                ],
            ),
            self::node(
                'stack-node',
                'studio.core/stack',
                ['direction' => 'block'],
                (object) ['direction' => (object) [
                    'compact' => 'inline',
                    'medium' => 'block',
                    'expanded' => 'inline',
                ]],
            ),
            self::node(
                'section-node',
                'studio.core/section',
                ['visibility' => 'visible'],
                (object) ['visibility' => (object) [
                    'compact' => 'visible',
                    'medium' => 'hidden',
                    'expanded' => 'visible',
                ]],
            ),
        ]);

        $html = self::renderer()->renderPublished(
            $document,
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );

        $expected = [
            'data-studio-layout-compact-alignment' => 'center',
            'data-studio-layout-compact-collapse' => 'stack',
            'data-studio-layout-compact-columns' => '4',
            'data-studio-layout-compact-spacing' => 'compact',
            'data-studio-layout-expanded-alignment' => 'stretch',
            'data-studio-layout-expanded-collapse' => 'preserve',
            'data-studio-layout-expanded-columns' => '4',
            'data-studio-layout-expanded-spacing' => 'spacious',
            'data-studio-layout-medium-alignment' => 'end',
            'data-studio-layout-medium-collapse' => 'wrap',
            'data-studio-layout-medium-columns' => '3',
            'data-studio-layout-medium-spacing' => 'none',
            'data-studio-layout-compact-direction' => 'inline',
            'data-studio-layout-medium-direction' => 'block',
            'data-studio-layout-expanded-direction' => 'inline',
            'data-studio-layout-compact-visibility' => 'visible',
            'data-studio-layout-medium-visibility' => 'hidden',
            'data-studio-layout-expanded-visibility' => 'visible',
        ];
        foreach ($expected as $name => $value) {
            self::assertStringContainsString(sprintf(' %s="%s"', $name, $value), $html);
        }
        self::assertSame(36, substr_count($html, ' data-studio-layout-'));
        self::assertStringNotContainsString('data-studio-preview-marker', $html);
        self::assertStringNotContainsString(' data-studio-layout-alignment=', $html);
        self::assertStringNotContainsString(' style=', $html);
        self::assertStringNotContainsString('display:none', $html);
    }

    /**
     * A malformed override at any retained width fails before public markup can be returned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublishedMarkupRefusesAnInvalidOverrideOutsideTheDefaultRenderWidth(): void
    {
        $document = self::document([
            self::node(
                'invalid-grid',
                'studio.core/grid',
                ['columns' => 2],
                (object) ['columns' => (object) ['compact' => 13]],
            ),
        ]);

        $this->expectException(InvalidArgumentException::class);
        self::renderer()->renderPublished(
            $document,
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
        );
    }

    /**
     * Responsive fragment admission accepts only fixed viewport, property, and value vocabularies.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testResponsiveFragmentAttributesRemainClosedAgainstArbitraryNamesAndValues(): void
    {
        $fragment = new StudioPreviewBlockFragment('div', 'studio-preview-grid', '', false, [
            'data-studio-layout-compact-columns' => '1',
            'data-studio-layout-expanded-columns' => '12',
            'data-studio-layout-medium-columns' => '6',
        ]);
        self::assertSame([
            'data-studio-layout-compact-columns' => '1',
            'data-studio-layout-expanded-columns' => '12',
            'data-studio-layout-medium-columns' => '6',
        ], $fragment->layoutAttributes);

        $invalid = [
            ['data-studio-layout-wide-columns' => '2'],
            ['data-studio-layout-compact-columns' => '13'],
            ['data-studio-layout-compact-style' => 'display:none'],
            ['data-studio-layout-compact-columns' => '" onfocus="alert(1)'],
        ];
        foreach ($invalid as $attributes) {
            try {
                new StudioPreviewBlockFragment('div', 'studio-preview-grid', '', false, $attributes);
                self::fail('An unsafe responsive attribute was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Build one exact published Blueprint around the supplied layout roots.
     *
     * @param   list<stdClass>  $roots  Core layout roots.
     *
     * @return  stdClass  Minimal exact-lock public document.
     *
     * @since   2.0.0
     */
    private static function document(array $roots): stdClass
    {
        $revisions = [
            'studio.core/section' => 'layout-section-r1',
            'studio.core/stack' => 'layout-stack-r1',
            'studio.core/grid' => 'layout-grid-r1',
            'studio.core/columns' => 'layout-columns-r1',
        ];
        $types = [];
        foreach ($roots as $root) {
            $types[$root->type] = true;
        }

        return (object) [
            'kind' => 'blueprint',
            'id' => 'blueprint:public-responsive-test',
            'version' => '1.0.0',
            'revision' => 'public-responsive-r1',
            'dependencyLock' => (object) ['blocks' => array_map(
                static fn (string $type): stdClass => (object) [
                    'type' => $type,
                    'version' => CoreStudioPreviewBlockRendererRegistry::BLOCK_VERSION,
                    'revision' => $revisions[$type],
                ],
                array_keys($types),
            )],
            'roots' => $roots,
        ];
    }

    /**
     * Build one exact-version core layout node.
     *
     * @param   string                $id          Stable node ID.
     * @param   string                $type        Core layout block type.
     * @param   array<string, mixed>  $properties  Base safe layout intent.
     * @param   stdClass              $responsive  Per-viewport safe layout intent.
     *
     * @return  stdClass  Minimal renderable node.
     *
     * @since   2.0.0
     */
    private static function node(
        string $id,
        string $type,
        array $properties,
        stdClass $responsive,
    ): stdClass {
        return (object) [
            'id' => $id,
            'type' => $type,
            'version' => CoreStudioPreviewBlockRendererRegistry::BLOCK_VERSION,
            'properties' => (object) $properties,
            'responsive' => $responsive,
            'bindings' => new stdClass(),
            'slots' => new stdClass(),
        ];
    }

    /**
     * Compose the production-safe renderer under test.
     *
     * @return  StudioCompositionMarkupRenderer  Marker-free public renderer.
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
