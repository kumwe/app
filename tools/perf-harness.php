<?php

/**
 * Characterise the business runtime against the capacity contract (P2-I).
 *
 * The capacity contract promises a measured envelope — profiles, service level objectives, a
 * deterministic dataset and a published method — and until this harness existed nothing in the
 * repository could measure any of it. This is the P2-I seed: a deterministic plan generator and a
 * workload driver that exercises the real `BusinessRecordService` through the same kernel the
 * integration suite boots, measures the contract's interactive operation classes, and writes a
 * report that speaks the contract's own vocabulary.
 *
 * Two modes:
 *
 *   php tools/perf-harness.php --plan [--seed=N] [--profile=baseline|enterprise|stretch]
 *   php tools/perf-harness.php --run  [--seed=N] [--profile=...] [--samples=N] [--warmup=N]
 *
 * `--plan` is pure: the same seed always prints byte-identical JSON, which is what makes the dataset
 * reproducible and lets a unit test hold the generator to it. `--run` needs the test database
 * environment (source `.agent-env`, or run inside the CI database job) and measures, per operation
 * class: bounded primary-key reads, policy-filtered page browses, ordinary small mutations, and
 * 100- and 1000-line document commits — reporting p50/p95/p99, mean, coefficient of variation and
 * the contract's SLO verdicts to `build/perf/report.json`.
 *
 * Honesty over reach, per the contract's own rules: this seed measures single-worker latency on
 * whatever host runs it, so the report binds the engine, commit, seed and sample counts but claims
 * no absolute-throughput authority ("shared CI runners are never the authority"), no concurrency or
 * contention profile, no write-amplification figure and no breakpoint. Those are the next P2-I
 * stages, and the report's `limitations` block names them rather than leaving them implied.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$mode = null;
$seed = 1400;
$profile = 'baseline';
$samples = 30;
$warmup = 5;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--plan' || $argument === '--run') {
        $mode = substr($argument, 2);
        continue;
    }
    if (preg_match('/^--seed=(\d+)$/', $argument, $match) === 1) {
        $seed = (int) $match[1];
        continue;
    }
    if (preg_match('/^--profile=(baseline|enterprise|stretch)$/', $argument, $match) === 1) {
        $profile = $match[1];
        continue;
    }
    if (preg_match('/^--samples=(\d+)$/', $argument, $match) === 1) {
        $samples = max(1, (int) $match[1]);
        continue;
    }
    if (preg_match('/^--warmup=(\d+)$/', $argument, $match) === 1) {
        $warmup = max(0, (int) $match[1]);
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(1);
}

if ($mode === null) {
    fwrite(STDERR, "Pass --plan or --run. See the file header for usage.\n");
    exit(1);
}

$contract = json_decode((string) file_get_contents($root . '/docs/roadmap/capacity-contract.json'), true);
if (!is_array($contract)) {
    fwrite(STDERR, "docs/roadmap/capacity-contract.json is unreadable.\n");
    exit(1);
}

/**
 * Advance a deterministic 32-bit xorshift state and return a float in [0, 1).
 *
 * @param   int  $state  RNG state, advanced in place.
 *
 * @return  float  The next deterministic sample.
 *
 * @since   2.0.0
 */
function nextSample(int &$state): float
{
    $state ^= ($state << 13) & 0xFFFFFFFF;
    $state ^= $state >> 17;
    $state ^= ($state << 5) & 0xFFFFFFFF;

    return ($state & 0x7FFFFFFF) / 0x80000000;
}

/**
 * Derive the deterministic measurement plan the contract's dataset rules describe.
 *
 * @param   array<string, mixed>  $contract  Decoded capacity contract.
 * @param   int                   $seed      Generator seed; the same seed always yields the same plan.
 * @param   string                $profile   Contract profile the run characterises against.
 * @param   int                   $samples   Measured samples per operation class.
 * @param   int                   $warmup    Warm-up iterations excluded from measurement.
 *
 * @return  array<string, mixed>  The plan, including per-class sample counts and line sizes.
 *
 * @since   2.0.0
 */
