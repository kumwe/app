<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Observability;

use Kumwe\CMS\Infrastructure\Observability\MetricCatalog;
use Kumwe\CMS\Infrastructure\Observability\MetricSample;
use Kumwe\CMS\Infrastructure\Observability\ObservabilityContract;
use Kumwe\CMS\Infrastructure\Observability\PrometheusExposition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrometheusExposition::class)]
#[CoversClass(MetricSample::class)]
final class PrometheusExpositionTest extends TestCase
{
    public function testAFamilyRendersItsHelpTypeAndSortedSeries(): void
    {
        $body = (new PrometheusExposition())->render(self::catalog(), [
            new MetricSample(
                MetricCatalog::HTTP_REQUESTS,
                MetricCatalog::HTTP_REQUESTS,
                ['method' => 'POST', 'status' => '5xx'],
                3.0,
            ),
            new MetricSample(
                MetricCatalog::HTTP_REQUESTS,
                MetricCatalog::HTTP_REQUESTS,
                ['method' => 'GET', 'status' => '2xx'],
                17.0,
            ),
        ]);

        self::assertSame(
            "# HELP kumwe_http_requests_total HTTP responses served, by request method and response status class.\n"
            . "# TYPE kumwe_http_requests_total counter\n"
            . "kumwe_http_requests_total{method=\"GET\",status=\"2xx\"} 17\n"
            . "kumwe_http_requests_total{method=\"POST\",status=\"5xx\"} 3\n",
            $body,
        );
    }

    public function testAnUnlabelledGaugeRendersWithoutABraceSection(): void
    {
        $body = (new PrometheusExposition())->render(self::catalog(), [
            new MetricSample('kumwe_ready', 'kumwe_ready', [], 1.0),
        ]);

        self::assertStringContainsString("\nkumwe_ready 1\n", "\n" . $body);
    }

    public function testASampleNamingAnUndeclaredFamilyIsDroppedRatherThanRendered(): void
    {
        $body = (new PrometheusExposition())->render(self::catalog(), [
            new MetricSample('kumwe_smuggled_total', 'kumwe_smuggled_total', ['user_id' => 'x'], 1.0),
        ]);

        self::assertSame('', $body);
    }

    public function testAnOperatorSuppliedReleaseCannotBreakOutOfItsLabel(): void
    {
        $catalog = MetricCatalog::create(self::contract(), "2.0\"\n# TYPE injected gauge\ninjected", 'http');
        $body = (new PrometheusExposition())->render($catalog, [
            new MetricSample(
                'kumwe_build_info',
                'kumwe_build_info',
                ['release' => "2.0\"\n# TYPE injected gauge\ninjected", 'runtime' => 'http'],
                1.0,
            ),
        ]);

        // The injected text survives as escaped characters inside the quoted label, which is harmless.
        // What must not survive is a real line break or an unescaped quote ending the label early.
        self::assertStringNotContainsString("\n# TYPE injected", $body);
        self::assertStringContainsString('release="2.0\\"\\n# TYPE injected gauge\\ninjected"', $body);
        self::assertSame(3, substr_count($body, "\n"));
    }

    public function testFractionalValuesRenderWithoutLocaleDependence(): void
    {
        $body = (new PrometheusExposition())->render(self::catalog(), [
            new MetricSample(
                'kumwe_metrics_scrape_duration_seconds',
                'kumwe_metrics_scrape_duration_seconds',
                [],
                0.001_25,
            ),
        ]);

        self::assertStringContainsString('kumwe_metrics_scrape_duration_seconds 0.00125', $body);
    }

    public function testTwoRendersOfTheSameSamplesAreByteIdentical(): void
    {
        $samples = [
            new MetricSample('kumwe_ready', 'kumwe_ready', [], 1.0),
            new MetricSample('kumwe_jobs_pending', 'kumwe_jobs_pending', [], 4.0),
        ];
        $exposition = new PrometheusExposition();

        self::assertSame(
            $exposition->render(self::catalog(), $samples),
            $exposition->render(self::catalog(), array_reverse($samples)),
        );
    }

    private static function catalog(): MetricCatalog
    {
        return MetricCatalog::create(self::contract(), '2.9.0-qualification', 'http');
    }

    private static function contract(): ObservabilityContract
    {
        return ObservabilityContract::load(dirname(__DIR__, 4));
    }
}
