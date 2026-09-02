<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Rendering;

use Kumwe\App\Studio\Application\Rendering\StudioLayoutBlockRenderer;
use Kumwe\Producer\Render\BlockRendererRegistry;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(StudioLayoutBlockRenderer::class)]
/**
 * Proves the structural block families keep the classed layout vocabulary the site stylesheet keys on.
 *
 * @since  2.0.0
 */
final class StudioLayoutBlockRendererTest extends TestCase
{
    /**
     * Prove a preview resolves one semantic width into unprefixed intent on the classed element.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPreviewResolvesTheRequestedWidthOntoTheClassedElement(): void
    {
        $node = (object) [
            'id' => 'grid-node',
            'type' => 'studio.core/grid',
            'properties' => (object) ['columns' => 4, 'collapse' => 'wrap'],
            'responsive' => (object) ['columns' => (object) ['compact' => 1, 'medium' => 2]],
        ];

        self::assertSame(
            '<div class="studio-preview-grid" data-studio-layout-alignment="stretch"'
            . ' data-studio-layout-spacing="comfortable" data-studio-layout-visibility="visible"'
            . ' data-studio-layout-collapse="wrap" data-studio-layout-columns="4"></div>',
            self::render($node, 'expanded'),
        );
        self::assertStringContainsString('data-studio-layout-columns="2"', self::render($node, 'medium'));
        self::assertStringContainsString('data-studio-layout-columns="1"', self::render($node, 'compact'));
    }

    /**
     * Prove public markup retains every bounded width under its own prefixed attribute family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicMarkupRetainsEveryBoundedWidth(): void
    {
        $node = (object) [
            'id' => 'columns-node',
            'type' => 'studio.core/columns',
            'properties' => (object) ['columns' => 3, 'alignment' => 'center'],
            'responsive' => (object) ['columns' => (object) ['compact' => 1]],
        ];

        $markup = self::render($node, null);

        self::assertStringStartsWith('<div class="studio-preview-columns" ', $markup);
        self::assertStringContainsString('data-studio-layout-compact-columns="1"', $markup);
        self::assertStringContainsString('data-studio-layout-medium-columns="3"', $markup);
        self::assertStringContainsString('data-studio-layout-expanded-columns="3"', $markup);
        self::assertStringContainsString('data-studio-layout-expanded-alignment="center"', $markup);
        self::assertStringNotContainsString(' data-studio-layout-columns=', $markup);
    }

    /**
     * Prove a section renders as a section element and a stack carries its direction intent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSectionsAndStacksKeepTheirElementAndDirectionVocabulary(): void
    {
        $section = self::render((object) ['id' => 's', 'type' => 'studio.core/section'], 'expanded');
        $stack = self::render(
            (object) ['id' => 'k', 'type' => 'studio.core/stack', 'properties' => (object) ['direction' => 'inline']],
            'expanded',
        );

        self::assertStringStartsWith('<section class="studio-preview-section" ', $section);
        self::assertStringEndsWith('></section>', $section);
        self::assertStringNotContainsString('data-studio-layout-columns', $section);
        self::assertStringContainsString('class="studio-preview-stack"', $stack);
        self::assertStringContainsString('data-studio-layout-direction="inline"', $stack);
    }

    /**
     * Prove intent outside the closed vocabulary or bounds never reaches markup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMalformedLayoutIntentFailsClosed(): void
    {
        $malformed = [
            (object) ['id' => 'a', 'type' => 'studio.core/grid', 'properties' => (object) ['columns' => 13]],
            (object) ['id' => 'b', 'type' => 'studio.core/grid', 'properties' => (object) ['collapse' => 'float']],
            (object) ['id' => 'c', 'type' => 'studio.core/stack', 'responsive' => 'inline'],
            (object) ['id' => 'd', 'type' => 'studio.core/section', 'properties' => []],
            (object) ['id' => 'e', 'type' => 'core/field-text'],
        ];
        foreach ($malformed as $node) {
            try {
                self::render($node, 'expanded');
                self::fail('Malformed layout intent must be refused for node ' . $node->id);
            } catch (RenderException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(RenderException::class);
        new StudioLayoutBlockRenderer('wide');
    }

    /**
     * Render one structural node directly through a Producer render state.
     *
     * @param   stdClass  $node      Candidate Blueprint node.
     * @param   ?string   $viewport  Preview semantic width, or null for public markup.
     *
     * @return  string  Rendered inner markup.
     *
     * @since   2.0.0
     */
    private static function render(stdClass $node, ?string $viewport): string
    {
        $state = new RenderState(
            new RenderContext(),
            new CompositionRenderer(BlockRendererRegistry::withCoreCatalog()),
        );

        return (new StudioLayoutBlockRenderer($viewport))->render($node, 'scope-token', $state);
    }
}
