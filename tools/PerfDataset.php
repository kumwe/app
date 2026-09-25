<?php

/**
 * Deterministic aged-dataset generator for capacity samples and storage measurements (P0-D).
 *
 * The capacity contract binds every result to a dataset generator seed and age distribution. This is that
 * generator: a Mersenne Twister seeded with the declared seed draws each row's age bucket by the declared
 * shares and a uniform age inside the bucket, so the same seed and row count always produce the same ages,
 * on every engine and host. The tools create the rows through the production record service, so every
 * ledger an LBT writes is populated, and then backdate the record table's timestamps to the drawn ages.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

namespace Kumwe\App\Tools\Performance;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * One declared, seeded, age-distributed dataset.
 *
 * @since  2.0.0
 */
final readonly class PerfDataset
{
    /**
     * Seed the contract declares for published samples.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int DEFAULT_SEED = 20_260_924;

    /**
     * Age buckets as [name, minimum days inclusive, maximum days exclusive, share]; shares sum to one.
     *
     * @var    list<array{string, int, int, float}>
     * @since  2.0.0
     */
    public const array AGE_DISTRIBUTION = [
        ['last_day', 0, 1, 0.05],
        ['last_week', 1, 7, 0.10],
        ['last_month', 7, 30, 0.20],
        ['last_quarter', 30, 90, 0.25],
        ['last_year', 90, 365, 0.25],
        ['older', 365, 1_095, 0.15],
    ];

    /**
     * Declare a dataset.
     *
     * @param  int  $seed     Generator seed.
     * @param  int  $records  Rows to generate, 0 to 1,000,000.
     *
     * @throws  InvalidArgumentException  When the row count is out of range.
     *
     * @since  2.0.0
     */
    public function __construct(public int $seed = self::DEFAULT_SEED, public int $records = 0)
    {
        if ($records < 0 || $records > 1_000_000) {
            throw new InvalidArgumentException('A dataset holds between 0 and 1,000,000 records.');
        }
    }

    /**
     * Draw every row's age, in generation order.
     *
     * @return  list<array{index: int, bucket: string, age_seconds: int}>  Rows.
     *
     * @since   2.0.0
     */
    public function rows(): array
    {
        $randomizer = new Randomizer(new Mt19937($this->seed));
        $rows = [];
        for ($index = 0; $index < $this->records; $index++) {
            $draw = $randomizer->getInt(0, 999_999) / 1_000_000;
            $cumulative = 0.0;
            $chosen = self::AGE_DISTRIBUTION[count(self::AGE_DISTRIBUTION) - 1];
            foreach (self::AGE_DISTRIBUTION as $bucket) {
                $cumulative += $bucket[3];
                if ($draw < $cumulative) {
                    $chosen = $bucket;
                    break;
                }
            }
            $rows[] = [
                'index' => $index,
                'bucket' => $chosen[0],
                'age_seconds' => $randomizer->getInt($chosen[1] * 86_400, $chosen[2] * 86_400 - 1),
            ];
        }

        return $rows;
    }

    /**
     * Count generated rows per bucket.
     *
     * @return  array<string, int>  Rows per bucket, in declaration order.
     *
     * @since   2.0.0
     */
    public function bucketCounts(): array
    {
        $counts = [];
        foreach (self::AGE_DISTRIBUTION as $bucket) {
            $counts[$bucket[0]] = 0;
        }
        foreach ($this->rows() as $row) {
            $counts[$row['bucket']]++;
        }

        return $counts;
    }

    /**
     * Digest the generated ages, so a result can prove which dataset it ran on.
     *
     * @return  string  SHA-256 of the canonical JSON of the rows.
     *
     * @since   2.0.0
     */
    public function digest(): string
    {
        return hash('sha256', json_encode($this->rows(), JSON_THROW_ON_ERROR));
    }

    /**
     * Describe the dataset for a result binding.
     *
     * @return  array{generator: string, prng: string, seed: int, records: int, age_distribution: list<array{
     *          bucket: string, minimum_days: int, maximum_days: int, share: float}>, bucket_counts: array<string,
     *          int>, digest: string}  Binding.
     *
     * @since   2.0.0
     */
    public function describe(): array
    {
        $distribution = [];
        foreach (self::AGE_DISTRIBUTION as [$name, $minimum, $maximum, $share]) {
            $distribution[] = [
                'bucket' => $name,
                'minimum_days' => $minimum,
                'maximum_days' => $maximum,
                'share' => $share,
            ];
        }

        return [
            'generator' => 'tools/PerfDataset.php',
            'prng' => 'Random\\Engine\\Mt19937 via Random\\Randomizer::getInt',
            'seed' => $this->seed,
            'records' => $this->records,
            'age_distribution' => $distribution,
            'bucket_counts' => $this->bucketCounts(),
            'digest' => $this->digest(),
        ];
    }
}

/**
 * Create a dataset's records through the production service, then backdate them to the drawn ages.
 *
 * Every row is one ordinary LBT, so the revision, audit, idempotency, outbox and journal ledgers hold what a
 * live installation of that age would. The record table's creation and update instants are then rewritten
 * in generation order, which is what gives date predicates, sorts and plans an aged distribution to work on.
 *
 * @param   callable(int): string                            $create     Creates row N and returns its record key.
 * @param   \Doctrine\DBAL\Connection                        $database   Connection the table is on.
 * @param   \Kumwe\BusinessSchema\Domain\PhysicalTableBlueprint  $table  Installed record table.
 * @param   PerfDataset                                      $dataset    Declared dataset.
 * @param   \DateTimeImmutable                               $now        Instant ages are measured back from.
 *
 * @return  array{seeded: int, seconds: float}  Rows created and the wall time the seeding took.
 *
 * @since   2.0.0
 */
function seedAgedRecords(
    callable $create,
    \Doctrine\DBAL\Connection $database,
    \Kumwe\BusinessSchema\Domain\PhysicalTableBlueprint $table,
    PerfDataset $dataset,
    \DateTimeImmutable $now,
): array {
    $started = hrtime(true);
    $identity = $table->column('record_id')?->physicalName;
    $created = $table->column('created_at')?->physicalName;
    $updated = $table->column('updated_at')?->physicalName;
    if ($identity === null || $created === null || $updated === null) {
        throw new \RuntimeException('The record table does not declare its identity and timestamp columns.');
    }
    $platform = $database->getDatabasePlatform();
    $sql = sprintf(
        'UPDATE %s SET %s = ?, %s = ? WHERE %s = ?',
        $platform->quoteSingleIdentifier($table->physicalName),
        $platform->quoteSingleIdentifier($created),
        $platform->quoteSingleIdentifier($updated),
        $platform->quoteSingleIdentifier($identity),
    );
    foreach ($dataset->rows() as $row) {
        $recordKey = $create($row['index']);
        $instant = $now->modify(sprintf('-%d seconds', $row['age_seconds']));
        $database->executeStatement($sql, [$instant, $instant, $recordKey], [
            \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
            \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE,
            \Doctrine\DBAL\Types\Types::STRING,
        ]);
    }

    return ['seeded' => $dataset->records, 'seconds' => (hrtime(true) - $started) / 1_000_000_000];
}
