<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Http\Api\Plan;

use DateTimeImmutable;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\IdempotencyKey;
use Kumwe\CMS\Delivery\Http\Api\Idempotency\RequireIdempotencyKeyMiddleware;
use Kumwe\CMS\Delivery\Http\Api\Plan\PlanPreviewHandler;
use Kumwe\CMS\Delivery\Http\Api\Plan\SafePlanFactory;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(PlanPreviewHandler::class)]
final class PlanPreviewHandlerTest extends TestCase
{
    public function testReturnsAPlanOnlyRepresentation(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-04T12:00:00Z');
            }
        };
        $handler = new PlanPreviewHandler(
            new SafePlanFactory($clock),
            new ProblemDetailsResponseFactory(),
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/plans')
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader('request-1234'),
            )
            ->withBody((new StreamFactory())->createStream(
                '{"operation":"seo.review","target":"homepage"}',
            ));
        $response = $handler->handle($request);
        $body = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('plan_only', $body['mode']);
        self::assertFalse($body['apply_supported']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testRejectsUnexposedOperations(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
        $handler = new PlanPreviewHandler(new SafePlanFactory($clock), new ProblemDetailsResponseFactory());
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://kumwe.test/api/v1/plans')
            ->withAttribute(
                RequireIdempotencyKeyMiddleware::ATTRIBUTE,
                IdempotencyKey::fromHeader('request-1234'),
            )
            ->withBody((new StreamFactory())->createStream(
                '{"operation":"extension.install","target":"package"}',
            ));

        self::assertSame(400, $handler->handle($request)->getStatusCode());
    }
}
