<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Performance;

use InvalidArgumentException;
use Kumwe\App\Tools\Performance\PerfDataset;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the aged-dataset generator is a pure function of its seed, follows its declared age distribution,
 * and is the generator the capacity contract declares.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PerfDatasetTest extends TestCase
{
    /**
     * Load the tool under test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/PerfDataset.php';
    }

    /**
     * One seed always yields the same rows and digest; another seed yields different ones.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDatasetIsAPureFunctionOfItsSeed(): void
    {
        $first = new PerfDataset(PerfDataset::DEFAULT_SEED, 500);
        $again = new PerfDataset(PerfDataset::DEFAULT_SEED, 500);
        $other = new PerfDataset(PerfDataset::DEFAULT_SEED + 1, 500);
        self::assertSame($first->rows(), $again->rows());
        self::assertSame($first->digest(), $again->digest());
        self::assertNotSame($first->digest(), $other->digest());
        self::assertSame(
            array_slice($first->rows(), 0, 100),
            (new PerfDataset(PerfDataset::DEFAULT_SEED, 100))->rows(),
            'A longer dataset extends a shorter one with the same seed.',
        );
        self::assertSame([], (new PerfDataset())->rows());
    }

    /**
     * Twenty thousand rows land in every bucket within one percentage point of its declared share, and
     * every age lies inside its bucket.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRowsFollowTheDeclaredAgeDistribution(): void
    {
        $dataset = new PerfDataset(PerfDataset::DEFAULT_SEED, 20_000);
        $shares = 0.0;
        $bounds = [];
        foreach (PerfDataset::AGE_DISTRIBUTION as [$name, $minimum, $maximum, $share]) {
            $shares += $share;
            $bounds[$name] = [$minimum * 86_400, $maximum * 86_400];
        }
        self::assertEqualsWithDelta(1.0, $shares, 1e-12);
        $counts = $dataset->bucketCounts();
        foreach (PerfDataset::AGE_DISTRIBUTION as [$name, , , $share]) {
            self::assertEqualsWithDelta($share, $counts[$name] / 20_000, 0.01, $name);
        }
        foreach ($dataset->rows() as $row) {
            self::assertGreaterThanOrEqual($bounds[$row['bucket']][0], $row['age_seconds']);
            self::assertLessThan($bounds[$row['bucket']][1], $row['age_seconds']);
        }
    }

    /**
     * The capacity contract declares exactly this generator, seed and distribution.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCapacityContractDeclaresThisGenerator(): void
    {
        $contract = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/docs/roadmap/capacity-contract.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        $declared = $contract['dataset_generator'] ?? null;
        self::assertIsArray($declared);
        $described = (new PerfDataset())->describe();
        self::assertSame($described['generator'], $declared['tool']);
        self::assertSame(PerfDataset::DEFAULT_SEED, $declared['default_seed']);
        self::assertSame($described['age_distribution'], $declared['age_distribution']);
        self::assertContains(
            'dataset generator seed and age distribution',
            $contract['benchmark_method']['result_binding'],
        );
    }

    /**
     * A row count outside the supported range is refused.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnOutOfRangeRowCountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PerfDataset(1, 1_000_001);
    }

    /**
     * The concurrent sampler's plan carries the dataset it will seed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheConcurrentPlanBindsTheDataset(): void
    {
        $output = shell_exec(sprintf(
            '%s %s --concurrent --plan --dataset-records=250 --dataset-seed=7',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__, 3) . '/tools/perf-harness.php'),
        ));
        self::assertIsString($output);
        $plan = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertSame(250, $plan['dataset_records']);
        self::assertSame(7, $plan['dataset_seed']);
        self::assertCount(count(PerfDataset::AGE_DISTRIBUTION), $plan['dataset_age_distribution']);
    }
}