function buildPlan(array $contract, int $seed, string $profile, int $samples, int $warmup): array
{
    $state = $seed === 0 ? 0x1D872B41 : $seed;
    $shape = $contract['reference_installation_shape'] ?? [];
    $split = $shape['load_distribution']['default_split'] ?? [1.0];
    $distribution = $contract['envelope']['documents']['line_distribution'] ?? [];
    $largeSamples = max(3, intdiv($samples, 6));

    $businessOfSample = [];
    for ($index = 0; $index < $samples; $index++) {
        $draw = nextSample($state);
        $running = 0.0;
        $businessOfSample[$index] = count($split) - 1;
        foreach ($split as $business => $share) {
            $running += (float) $share;
            if ($draw < $running) {
                $businessOfSample[$index] = $business;
                break;
            }
        }
    }

    return [
        'harness' => 'kumwe-perf-harness',
        'stage' => 'P2-I seed: single-worker interactive characterisation',
        'seed' => $seed,
        'profile' => $profile,
        'profile_target' => $contract['profiles'][$profile] ?? null,
        'reference_shape' => [
            'businesses' => $shape['businesses'] ?? null,
            'load_split' => $split,
            'business_of_sample' => $businessOfSample,
        ],
        'line_distribution' => $distribution,
        'operation_classes' => [
            ['class' => 'bounded_primary_key_read', 'samples' => $samples, 'warmup' => $warmup],
            ['class' => 'indexed_policy_filtered_page', 'samples' => $samples, 'warmup' => $warmup],
            ['class' => 'ordinary_small_mutation', 'samples' => $samples, 'warmup' => $warmup],
            ['class' => 'hot_sequence_commit', 'samples' => $samples, 'warmup' => $warmup],
            ['class' => 'document_100_line_commit', 'samples' => $samples, 'warmup' => min($warmup, 2), 'lines' => 100],
            [
                'class' => 'document_1000_line_commit',
                'samples' => $largeSamples,
                'warmup' => 1,
                'lines' => 1000,
                'below_contract_sample_minimum' => $largeSamples < 30,
            ],
        ],
        'counting_rules' => [
            'lbt' => 'A document header plus its owned lines committed under one invariant is one LBT.',
            'prohibited' => 'No unqualified TPS figure is derived from this report.',
        ],
    ];
}

