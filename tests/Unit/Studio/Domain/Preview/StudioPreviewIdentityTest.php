<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Preview;

use JsonException;
use Kumwe\App\Studio\Application\Preview\CoreStudioPreviewBlockRendererRegistry;
use Kumwe\App\Studio\Application\Preview\StudioCompositionMarkupRenderer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingResolver;
use Kumwe\App\Studio\Application\Preview\StudioPreviewBindingValues;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Replays both exact Studio preview-identity vectors against the independent PHP implementation.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewDraft::class)]
#[CoversClass(StudioPreviewIdentity::class)]
#[CoversClass(StudioCompositionMarkupRenderer::class)]
final class StudioPreviewIdentityTest extends TestCase
{
    /**
     * Name both vendored preview identity vectors.
     *
     * @return  iterable<string, array{string}>  Vector ID to fixture filename.
     *
     * @since  2.0.0
     */
    public static function previewVectors(): iterable
    {
        yield 'vector.preview-identity.canonical-preorder' => ['canonical-preorder.json'];
        yield 'vector.preview-identity.empty-draft' => ['empty-draft.json'];
    }

    /**
     * Canonical bytes, SHA-256, preorder markers and marker map reproduce the released corpus exactly.
     *
     * @param   string  $filename  Committed preview vector filename.
     *
     * @return  void
     *
     * @throws  JsonException  When a committed fixture is not valid JSON.
     *
     * @since  2.0.0
     */
    #[DataProvider('previewVectors')]
    public function testExactPreviewIdentityVectorsReplay(string $filename): void
    {
        $vector = self::vector($filename);
        $document = self::objectMember($vector, 'draft');
        $expect = self::objectMember($vector, 'expect');
        $render = self::objectMember($vector, 'render');
        $draft = new StudioPreviewDraft('default', $document);
        $identity = StudioPreviewIdentity::forDraft($draft->document());

        self::assertSame($expect->draftDigest, $draft->digest());
        self::assertSame($expect->draftDigest, $identity['draftDigest']);
        self::assertEquals($expect->markers, $identity['markers']);
        self::assertEquals($expect->markerMap, (object) $identity['markerMap']);
        self::assertSame($render->artifactId, $draft->artifactId());
        self::assertSame($render->draftRevision, $draft->revision());
    }

    /**
     * Marker attributes appear once in canonical order and stored strings cannot become active markup.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed fixture is not valid JSON.
     *
     * @since  2.0.0
     */
    public function testStructuralProjectionIsMarkerCompleteAndContainsNoInlineExecutionSurface(): void
    {
        $vector = self::vector('canonical-preorder.json');
        $document = self::objectMember($vector, 'draft');
        $roots = $document->roots ?? null;
        $root = is_array($roots) ? ($roots[0] ?? null) : null;
        $properties = $root instanceof stdClass ? $root->properties ?? null : null;
        if (!$properties instanceof stdClass) {
            throw new RuntimeException('The Studio preview vector root properties are invalid.');
        }
        $properties->label = '<script>alert(1)</script>';
        $identity = StudioPreviewIdentity::forDraft($document);
        $renderer = new StudioCompositionMarkupRenderer(
            new StudioPreviewBindingResolver(),
            new CoreStudioPreviewBlockRendererRegistry(),
        );
        $html = $renderer->render(
            $document,
            $identity['markers'],
            $identity['markerMap'],
            new StudioPreviewBindingValues(new stdClass(), new stdClass()),
            'expanded',
        );

        foreach ($identity['markers'] as $marker) {
            self::assertSame(1, substr_count($html, 'data-studio-preview-marker="' . $marker . '"'));
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString(' style=', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    /**
     * Decode one committed preview vector as an object.
     *
     * @param   string  $filename  Committed preview vector filename.
     *
     * @return  stdClass  Decoded vector.
     *
     * @throws  JsonException  When the committed fixture is invalid.
     * @throws  RuntimeException  When it does not decode to an object.
     *
     * @since  2.0.0
     */
    private static function vector(string $filename): stdClass
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/vectors/preview/' . $filename;
        $vector = json_decode((string) file_get_contents($path), false, 64, JSON_THROW_ON_ERROR);
        if (!$vector instanceof stdClass) {
            throw new RuntimeException('The Studio preview vector is invalid.');
        }

        return $vector;
    }

    /**
     * Resolve one required object member from a committed vector.
     *
     * @param   stdClass  $vector  Decoded vector.
     * @param   string    $member  Required member name.
     *
     * @return  stdClass  Required object member.
     *
     * @throws  RuntimeException  When the member is absent or not an object.
     *
     * @since   2.0.0
     */
    private static function objectMember(stdClass $vector, string $member): stdClass
    {
        $value = $vector->{$member} ?? null;
        if (!$value instanceof stdClass) {
            throw new RuntimeException('The Studio preview vector member is invalid.');
        }

        return $value;
    }
}
