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
                '<p class="studio-preview-field-text" data-studio-part="value">Plain &lt;b&gt;text&lt;/b&gt;</p>',
            ],
            ['core/field-integer', 42, '<p class="studio-preview-field-integer" data-studio-part="value">42</p>'],
            [
                'core/field-decimal',
                '19.95',
                '<p class="studio-preview-field-decimal" data-studio-part="value">19.95</p>',
            ],
            ['core/field-boolean', true, '<p class="studio-preview-field-boolean" data-studio-part="value">true</p>'],
            [
                'core/field-date',
                '2026-08-12',
                '<p class="studio-preview-field-date" data-studio-part="value">2026-08-12</p>',
            ],
            [
                'core/field-date-time',
                '2026-08-12T00:00:00+00:00',
                '<p class="studio-preview-field-date-time" data-studio-part="value">2026-08-12T00:00:00+00:00</p>',
            ],
            [
                'core/field-rich-text',
                (object) ['text' => 'Lead ', 'content' => [(object) ['text' => 'tail']]],
                '<article class="studio-preview-field-rich-text" data-studio-part="value">Lead tail</article>',
            ],
            [
                'core/field-media',
                (object) ['alt' => 'Poster', 'id' => 'm-1'],
                '<p class="studio-preview-field-media" data-studio-part="value">Poster</p>',
            ],
            [
                'core/field-resource',
                (object) ['id' => 'r-1'],
                '<p class="studio-preview-field-resource" data-studio-part="value">r-1</p>',
            ],
        ];
        foreach ($cases as [$type, $value, $expected]) {
            self::assertSame($expected, self::render($type, BindingResolution::available($value)), $type);
        }
        self::assertSame(
            self::emptyField('integer'),
            self::render('core/field-integer', BindingResolution::available('not-an-int')),
        );
        self::assertSame(
            self::emptyField('boolean'),
            self::render('core/field-boolean', BindingResolution::available('yes')),
        );
        self::assertSame(
            self::emptyField('media'),
            self::render('core/field-media', BindingResolution::available((object) ['url' => 'x'])),
        );
    }

    /**
     * Prove an unavailable binding keeps its empty field element while a hidden one renders nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnavailableBindingRendersNothing(): void
    {
        self::assertSame(self::emptyField('text'), self::render('core/field-text', BindingResolution::unavailable()));
        self::assertSame('', self::render('core/field-text', BindingResolution::hidden()));
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
            '<article class="studio-preview-field-rich-text" data-studio-part="value">Plain rich value</article>',
            self::render('core/field-rich-text', BindingResolution::available('Plain rich value')),
        );
        self::assertSame(
            '<article class="studio-preview-field-rich-text" data-studio-part="value">First second</article>',
            self::render('core/field-rich-text', BindingResolution::available([
                'First ',
                (object) ['text' => 'second'],
            ])),
        );
        self::assertSame(
            self::emptyField('rich-text', 'article'),
            self::render('core/field-rich-text', BindingResolution::available(42)),
        );
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
            '<p class="studio-preview-field-media" data-studio-part="value">Plain label</p>',
            self::render('core/field-media', BindingResolution::available('Plain label')),
        );
        self::assertSame(
            self::emptyField('resource'),
            self::render('core/field-resource', BindingResolution::available(7)),
        );
        self::assertSame(
            self::emptyField('media'),
            self::render('core/field-media', BindingResolution::available(['label' => 'x'])),
        );
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

    /**
     * Render the empty field element one kind keeps when its value is unavailable or malformed.
     *
     * @param   string  $kind  Closed field suffix.
     * @param   string  $tag   Element the kind renders.
     *
     * @return  string  Empty classed field element.
     *
     * @since   2.0.0
     */
    private static function emptyField(string $kind, string $tag = 'p'): string
    {
        return sprintf('<%1$s class="studio-preview-field-%2$s" data-studio-part="value"></%1$s>', $tag, $kind);
    }
}