if ($mode === 'plan') {
    echo json_encode(buildPlan($contract, $seed, $profile, $samples, $warmup), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

require $root . '/vendor/autoload.php';

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Ramsey\Uuid\Uuid;

$plan = buildPlan($contract, $seed, $profile, $samples, $warmup);
$nonce = substr(bin2hex(random_bytes(6)), 0, 10);

$container = TestKernelFactory::create(Environment::fromGlobals());
$context = TestKernelFactory::administratorContext($container);
$records = $container->get(BusinessRecordService::class);
if (!$records instanceof BusinessRecordService) {
    fwrite(STDERR, "The business record service is unavailable.\n");
    exit(1);
}

$small = NeutralBusinessFixture::install($container, $context);
$lineDocument = NeutralBusinessFixture::documentLineDocument(
    NeutralBusinessFixture::DOCUMENT_SUFFIX,
    Uuid::uuid7()->toString(),
);
$lineHandle = $lineDocument['handle'];
$headerDocument = NeutralBusinessFixture::documentHeaderDocument(
    NeutralBusinessFixture::DOCUMENT_SUFFIX,
    Uuid::uuid7()->toString(),
    is_string($lineHandle) ? $lineHandle : '',
);
NeutralBusinessFixture::install($container, $context, $lineDocument);
$header = NeutralBusinessFixture::install($container, $context, $headerDocument);

/**
 * Build a consistent owned-line collection of the given size, each line worth one unit.
 *
 * @param   int     $size   How many lines the document carries.
 * @param   string  $stem   Uniqueness stem for the line codes.
 *
 * @return  list<DocumentLineInput>  The submitted collection.
 *
 * @since   2.0.0
 */
function documentLines(int $size, string $stem): array
{
    $lines = [];
    for ($index = 1; $index <= $size; $index++) {
        $lines[] = new DocumentLineInput([
            'code' => 'perf-' . $stem . '-' . $index,
            'description' => 'Perf line ' . $index,
            'amount' => '1.00',
        ]);
    }

    return $lines;
}

/**
 * Measure one operation repeatedly and summarise its latency distribution.
 *
 * @param   int                   $warmupRuns  Iterations executed and discarded before measuring.
 * @param   int                   $sampleRuns  Iterations measured.
 * @param   callable(int): void   $operation   The operation under measurement, given the iteration index.
 *
 * @return  array<string, mixed>  Sample count, milliseconds percentiles, mean and variation.
 *
 * @since   2.0.0
 */
function measure(int $warmupRuns, int $sampleRuns, callable $operation): array
{
    for ($index = 0; $index < $warmupRuns; $index++) {
        $operation(-1 - $index);
    }
    $durations = [];
    for ($index = 0; $index < $sampleRuns; $index++) {
        $start = hrtime(true);
        $operation($index);
        $durations[] = (hrtime(true) - $start) / 1_000_000;
    }
    sort($durations);
    $mean = array_sum($durations) / count($durations);
    $variance = 0.0;
    foreach ($durations as $duration) {
        $variance += ($duration - $mean) ** 2;
    }
    $deviation = sqrt($variance / count($durations));
    $percentile = static function (float $rank) use ($durations): float {
        $index = (int) ceil($rank * count($durations)) - 1;

        return $durations[max(0, min($index, count($durations) - 1))];
    };

    return [
        'samples' => count($durations),
        'p50_ms' => round($percentile(0.50), 2),
        'p95_ms' => round($percentile(0.95), 2),
        'p99_ms' => round($percentile(0.99), 2),
        'mean_ms' => round($mean, 2),
        'coefficient_of_variation' => $mean > 0.0 ? round($deviation / $mean, 3) : 0.0,
    ];
}

$results = [];
$cleanup = [];

$seedRecordId = Uuid::uuid7()->toString();
$records->create(new CreateRecordCommand(
    $context,
    $small->handle,
    NeutralBusinessFixture::recordValues('Perf seed record ' . $nonce),
    NeutralBusinessFixture::idempotencyKey('perf-seed-' . $nonce),
    recordId: $seedRecordId,
));
$cleanup[] = [$small->handle, $seedRecordId];

$results['bounded_primary_key_read'] = measure($warmup, $samples, function () use ($records, $context, $small, $seedRecordId): void {
    $records->read(new ReadRecordQuery($context, $small->handle, $seedRecordId));
});

$results['indexed_policy_filtered_page'] = measure($warmup, $samples, function () use ($records, $context, $small): void {
    $records->browse(new BrowseRecordsQuery($context, $small->handle, new RecordQuerySpecification()));
});

$results['ordinary_small_mutation'] = measure($warmup, $samples, function (int $index) use ($records, $context, $small, $nonce, &$cleanup): void {
    $recordId = Uuid::uuid7()->toString();
    $records->create(new CreateRecordCommand(
        $context,
        $small->handle,
        NeutralBusinessFixture::recordValues('Perf record ' . $nonce . ' ' . $index),
        NeutralBusinessFixture::idempotencyKey('perf-create-' . $nonce . '-' . ($index + 1000)),
        recordId: $recordId,
    ));
    $cleanup[] = [$small->handle, $recordId];
});

// One counter takes every commit of this class, so the sample distribution is the sustained
// hot-sequence worst case decision D1's envelope names: a legally constrained single gapless
// sequence, serialized on its counter row. ADR 0011 records the transition model this binds to.
$sequencedDocument = NeutralBusinessFixture::document(
    'perfseq' . $nonce,
    Uuid::uuid7()->toString(),
);
$sequencedDocument['fields'][] = [
    'handle' => 'document_number',
    'label' => 'Document number',
    'type' => 'core.sequence',
    'configuration' => [
        'scope' => 'site',
        'reset' => 'yearly',
        'prefix' => 'PERF-',
        'padding' => 6,
        'timezone' => 'UTC',
    ],
    'required' => true,
    'nullable' => false,
    'length' => 36,
    'unique' => true,
    'indexed' => true,
    'immutable_after_create' => true,
    'server_only' => true,
    'read_only' => true,
    'sortable' => true,
    'filterable' => true,
];
$sequenced = NeutralBusinessFixture::install($container, $context, $sequencedDocument);
$results['hot_sequence_commit'] = measure($warmup, $samples, function (int $index) use (
    $records,
    $context,
    $sequenced,
    $nonce,
    &$cleanup
): void {
    $recordId = Uuid::uuid7()->toString();
    $records->create(new CreateRecordCommand(
        $context,
        $sequenced->handle,
        NeutralBusinessFixture::recordValues('Perf sequence ' . $nonce . ' ' . $index),
        NeutralBusinessFixture::idempotencyKey('perf-seq-' . $nonce . '-' . ($index + 1000)),
        recordId: $recordId,
    ));
    $cleanup[] = [$sequenced->handle, $recordId];
});

foreach ([100 => 'document_100_line_commit', 1000 => 'document_1000_line_commit'] as $size => $class) {
    $classPlan = null;
    foreach ($plan['operation_classes'] as $candidate) {
        if ($candidate['class'] === $class) {
            $classPlan = $candidate;
        }
    }
    $documents = [];
    $results[$class] = measure(
        (int) ($classPlan['warmup'] ?? 1),
        (int) ($classPlan['samples'] ?? $samples),
        function (int $index) use ($records, $context, $header, $size, $nonce, &$documents): void {
            $documentId = Uuid::uuid7()->toString();
            $stem = $nonce . '-' . $size . '-' . ($index + 100);
            $result = $records->writeDocument(new WriteDocumentCommand(
                $context,
                $header->handle,
                'lines',
                ['title' => 'Perf document ' . $stem, 'total' => number_format($size, 2, '.', '')],
                documentLines($size, $stem),
                NeutralBusinessFixture::idempotencyKey('perf-doc-' . $stem),
                recordId: $documentId,
            ));
            $documents[] = [$documentId, $result->version];
        },
    );
    foreach ($documents as $position => [$documentId, $version]) {
        $records->delete(new DeleteRecordCommand(
            $context,
            $header->handle,
            $documentId,
            $version,
            NeutralBusinessFixture::idempotencyKey('perf-doc-drop-' . $nonce . '-' . $size . '-' . $position),
        ));
    }
}

foreach ($cleanup as $position => [$handle, $recordId]) {
    $read = $records->read(new ReadRecordQuery($context, $handle, $recordId));
    $records->delete(new DeleteRecordCommand(
        $context,
        $handle,
        $recordId,
        $read->version,
        NeutralBusinessFixture::idempotencyKey('perf-drop-' . $nonce . '-' . $position),
    ));
}

$objectives = $contract['service_level_objectives'] ?? [];
$verdicts = [];
foreach ($results as $class => $stats) {
    $objective = $objectives[$class] ?? null;
    if (!is_array($objective) || !isset($objective['p95_ms'])) {
        $verdicts[$class] = 'no objective declared';
        continue;
    }
    $within = $stats['p95_ms'] <= $objective['p95_ms']
        && (!isset($objective['p99_ms']) || $stats['p99_ms'] <= $objective['p99_ms']);
    $verdicts[$class] = $within ? 'within objective' : 'outside objective';
}

$commit = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'));
$engineVersion = '';
try {
    $probe = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            (string) getenv('DB_HOST') ?: '127.0.0.1',
            (string) getenv('DB_PORT') ?: '3306',
            (string) getenv('DB_NAME') ?: 'kumwe_test',
        ),
        (string) getenv('DB_USER') ?: 'kumwe',
        (string) getenv('DB_PASSWORD') ?: '',
    );
    $engineVersion = (string) $probe->query('SELECT VERSION()')->fetchColumn();
} catch (Throwable) {
    $engineVersion = 'unavailable';
}

