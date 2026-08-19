<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Http\Handler;

use Kumwe\App\Http\Handler\MetricsHandler;
use Kumwe\App\Infrastructure\Observability\MetricCatalog;
use Kumwe\App\Infrastructure\Observability\MetricCollector;
use Kumwe\App\Infrastructure\Observability\MetricRecorder;
use Kumwe\App\Infrastructure\Observability\MetricSample;
use Kumwe\App\Infrastructure\Observability\MetricsAccessPolicy;
use Kumwe\App\Infrastructure\Observability\NullMetricRecorder;
use Kumwe\App\Infrastructure\Observability\ObservabilityContract;
use Kumwe\App\Infrastructure\Observability\PrometheusExposition;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(MetricsHandler::class)]
#[CoversClass(MetricsAccessPolicy::class)]
#[CoversClass(NullMetricRecorder::class)]
final class MetricsEndpointTest extends TestCase
{
    private const TOKEN = 'metrics-scrape-token-derived-from-patterned-words-only';

    public function testTheShippedDefaultIsInvisibleRatherThanUnauthorized(): void
    {
        $response = $this->handler(enabled: false)->handle($this->request());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testAnEnabledPrivateEndpointWithNoTokenStaysInvisible(): void
    {
        $response = $this->handler(enabled: true, token: null)->handle($this->request());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testAScrapeWithoutACredentialIsChallengedAndTellsNothingElse(): void
    {
        $response = $this->handler(enabled: true)->handle($this->request());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="metrics"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', (string) $response->getBody());
    }

    public function testAScrapeWithTheWrongCredentialIsRefused(): void
    {
        $response = $this->handler(enabled: true)
            ->handle($this->request('Bearer metrics-scrape-token-that-is-not-the-configured-one'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testAnAuthorizedScrapeGetsThePrometheusExpositionAndIsNeverCached(): void
    {
        $response = $this->handler(enabled: true)->handle($this->request('Bearer ' . self::TOKEN));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(PrometheusExposition::CONTENT_TYPE, $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('# TYPE kumwe_ready gauge', (string) $response->getBody());
        self::assertStringContainsString("\nkumwe_ready 1\n", (string) $response->getBody());
    }

    public function testAPublicEndpointNeedsNoCredential(): void
    {
        $response = $this->handler(enabled: true, public: true, token: null)->handle($this->request());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTheConfiguredTokenIsNeverEchoedBackToTheScraper(): void
    {
        $response = $this->handler(enabled: true)->handle($this->request('Bearer ' . self::TOKEN));
        $serialized = (string) $response->getBody() . json_encode($response->getHeaders());

        self::assertStringNotContainsString(self::TOKEN, $serialized);
    }

    private function handler(bool $enabled, bool $public = false, ?string $token = self::TOKEN): MetricsHandler
    {
        $contract = ObservabilityContract::load(dirname(__DIR__, 4));
        $catalog = MetricCatalog::create($contract, '2.9.0-qualification', 'http');
        $collector = $this->createStub(MetricCollector::class);
        $collector->method('collect')->willReturn([new MetricSample('kumwe_ready', 'kumwe_ready', [], 1.0)]);
        $recorder = $this->createStub(MetricRecorder::class);
        $recorder->method('samples')->willReturn([]);

        return new MetricsHandler(
            new MetricsAccessPolicy($enabled, $public, $token),
            $catalog,
            $recorder,
            $collector,
            new PrometheusExposition(),
        );
    }

    private function request(?string $authorization = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/metrics');

        return $authorization === null ? $request : $request->withHeader('Authorization', $authorization);
    }
}
