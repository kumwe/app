<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Preview;

use JsonException;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewIdentity;
use Kumwe\Producer\Schema\StudioContractResources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Replays both exact Studio preview-identity vectors from Producer's pinned testkit.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioPreviewDraft::class)]
#[CoversClass(StudioPreviewIdentity::class)]
final class StudioPreviewIdentityTest extends TestCase
{
    /**
     * Name both Producer-pinned preview identity vectors.
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
     * @throws  JsonException  When a pinned fixture is not valid JSON.
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
     * Decode one committed preview vector as an object.
     *
     * @param   string  $filename  Committed preview vector filename.
     *
     * @return  stdClass  Decoded vector.
     *
     * @throws  JsonException  When the pinned fixture is invalid.
     * @throws  RuntimeException  When it does not decode to an object.
     *
     * @since  2.0.0
     */
    private static function vector(string $filename): stdClass
    {
        $vector = json_decode(
            StudioContractResources::testkitBytes('vectors/preview/' . $filename),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
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