$report = [
    'plan' => $plan,
    'result_binding' => [
        'source_commit' => $commit,
        'engine' => (string) getenv('DB_DRIVER') ?: 'mariadb',
        'engine_version' => $engineVersion,
        'php_version' => PHP_VERSION,
        'measured_at' => gmdate('c'),
        'runner' => 'unqualified host: latency characterisation only, never an absolute-throughput authority',
    ],
    'measurements' => $results,
    'slo_verdicts' => $verdicts,
    'limitations' => [
        'single worker: no concurrency, contention or fence profile is measured',
        'no write-amplification (PRM/LBT) figure: physical-row instrumentation is a later P2-I stage',
        'no breakpoint: throughput ramping is a later P2-I stage',
        'no plan capture: EXPLAIN regression assertions are a later P2-I stage',
        'document_1000_line_commit may run below the contract sample minimum; its plan entry says so',
    ],
];

if (!is_dir($root . '/build/perf')) {
    mkdir($root . '/build/perf', 0775, true);
}
file_put_contents(
    $root . '/build/perf/report.json',
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);

foreach ($results as $class => $stats) {
    printf(
        "%-30s p50 %8.1fms  p95 %8.1fms  p99 %8.1fms  cv %.3f  %s\n",
        $class,
        $stats['p50_ms'],
        $stats['p95_ms'],
        $stats['p99_ms'],
        $stats['coefficient_of_variation'],
        $verdicts[$class],
    );
}
printf("Report written to build/perf/report.json (seed %d, profile %s).\n", $seed, $profile);
