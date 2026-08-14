<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Observability;

use InvalidArgumentException;
use Kumwe\CMS\Infrastructure\Observability\MetricCatalog;
use Kumwe\CMS\Infrastructure\Observability\MetricDefinition;
use Kumwe\CMS\Infrastructure\Observability\MetricType;
use Kumwe\CMS\Infrastructure\Observability\ObservabilityContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetricCatalog::class)]
#[CoversClass(MetricDefinition::class)]
#[CoversClass(MetricType::class)]
final class MetricCatalogTest extends TestCase
{
    public function testNoDeclaredMetricCarriesALabelTheContractForbids(): void
    {
        $contract = self::contract();
        $catalog = self::catalog();

        foreach ($catalog->definitions() as $definition) {
            foreach (array_keys($definition->labels) as $label) {
                self::assertFalse(
                    $contract->forbidsLabel($label),
                    sprintf('%s must not carry the label %s.', $definition->name, $label),
                );
            }
        }
    }

    public function testEveryDeclaredLabelEnumeratesItsValuesSoSeriesCountIsProvable(): void
    {
        foreach (self::catalog()->definitions() as $definition) {
            foreach ($definition->labels as $label => $values) {
                self::assertNotSame([], $values, sprintf('%s.%s enumerates nothing.', $definition->name, $label));
            }
        }
    }

    public function testTheWholeExpositionIsBoundedToAFewHundredSeries(): void
    {
        // The bound is the property that matters: it holds whatever a caller passes, because every
        // label value outside its enumeration folds into `other` rather than minting a new series.
        self::assertLessThan(200, self::catalog()->maximumSeries());
    }

    public function testAnUnenumeratedLabelValueFoldsIntoOtherRatherThanMintingASeries(): void
    {
        $definition = self::catalog()->definition(MetricCatalog::HTTP_REQUESTS);

        self::assertSame(
            ['method' => 'GET', 'status' => '2xx'],
            $definition->bind(['method' => 'GET', 'status' => '2xx']),
        );
        self::assertSame(
            ['method' => MetricDefinition::OTHER, 'status' => MetricDefinition::OTHER],
            $definition->bind(['method' => 'PROPFIND', 'status' => '9xx']),
        );
    }

    public function testALabelTheDefinitionDoesNotDeclareIsDroppedEntirely(): void
    {
        $bound = self::catalog()->definition(MetricCatalog::HTTP_REQUESTS)->bind([
            'method' => 'POST',
            'status' => '5xx',
            'user_id' => 'patterned-example-account-identifier',
            'path' => '/api/v1/business/records/invoice/patterned-example-record',
        ]);

        self::assertSame(['method' => 'POST', 'status' => '5xx'], $bound);
    }

    public function testStatusCodesFoldOntoTheirClass(): void
    {
        self::assertSame('2xx', MetricCatalog::statusClass(204));
        self::assertSame('4xx', MetricCatalog::statusClass(429));
        self::assertSame('5xx', MetricCatalog::statusClass(503));
        self::assertSame(MetricDefinition::OTHER, MetricCatalog::statusClass(999));
    }

    public function testBuildInfoIsPinnedToThisProcessReleaseAndSurface(): void
    {
        $definition = self::catalog()->definition('kumwe_build_info');

        self::assertSame(
            ['release' => ['2.9.0-qualification'], 'runtime' => ['http']],
            $definition->labels,
        );
    }

    public function testTheRunbookMinimumSignalsAllHaveADeclaredFamily(): void
    {
        $declared = array_keys(self::catalog()->definitions());

        foreach (
            [
                MetricCatalog::HTTP_REQUESTS,
                MetricCatalog::HTTP_DURATION,
                'kumwe_jobs_pending',
                'kumwe_jobs_oldest_due_age_seconds',
                'kumwe_outbox_oldest_pending_age_seconds',
                'kumwe_scheduler_lag_seconds',
                'kumwe_export_queue_depth',
                'kumwe_worker_heartbeat_age_seconds',
            ] as $family
        ) {
            self::assertContains($family, $declared);
        }
    }

    public function testAMetricDeclaringAForbiddenLabelIsRefusedAtBuildTime(): void
    {
        $contract = self::contract();
        $definition = new MetricDefinition(
            'kumwe_example_total',
            MetricType::Counter,
            'Example.',
            ['user_id' => ['a']],
        );

        self::assertTrue($contract->forbidsLabel(array_key_first($definition->labels) ?? ''));
    }

    public function testAHistogramWithoutBucketsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MetricDefinition('kumwe_example_seconds', MetricType::Histogram, 'Example.');
    }

    public function testAGaugeMayNotDeclareBuckets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MetricDefinition('kumwe_example', MetricType::Gauge, 'Example.', [], [1.0]);
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
