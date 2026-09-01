<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Rendering;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Preview\ContributedStudioPreviewBlock;
use Kumwe\App\Studio\Application\Rendering\FragmentStudioPreviewBlockRenderer;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBindingResult;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockFragment;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlockRenderer;
use Kumwe\Producer\Render\BindingResolution;
use Kumwe\Producer\Render\BlockCoordinate;
use Kumwe\Producer\Render\BlockRendererRegistry;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderPolicy;
use Kumwe\Producer\Render\RenderState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(FragmentStudioPreviewBlockRenderer::class)]
#[CoversClass(ContributedStudioPreviewBlock::class)]
/**
 * Proves the fragment adapter serializes contributed SDK output safely inside the Producer engine.
 *
 * @since  2.0.0
 */
final class FragmentStudioPreviewBlockRendererTest extends TestCase
{
    /**
     * Exact contributed block type every fixture document locks.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TYPE = 'acme.tests/panel';

    /**
     * Prove the fragment is serialized escaped, with the copied block input and active viewport.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProjectsFragmentSerializationEscapedInsideTheEngineWrapper(): void
    {
        $inner = new class implements StudioPreviewBlockRenderer {
            /**
             * Echo the copied properties, binding value and viewport into a bounded fragment.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Safe fixture fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                $columns = $block->property('columns');
                $value = $binding->available && is_string($binding->value) ? $binding->value : 'unbound';

                return new StudioPreviewBlockFragment(
                    'section',
                    'acme-panel',
                    sprintf('%s <%s> %s', $viewport, is_int($columns) ? (string) $columns : '?', $value),
                    false,
                    ['data-studio-layout-columns' => '3'],
                );
            }
        };
        $html = self::render($inner, self::document(slots: (object) [
            'later' => [self::node('child-b')],
            'early' => [self::node('child-a')],
        ]), 'compact', static fn (): BindingResolution => BindingResolution::available('<Bound & value>'));

        self::assertStringContainsString(
            '<section class="acme-panel" data-studio-layout-columns="3">'
            . '<p>compact &lt;3&gt; &lt;Bound &amp; value&gt;</p>',
            $html,
        );
        self::assertStringNotContainsString('<Bound & value>', $html);
        $early = strpos($html, 'data-studio-node="child-a"');
        $later = strpos($html, 'data-studio-node="child-b"');
        self::assertIsInt($early);
        self::assertIsInt($later);
        self::assertLessThan($later, $early, 'Slots must render in canonical code-unit order.');
    }

    /**
     * Prove hidden and unavailable binding outcomes reach the SDK renderer distinctly and stay closed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBindingOutcomesReachTheRendererDistinctly(): void
    {
        $inner = new class implements StudioPreviewBlockRenderer {
            /**
             * Name the exact binding outcome so the assertion can read it back from safe text.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Safe fixture fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $viewport);
                $outcome = $binding->hidden ? 'hidden' : ($binding->available ? 'available' : 'unavailable');

                return new StudioPreviewBlockFragment('div', 'acme-panel', $outcome, $binding->hidden);
            }
        };

        $unavailable = self::render($inner, self::document(), 'expanded');
        self::assertStringContainsString('<p>unavailable</p>', $unavailable);

        $hidden = self::render(
            $inner,
            self::document(),
            'expanded',
            static fn (): BindingResolution => BindingResolution::hidden(),
        );
        self::assertStringContainsString('<p>hidden</p>', $hidden);
        self::assertStringContainsString(' hidden><div class="acme-panel" hidden>', $hidden);
    }

    /**
     * Prove an unsafe fragment attempt renders the engine's bounded fallback instead of throwing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsafeFragmentRendersTheBoundedFallback(): void
    {
        $inner = new class implements StudioPreviewBlockRenderer {
            /**
             * Attempt an element outside the closed safe set.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Never returned; construction is refused.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);

                return new StudioPreviewBlockFragment('script', 'acme-panel', '');
            }
        };
        $html = self::render($inner, self::document(), 'expanded');

        self::assertStringContainsString('<p role="status">Unavailable Studio block acme.tests/panel</p>', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * Prove a viewport outside the closed semantic set is refused at construction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testViewportOutsideTheClosedSetIsRefused(): void
    {
        $inner = new class implements StudioPreviewBlockRenderer {
            /**
             * Provide a syntactically complete implementation that must never execute.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Safe fixture fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);

                return new StudioPreviewBlockFragment('div', 'acme-panel', '');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        new FragmentStudioPreviewBlockRenderer($inner, 'desktop');
    }

    /**
     * Prove a node without its promised exact coordinates is refused before the SDK boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNodeMissingExactCoordinatesIsRefused(): void
    {
        $inner = new class implements StudioPreviewBlockRenderer {
            /**
             * Provide a syntactically complete implementation that must never execute.
             *
             * @param   StudioPreviewBlock          $block     Immutable copied contributed block input.
             * @param   StudioPreviewBindingResult  $binding   Authorized binding projection.
             * @param   string                      $viewport  Active semantic viewport.
             *
             * @return  StudioPreviewBlockFragment  Safe fixture fragment.
             *
             * @since   2.0.0
             */
            public function render(
                StudioPreviewBlock $block,
                StudioPreviewBindingResult $binding,
                string $viewport,
            ): StudioPreviewBlockFragment {
                unset($block, $binding, $viewport);

                return new StudioPreviewBlockFragment('div', 'acme-panel', '');
            }
        };
        $registry = BlockRendererRegistry::withCoreCatalog();
        $state = new RenderState(new RenderContext(), new CompositionRenderer($registry));

        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('missing exact coordinates');

        (new FragmentStudioPreviewBlockRenderer($inner, 'expanded'))->render(
            (object) ['id' => 'panel-root', 'type' => self::TYPE],
            'scope-token',
            $state,
        );
    }

    /**
     * Render one locked contributed document through the real Producer engine.
     *
     * @param   StudioPreviewBlockRenderer                          $inner     Contributed SDK fixture renderer.
     * @param   stdClass                                            $document  Locked Blueprint input.
     * @param   string                                              $viewport  Active semantic viewport.
     * @param   (callable(stdClass, string): BindingResolution)|null  $resolve   Host binding evaluation.
     *
     * @return  string  Complete safe document markup.
     *
     * @since   2.0.0
     */
    private static function render(
        StudioPreviewBlockRenderer $inner,
        stdClass $document,
        string $viewport,
        ?callable $resolve = null,
    ): string {
        $registry = BlockRendererRegistry::withCoreCatalog();
        $registry->register(
            new BlockCoordinate(self::TYPE, '1.0.0', 'panel-r1'),
            new FragmentStudioPreviewBlockRenderer($inner, $viewport),
        );

        return (new CompositionRenderer($registry))->renderDocument(
            $document,
            new RenderContext(
                resolveBinding: $resolve,
                policy: RenderPolicy::RequireRegistered,
            ),
        )->html;
    }

    /**
     * Build one minimal Blueprint locked to the fixture panel coordinate.
     *
     * @param   ?stdClass  $slots  Optional authored slots for the root node.
     *
     * @return  stdClass  Minimal canonical render input.
     *
     * @since   2.0.0
     */
    private static function document(?stdClass $slots = null): stdClass
    {
        $root = self::node('panel-root');
        $root->properties = (object) ['columns' => 3];
        $root->slots = $slots ?? new stdClass();

        return (object) [
            'kind' => 'blueprint',
            'id' => 'acme.tests/panel-proof',
            'revision' => 'blueprint-r1',
            'dependencyLock' => (object) ['blocks' => [(object) [
                'type' => self::TYPE,
                'version' => '1.0.0',
                'revision' => 'panel-r1',
            ]]],
            'roots' => [$root],
        ];
    }

    /**
     * Build one bare fixture node of the locked contributed type.
     *
     * @param   string  $id  Stable Blueprint node identifier.
     *
     * @return  stdClass  Schema-shaped node.
     *
     * @since   2.0.0
     */
    private static function node(string $id): stdClass
    {
        return (object) [
            'id' => $id,
            'type' => self::TYPE,
            'version' => '1.0.0',
            'properties' => new stdClass(),
            'bindings' => new stdClass(),
            'slots' => new stdClass(),
        ];
    }
}
