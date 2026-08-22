<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api;

use InvalidArgumentException;
use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\OpenApi\Application\ProblemDetailsDefinition;
use Kumwe\App\OpenApi\Application\ProblemDetailsRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that public problem responses cannot drift beyond the finite registry.
 *
 * @since  2.0.0
 */
#[CoversClass(ProblemDetailsResponseFactory::class)]
#[CoversClass(ProblemDetailsDefinition::class)]
#[CoversClass(ProblemDetailsRegistry::class)]
final class ProblemDetailsResponseFactoryTest extends TestCase
{
    /**
     * Preserve reserved RFC 9457 members while publishing a registered typed extension.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCreatesAnRfc9457ResponseWithoutReplacingReservedMembers(): void
    {
        $response = (new ProblemDetailsResponseFactory())->create(
            422,
            'Business Record Validation Failed',
            'The submitted record is invalid.',
            'urn:kumwe:problem:business-record-validation-failed',
            '/api/v1/content/42',
            ['violations' => [[
                'field' => 'reference',
                'code' => 'required',
                'message' => 'A reference is required.',
            ]]],
        );
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('required', $body['violations'][0]['code']);
        self::assertSame('urn:kumwe:problem:business-record-validation-failed', $body['type']);
    }

    /**
     * Refuse an extension member that attempts to replace the response status.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsReservedExtensionMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProblemDetailsResponseFactory())->create(400, 'Bad Request', 'Invalid.', extensions: ['status' => 200]);
    }

    /**
     * Unknown core URNs must not become accidental public branch codes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnUnknownCoreProblemType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not registered');

        (new ProblemDetailsResponseFactory())->create(
            409,
            'Version Conflict',
            'The submitted version is stale.',
            'urn:kumwe:problem:version-conflict',
        );
    }

    /**
     * Absolute third-party URIs must not widen the retained client branch vocabulary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsAnUnregisteredAbsoluteProblemType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not registered');

        (new ProblemDetailsResponseFactory())->create(
            409,
            'External conflict',
            'An undeclared external problem was attempted.',
            'https://third-party.example/problems/conflict',
        );
    }

    /**
     * Generic failures may expose only the bounded correlation id declared by the OpenAPI union.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAboutBlankOnlyAllowsItsBoundedRequestId(): void
    {
        $factory = new ProblemDetailsResponseFactory();
        $invalidExtensions = [
            ['debug' => true],
            ['request_id' => 42],
            ['request_id' => ''],
            ['request_id' => str_repeat('r', 192)],
        ];
        $rejections = 0;
        foreach ($invalidExtensions as $extensions) {
            try {
                $factory->create(500, 'Internal Server Error', 'The request failed.', extensions: $extensions);
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }

        self::assertSame(count($invalidExtensions), $rejections);
        $body = json_decode(
            (string) $factory->create(
                500,
                'Internal Server Error',
                'The request failed.',
                extensions: ['request_id' => 'request-42'],
            )->getBody(),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('request-42', $body['request_id']);
    }

    /**
     * A registered type may vary occurrence detail but not status or required members.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRejectsRegisteredStatusAndExtensionDrift(): void
    {
        $factory = new ProblemDetailsResponseFactory();
        try {
            $factory->create(
                409,
                'Invalid If-Match Header',
                'The header is malformed.',
                'urn:kumwe:problem:invalid-if-match',
            );
            self::fail('A registered problem type was emitted under a different status.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot be emitted', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing a required extension');
        $factory->create(
            422,
            'Business Record Validation Failed',
            'The submitted record is invalid.',
            'urn:kumwe:problem:business-record-validation-failed',
        );
    }

    /**
     * Stable retry timing is applied from the registry instead of repeated at each handler.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRegistryAppliesTheStableRetryDelay(): void
    {
        $response = (new ProblemDetailsResponseFactory())->create(
            503,
            'OpenAPI contract unavailable',
            'The current contract is temporarily unavailable.',
            'urn:kumwe:problem:openapi-contract-unavailable',
        );

        self::assertSame('30', $response->getHeaderLine('Retry-After'));
    }
}
