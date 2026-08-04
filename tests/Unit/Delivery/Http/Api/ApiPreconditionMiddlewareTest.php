<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Api;

use Kumwe\CMS\Delivery\Http\Api\Concurrency\IfMatch;
use Kumwe\CMS\Delivery\Http\Api\Concurrency\RequireIfMatchMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(RequireIfMatchMiddleware::class)]
#[CoversClass(RequireIdempotencyKeyMiddleware::class)]
final class ApiPreconditionMiddlewareTest extends TestCase
{
    public function testIfMatchIsRequiredAndAttachedAsAValueObject(): void
    {
        $middleware = new RequireIfMatchMiddleware(new ProblemDetailsResponseFactory());

        self::assertSame(428, $middleware->process($this->request(), $this->handler())->getStatusCode());

        $response = $middleware->process($this->request()->withHeader('If-Match', '"v4"'), $this->handler());
        self::assertSame(204, $response->getStatusCode());
        self::assertSame(IfMatch::class, $response->getHeaderLine('X-Attribute-Class'));
    }

    public function testIdempotencyKeyIsRequiredAndAttachedAsAValueObject(): void
    {
        $middleware = new RequireIdempotencyKeyMiddleware(new ProblemDetailsResponseFactory());

        self::assertSame(400, $middleware->process($this->request(), $this->handler())->getStatusCode());

        $response = $middleware->process(
            $this->request()->withHeader('Idempotency-Key', 'request-1234'),
            $this->handler(),
        );
        self::assertSame(204, $response->getStatusCode());
        self::assertSame(IdempotencyKey::class, $response->getHeaderLine('X-Attribute-Class'));
    }

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'https://kumwe.test/api/v1/plans');
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $attribute = $request->getAttribute(RequireIfMatchMiddleware::ATTRIBUTE)
                    ?? $request->getAttribute(RequireIdempotencyKeyMiddleware::ATTRIBUTE);

                return new TextResponse('', 204, ['X-Attribute-Class' => $attribute::class]);
            }
        };
    }
}
