<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Middleware;

use Kumwe\App\Http\Middleware\MetricsMiddleware;
use Kumwe\App\Http\Middleware\RequestIdMiddleware;
use Kumwe\App\Infrastructure\Observability\CorrelationContext;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\MetricSample;
use Kumwe\App\Infrastructure\Observability\NullMetricRecorder;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(RequestIdMiddleware::class)]
#[CoversClass(MetricsMiddleware::class)]
#[CoversClass(CorrelationContext::class)]
final class ObservabilityMiddlewareTest extends TestCase
{
    /**
     * The canonical example from the W3C Trace Context recommendation, used so the fixture is
     * recognisably a published specification example rather than anything credential-shaped.
     */
    private const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    public function testAWellFormedUpstreamTraceIsAcceptedPublishedAndEchoed(): void
    {
        $correlation = new CorrelationContext();
        $seen = null;
        $response = (new RequestIdMiddleware($correlation))->process(
            self::request()->withHeader('traceparent', self::TRACEPARENT),
            self::handler(function (ServerRequestInterface $request) use ($correlation, &$seen): void {
                $seen = [
                    'attribute' => $request->getAttribute(RequestIdMiddleware::TRACE_ATTRIBUTE),
                    'fragment' => $correlation->fragment(),
                ];
            }),
        );

        self::assertSame(self::TRACEPARENT, $response->getHeaderLine('traceparent'));
        self::assertIsArray($seen);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $seen['attribute']);
        self::assertIsArray($seen['fragment']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $seen['fragment']['trace_id']);
        self::assertSame('00f067aa0ba902b7', $seen['fragment']['span_id']);
    }

    public function testNoTraceIsInventedWhenNoneIsOffered(): void
    {
        $correlation = new CorrelationContext();
        $inside = null;
        $response = (new RequestIdMiddleware($correlation))->process(
            self::request(),
            self::handler(static function () use ($correlation, &$inside): void {
                $inside = $correlation->traceId();
            }),
        );

        self::assertNull($inside);
        self::assertFalse($response->hasHeader('traceparent'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $response->getHeaderLine('X-Request-ID'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function malformedTraceparents(): array
    {
        return [
            ['01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
            ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
            ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
            ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7'],
            ['00-XXf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
            ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01" injected="yes'],
            ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedTraceparents')]
    public function testAMalformedOrReservedTraceparentIsIgnoredEntirely(string $header): void
    {
        $correlation = new CorrelationContext();
        $response = (new RequestIdMiddleware($correlation))->process(
            self::request()->withHeader('traceparent', [$header]),
            self::handler(),
        );

        self::assertFalse($response->hasHeader('traceparent'));
        self::assertNull($correlation->traceId());
    }

    public function testTheUnitOfWorkIsClosedEvenWhenTheRequestEndsByThrowing(): void
    {
        $correlation = new CorrelationContext();
        $middleware = new RequestIdMiddleware($correlation);
        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('handler failed');
            }
        };

        try {
            $middleware->process(self::request(), $failing);
            self::fail('The middleware must not swallow the failure.');
        } catch (RuntimeException) {
            self::assertSame([], $correlation->fragment());
        }
    }

    public function testEveryResponseIsCountedWithABoundedMethodAndStatusClass(): void
    {
        $recorder = new RecordingMetricRecorder();
        (new MetricsMiddleware($recorder))->process(
            self::request('POST'),
            self::handler(status: 503),
        );

        self::assertSame(
            [[MetricCatalog::HTTP_REQUESTS, ['method' => 'POST', 'status' => '5xx'], 1.0]],
            $recorder->counters,
        );
        self::assertCount(1, $recorder->observations);
        self::assertSame(MetricCatalog::HTTP_DURATION, $recorder->observations[0][0]);
        self::assertSame(['method' => 'POST'], $recorder->observations[0][1]);
        self::assertGreaterThanOrEqual(0.0, $recorder->observations[0][2]);
    }

    public function testARequestThatEscapesTheHandlerIsStillCountedAsAServerError(): void
    {
        $recorder = new RecordingMetricRecorder();
        $failing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('handler failed');
            }
        };

        try {
            (new MetricsMiddleware($recorder))->process(self::request(), $failing);
            self::fail('The middleware must not swallow the failure.');
        } catch (RuntimeException) {
            self::assertSame(
                [[MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'status' => '5xx'], 1.0]],
                $recorder->counters,
            );
        }
    }

    public function testNoRequestPathOrIdentifierEverReachesALabel(): void
    {
        $recorder = new RecordingMetricRecorder();
        (new MetricsMiddleware($recorder))->process(
            (new ServerRequestFactory())->createServerRequest(
                'GET',
                'https://kumwe.test/api/v1/business/records/invoice/patterned-example-record-identifier',
            ),
            self::handler(),
        );

        $encoded = json_encode([$recorder->counters, $recorder->observations]);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('patterned-example-record-identifier', $encoded);
        self::assertStringNotContainsString('/api/v1', $encoded);
    }

    public function testTheDisabledRecorderDoesNothingAtAll(): void
    {
        $recorder = new NullMetricRecorder();
        $recorder->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'status' => '2xx']);
        $recorder->observe(MetricCatalog::HTTP_DURATION, ['method' => 'GET'], 0.5);

        self::assertSame([], $recorder->samples());
    }

    private static function request(string $method = 'GET'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, 'https://kumwe.test/');
    }

    private static function handler(?callable $inspect = null, int $status = 200): RequestHandlerInterface
    {
        return new class ($inspect, $status) implements RequestHandlerInterface {
            public function __construct(private $inspect, private int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->inspect !== null) {
                    ($this->inspect)($request);
                }

                return new TextResponse('ok', $this->status);
            }
        };
    }
}

final class RecordingMetricRecorder implements MetricRecorder
{
    /** @var list<array{0: string, 1: array<string, string>, 2: float}> */
    public array $counters = [];

    /** @var list<array{0: string, 1: array<string, string>, 2: float}> */
    public array $observations = [];

    public function increment(string $metric, array $labels = [], float $value = 1.0): void
    {
        $this->counters[] = [$metric, $labels, $value];
    }

    public function observe(string $metric, array $labels, float $observation): void
    {
        $this->observations[] = [$metric, $labels, $observation];
    }

    /**
     * @return list<MetricSample>
     */
    public function samples(): array
    {
        return [];
    }
}
