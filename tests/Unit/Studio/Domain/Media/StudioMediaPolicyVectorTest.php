<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Domain\Media;

use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Media\StudioMediaPolicyRejected;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPolicy;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Replays every vendored language-neutral Studio media policy and lifecycle vector.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioMediaUploadPolicy::class)]
#[CoversClass(StudioMediaUploadRequest::class)]
#[CoversClass(StudioMediaUploadState::class)]
final class StudioMediaPolicyVectorTest extends TestCase
{
    /**
     * Discover all eleven pinned media vectors directly from the vendored corpus.
     *
     * @return  iterable<string, array{string}>  Vector ID to path argument.
     *
     * @since  2.0.0
     */
    public static function vectors(): iterable
    {
        $paths = glob(dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/vectors/media/*.json');
        self::assertIsArray($paths);
        self::assertCount(11, $paths);
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $vector = self::decode($path);
            self::assertIsString($vector->id);
            yield $vector->id => [$path];
        }
    }

    /**
     * Drive policy, cancellation and retry identity expectations from each JSON vector.
     *
     * @param   string  $path  Absolute vendored vector path.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    #[DataProvider('vectors')]
    public function testVendoredMediaVector(string $path): void
    {
        $vector = self::decode($path);
        self::assertInstanceOf(stdClass::class, $vector->policy);
        self::assertInstanceOf(stdClass::class, $vector->request);
        self::assertInstanceOf(stdClass::class, $vector->expect);
        self::assertIsString($vector->id);
        self::assertIsArray($vector->policy->acceptedMediaTypes);
        $acceptedMediaTypes = [];
        foreach ($vector->policy->acceptedMediaTypes as $acceptedMediaType) {
            self::assertIsString($acceptedMediaType);
            $acceptedMediaTypes[] = $acceptedMediaType;
        }
        self::assertIsInt($vector->policy->maximumBytes);
        self::assertIsBool($vector->policy->resumable);
        $chunkBytes = $vector->policy->chunkBytes ?? null;
        self::assertTrue($chunkBytes === null || is_int($chunkBytes));
        $policy = new StudioMediaUploadPolicy(
            $acceptedMediaTypes,
            $vector->policy->maximumBytes,
            $vector->policy->resumable,
            $chunkBytes,
        );

        try {
            $request = StudioMediaUploadRequest::fromDocument($vector->request);
            $plan = $policy->authorize($request);
            self::assertIsString($vector->expect->outcome);
            self::assertSame('accepted', $vector->expect->outcome, $vector->id);
            self::assertEquals($vector->expect->plan, $plan->document(), $vector->id);
            if (property_exists($vector, 'cancel')) {
                self::assertInstanceOf(stdClass::class, $vector->cancel);
                self::assertIsString($vector->cancel->during);
                self::assertIsString($vector->cancel->finalState);
                $state = StudioMediaUploadState::from($vector->cancel->during);
                self::assertSame($vector->cancel->finalState, $state->cancelled()->value, $vector->id);
            }
        } catch (InvalidArgumentException | StudioMediaPolicyRejected $failure) {
            self::assertIsString($vector->expect->outcome);
            self::assertIsString($vector->expect->code);
            self::assertSame('rejected', $vector->expect->outcome, $vector->id);
            $code = $failure instanceof StudioMediaPolicyRejected
                ? $failure->failureCode
                : 'studio.media/upload-failed';
            self::assertSame($vector->expect->code, $code, $vector->id);
            $forbiddenValues = $vector->expect->messageMustNotContain ?? [];
            self::assertIsArray($forbiddenValues);
            foreach ($forbiddenValues as $forbidden) {
                self::assertIsString($forbidden);
                self::assertStringNotContainsString($forbidden, $failure->getMessage(), $vector->id);
            }
            if (property_exists($vector, 'retry')) {
                self::assertInstanceOf(stdClass::class, $vector->retry);
                self::assertIsBool($vector->retry->freshSession);
                self::assertTrue($vector->retry->freshSession);
            }
        }
    }

    /**
     * Refuse empty, malformed and duplicate host policy media-type vocabularies.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testInvalidPolicyVocabulariesAreRejected(): void
    {
        $cases = [
            'empty policy' => [],
            'malformed type' => ['IMAGE/PNG'],
            'duplicate type' => ['image/png', 'image/png'],
        ];

        foreach ($cases as $case => $mediaTypes) {
            try {
                new StudioMediaUploadPolicy($mediaTypes, 1024, false);
                self::fail('The invalid Studio media policy was accepted: ' . $case);
            } catch (InvalidArgumentException $failure) {
                self::assertNotSame('', $failure->getMessage(), $case);
            }
        }
    }

    /**
     * Decode one required JSON vector as an object.
     *
     * @param   string  $path  Absolute fixture path.
     *
     * @return  stdClass  Decoded vector.
     *
     * @since  2.0.0
     */
    private static function decode(string $path): stdClass
    {
        $document = json_decode((string) file_get_contents($path), false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }
}
