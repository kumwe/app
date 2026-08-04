<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Api;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProblemDetailsResponseFactory::class)]
final class ProblemDetailsResponseFactoryTest extends TestCase
{
    public function testCreatesAnRfc9457ResponseWithoutReplacingReservedMembers(): void
    {
        $response = (new ProblemDetailsResponseFactory())->create(
            409,
            'Version Conflict',
            'The submitted version is stale.',
            'urn:kumwe:problem:version-conflict',
            '/api/v1/content/42',
            ['current_version' => 7],
        );
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(7, $body['current_version']);
        self::assertSame('urn:kumwe:problem:version-conflict', $body['type']);
    }

    public function testRejectsReservedExtensionMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProblemDetailsResponseFactory())->create(400, 'Bad Request', 'Invalid.', extensions: ['status' => 200]);
    }
}
