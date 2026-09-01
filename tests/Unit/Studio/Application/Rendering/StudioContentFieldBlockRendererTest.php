<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Rendering;

use Kumwe\App\Studio\Application\Rendering\StudioContentFieldBlockRenderer;
use Kumwe\Producer\Render\BindingResolution;
use Kumwe\Producer\Render\BlockRendererRegistry;
use Kumwe\Producer\Render\CompositionRenderer;
use Kumwe\Producer\Render\RenderContext;
use Kumwe\Producer\Render\RenderException;
use Kumwe\Producer\Render\RenderState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(StudioContentFieldBlockRenderer::class)]
/**
 * Proves the Content field renderer stays inside its exact registration and scalar families.
 *
 * @since  2.0.0
 */
final class StudioContentFieldBlockRendererTest extends TestCase
{
    /**
     * Prove every declared field kind coerces only its scalar family into escaped inert markup.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEachFieldKindCoercesOnlyItsDeclaredScalarFamily(): void
    {
        $cases = [
            [
                'core/field-text',
                'Plain <b>text</b>',
                '<p data-studio-part="value">Plain &lt;b&gt;text&lt;/b&gt;</p>',
            ],
            ['core/field-integer', 42, '<p data-studio-part="value">42</p>'],
            ['core/field-decimal', '19.95', '<p data-studio-part="value">19.95</p>'],
            ['core/field-boolean', true, '<p data-studio-part="value">true</p>'],
            ['core/field-date', '2026-08-12', '<p data-studio-part="value">2026-08-12</p>'],
            [
                'core/field-date-time',
                '2026-08-12T00:00:00+00:00',
                '<p data-studio-part="value">2026-08-12T00:00:00+00:00</p>',
            ],
            [
                'core/field-rich-text',
                (object) ['text' => 'Lead ', 'content' => [(object) ['text' => 'tail']]],
                '<article data-studio-part="value">Lead tail</article>',
            ],
            ['core/field-media', (object) ['alt' => 'Poster', 'id' => 'm-1'], '<p data-studio-part="value">Poster</p>'],
            ['core/field-resource', (object) ['id' => 'r-1'], '<p data-studio-part="value">r-1</p>'],
        ];
        foreach ($cases as [$type, $value, $expected]) {
            self::assertSame($expected, self::render($type, BindingResolution::available($value)), $type);
        }
        self::assertSame('', self::render('core/field-integer', BindingResolution::available('not-an-int')));
        self::assertSame('', self::render('core/field-boolean', BindingResolution::available('yes')));
        self::assertSame('', self::render('core/field-media', BindingResolution::available((object) ['url' => 'x'])));
    }

    /**
     * Prove an unavailable binding renders the empty unavailable baseline, never a placeholder.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnavailableBindingRendersNothing(): void
    {
        self::assertSame('', self::render('core/field-text', BindingResolution::unavailable()));
    }

    /**
     * Prove invocation outside the exact host registration is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvocationOutsideTheExactRegistrationIsRefused(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('unregistered block type');

        self::render('core/heading', BindingResolution::available('never'));
    }

    /**
     * Prove rich text flattens strings, lists and node objects while refusing any other family.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRichTextFlattensOnlyItsClosedCanonicalShapes(): void
    {
        self::assertSame(
            '<article data-studio-part="value">Plain rich value</article>',
            self::render('core/field-rich-text', BindingResolution::available('Plain rich value')),
        );
        self::assertSame(
            '<article data-studio-part="value">First second</article>',
            self::render('core/field-rich-text', BindingResolution::available([
                'First ',
                (object) ['text' => 'second'],
            ])),
        );
        self::assertSame('', self::render('core/field-rich-text', BindingResolution::available(42)));
    }

    /**
     * Prove references render a plain string label directly and refuse non-reference families.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReferencesRenderOnlyPlainLabelsOrNothing(): void
    {
        self::assertSame(
            '<p data-studio-part="value">Plain label</p>',
            self::render('core/field-media', BindingResolution::available('Plain label')),
        );
        self::assertSame('', self::render('core/field-resource', BindingResolution::available(7)));
        self::assertSame('', self::render('core/field-media', BindingResolution::available(['label' => 'x'])));
    }

    /**
     * Render one node directly with a fixed binding resolution.
     *
     * @param   string             $type        Candidate block type on the node.
     * @param   BindingResolution  $resolution  Host binding evaluation the state serves.
     *
     * @return  string  Rendered inner markup.
     *
     * @since   2.0.0
     */
    private static function render(string $type, BindingResolution $resolution): string
    {
        $registry = BlockRendererRegistry::withCoreCatalog();
        $state = new RenderState(
            new RenderContext(resolveBinding: static fn (stdClass $node, string $port): BindingResolution
                => $resolution),
            new CompositionRenderer($registry),
        );

        return (new StudioContentFieldBlockRenderer())->render(
            (object) ['id' => 'field-node', 'type' => $type, 'bindings' => new stdClass()],
            'scope-token',
            $state,
        );
    }
}
