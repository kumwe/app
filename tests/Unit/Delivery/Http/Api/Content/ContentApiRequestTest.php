<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Api\Content;

use InvalidArgumentException;
use Kumwe\App\Delivery\Http\Api\Content\ContentApiRequest;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(ContentApiRequest::class)]
final class ContentApiRequestTest extends TestCase
{
    public function testEmptyRequestObjectIsAccepted(): void
    {
        self::assertSame([], ContentApiRequest::json($this->request('{}')));
    }

    public function testRequestArrayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('request body must be a JSON object');

        ContentApiRequest::json($this->request('[]'));
    }

    public function testEmptyDataObjectIsAccepted(): void
    {
        $body = ContentApiRequest::json($this->request('{"data":{}}'));

        self::assertSame([], ContentApiRequest::data($body));
    }

    public function testDataArrayIsRejected(): void
    {
        $body = ContentApiRequest::json($this->request('{"data":[]}'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('data field must be a JSON object');

        ContentApiRequest::data($body);
    }

    public function testNestedDataObjectsAndArraysAreNormalized(): void
    {
        $body = ContentApiRequest::json($this->request(
            '{"data":{"metadata":{"featured":true},"tags":["news","featured"]}}',
        ));

        self::assertSame([
            'metadata' => ['featured' => true],
            'tags' => ['news', 'featured'],
        ], ContentApiRequest::data($body));
    }

    private function request(string $json): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/content')
            ->withBody((new StreamFactory())->createStream($json));
    }
}
