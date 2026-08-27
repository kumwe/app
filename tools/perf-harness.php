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
 * Three modes:
 *
 *   php tools/perf-harness.php --plan       [--seed=N] [--profile=baseline|enterprise|stretch]
 *   php tools/perf-harness.php --run        [--seed=N] [--profile=...] [--samples=N] [--warmup=N]
 *   php tools/perf-harness.php --breakpoint [--seed=N] [--samples=N]
 *
 * `--plan` is pure: the same seed always prints byte-identical JSON, which is what makes the dataset
 * reproducible and lets a unit test hold the generator to it. `--run` needs the test database
 * environment (source `.agent-env`, or run inside the CI database job) and measures, per operation
 * class: bounded primary-key reads, policy-filtered page browses, ordinary small mutations, and
 * 100- and 1000-line document commits — reporting p50/p95/p99, mean, coefficient of variation and
 * the contract's SLO verdicts to `build/perf/report.json`.
 *
 * `--run` also measures write amplification the way the contract counts it — physical row mutations
 * per logical business transaction, from real row-count deltas across the header, line, revision,
 * audit, idempotency, outbox and projection-source tables around one commit — and refuses to write a
 * report that does not validate against `docs/quality/perf-report.schema.json`, so the result schema
 * is a contract rather than a habit. `--breakpoint` ramps the document line axis twice and records
 * where the measured p95 first crosses the interpolated commit objective: a baseline fact about this
 * host and this commit, never a capacity claim. The run fails when the two knees disagree or a p95
 * pair diverges by both the relative tolerance and a material share of its objective, because the
 * phase-2 exit gate asks for a report that is *stable*, not merely produced.
 *
 * Honesty over reach, per the contract's own rules: this harness measures single-worker latency on
 * whatever host runs it, so every report binds the engine, commit, seed and sample counts but claims
 * no absolute-throughput authority ("shared CI runners are never the authority") and no concurrency
 * or contention profile. Hot-plan capture lives in the integration gate
 * (tests/Integration/Performance/HotPlanRegressionIntegrationTest.php) where every engine runs it.
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
    if ($argument === '--plan' || $argument === '--run' || $argument === '--breakpoint') {
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
    fwrite(STDERR, "Pass --plan, --run or --breakpoint. See the file header for usage.\n");
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
        'schema' => 'docs/quality/perf-report.schema.json',
        'stage' => 'P2-I: single-worker interactive characterisation',
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

/**
 * Hold a built document to the declared result schema, reporting every divergence at once.
 *
 * The validator speaks the small structural dialect the schema file uses — required keys with a
 * declared type, nothing more — because the point is not general JSON Schema power but that a report
 * the tooling writes and a report the schema promises cannot quietly drift apart.
 *
 * @param   array<string, mixed>  $document  Report or breakpoint document about to be written.
 * @param   string                $section   Key of the schema file describing this document kind.
 * @param   string                $root      Repository root holding the schema file.
 *
 * @return  list<string>  Human-readable divergences; empty when the document conforms.
 *
 * @since   2.0.0
 */
function schemaDivergences(array $document, string $section, string $root): array
{
    $schema = json_decode((string) file_get_contents($root . '/docs/quality/perf-report.schema.json'), true);
    if (!is_array($schema) || !is_array($schema[$section] ?? null)) {
        return [sprintf('The result schema declares no "%s" section.', $section)];
    }
    $divergences = [];
    /** @var array<string, string> $required */
    $required = $schema[$section]['required'] ?? [];
    foreach ($required as $key => $type) {
        if (!array_key_exists($key, $document)) {
            $divergences[] = sprintf('%s: required key "%s" is absent.', $section, $key);
            continue;
        }
        $actual = get_debug_type($document[$key]);
        $matches = match ($type) {
            'object', 'array' => is_array($document[$key]),
            'number' => is_int($document[$key]) || is_float($document[$key]),
            'string' => is_string($document[$key]),
            'boolean' => is_bool($document[$key]),
            default => false,
        };
        if (!$matches) {
            $divergences[] = sprintf('%s: key "%s" must be %s, found %s.', $section, $key, $type, $actual);
        }
    }

    return $divergences;
}

if ($mode === 'plan') {
    echo json_encode(buildPlan($contract, $seed, $profile, $samples, $warmup), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

require $root . '/vendor/autoload.php';
require $root . '/tools/PerfBreakpointStability.php';

use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DeleteRecordCommand;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Query\BrowseRecordsQuery;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\BusinessRecord\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tools\PerfBreakpointStability;
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

/**
 * Count the live rows of every table one committed document touches, keyed by the ledger's name.
 *
 * @param   Connection             $connection  Live connection of the engine under measurement.
 * @param   array<string, string>  $tables      Quoted physical table names keyed by ledger name.
 *
 * @return  array<string, int>  Current row count per ledger.
 *
 * @since   2.0.0
 */
function ledgerRowCounts(Connection $connection, array $tables): array
{
    $counts = [];
    foreach ($tables as $ledger => $quoted) {
        $counts[$ledger] = (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $quoted);
    }

    return $counts;
}

$connection = $container->get(Connection::class);
if (!$connection instanceof Connection) {
    fwrite(STDERR, "The shared connection is unavailable.\n");
    exit(1);
}
$tableNames = $container->get(TableNames::class);
$installations = $container->get(BusinessSchemaInstallationRepository::class);
if (!$tableNames instanceof TableNames || !$installations instanceof BusinessSchemaInstallationRepository) {
    fwrite(STDERR, "The table-name services are unavailable.\n");
    exit(1);
}
$headerInstallation = $installations->find($header->id);
$headerTable = $headerInstallation?->blueprint->table('record');
$lineTable = $headerInstallation?->blueprint->table('line:lines');
if ($headerTable === null || $lineTable === null) {
    fwrite(STDERR, "The document fixture's installed tables are unavailable.\n");
    exit(1);
}
$platform = $connection->getDatabasePlatform();
$ledgerTables = [
    'header' => $platform->quoteSingleIdentifier($headerTable->physicalName),
    'lines' => $platform->quoteSingleIdentifier($lineTable->physicalName),
    'revisions' => $tableNames->quoted('business_record_revisions'),
    'audit' => $tableNames->quoted('audit_events'),
    'idempotency' => $tableNames->quoted('business_command_idempotency'),
    'outbox' => $tableNames->quoted('integration_outbox'),
    'projection_source' => $tableNames->quoted('business_projection_source_events'),
];

$binding = static function () use ($root, $connection): array {
    $commit = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'));
    $engineVersion = 'unavailable';
    try {
        $engineVersion = (string) $connection->fetchOne('SELECT VERSION()');
    } catch (Throwable) {
    }

    return [
        'source_commit' => $commit,
        'engine' => (string) getenv('DB_DRIVER') ?: 'mariadb',
        'engine_version' => $engineVersion,
        'php_version' => PHP_VERSION,
        'measured_at' => gmdate('c'),
        'runner' => 'unqualified host: latency characterisation only, never an absolute-throughput authority',
    ];
};

if ($mode === 'breakpoint') {
    $objectives = $contract['service_level_objectives'] ?? [];
    $floor = (float) ($objectives['document_100_line_commit']['p95_ms'] ?? 2000);
    $ceiling = (float) ($objectives['document_1000_line_commit']['p95_ms'] ?? 8000);
    // The objective between the two declared sizes is interpolated on the line axis, because the
    // contract prices the commit by its lines and declares the two ends of that price.
    $budgetAt = static fn (int $size): float => $floor + ($size - 100) * ($ceiling - $floor) / 900.0;
    $sizes = [100, 200, 500, 1000];
    $rampSamples = max(3, intdiv($samples, 10));
    $passes = [];
    $knees = [];
    for ($pass = 0; $pass < 2; ++$pass) {
        $measured = [];
        $knee = null;
        foreach ($sizes as $size) {
            $documents = [];
            $stats = measure(1, $rampSamples, function (int $index) use (
                $records,
                $context,
                $header,
                $size,
                $nonce,
                $pass,
                &$documents
            ): void {
                $documentId = Uuid::uuid7()->toString();
                $stem = $nonce . '-bp' . $pass . '-' . $size . '-' . ($index + 100);
                $result = $records->writeDocument(new WriteDocumentCommand(
                    $context,
                    $header->handle,
                    'lines',
                    ['title' => 'Breakpoint document ' . $stem, 'total' => number_format($size, 2, '.', '')],
                    documentLines($size, $stem),
                    NeutralBusinessFixture::idempotencyKey('perf-bp-' . $stem),
                    recordId: $documentId,
                ));
                $documents[] = [$documentId, $result->version];
            });
            foreach ($documents as $position => [$documentId, $version]) {
                $records->delete(new DeleteRecordCommand(
                    $context,
                    $header->handle,
                    $documentId,
                    $version,
                    NeutralBusinessFixture::idempotencyKey(
                        'perf-bp-drop-' . $nonce . '-' . $pass . '-' . $size . '-' . $position,
                    ),
                ));
            }
            $measured[] = [
                'lines' => $size,
                'p95_ms' => $stats['p95_ms'],
                'budget_p95_ms' => round($budgetAt($size), 1),
                'over_budget' => $stats['p95_ms'] > $budgetAt($size),
            ];
            if ($knee === null && $stats['p95_ms'] > $budgetAt($size)) {
                $knee = $size;
            }
        }
        $passes[] = $measured;
        $knees[] = $knee;
    }
    $pairs = [];
    foreach ($sizes as $index => $size) {
        $pairs[] = [
            'first_p95_ms' => (float) $passes[0][$index]['p95_ms'],
            'second_p95_ms' => (float) $passes[1][$index]['p95_ms'],
            'budget_p95_ms' => $budgetAt($size),
        ];
    }
    $stable = PerfBreakpointStability::agrees($knees[0], $knees[1], $pairs);
    $breakpoint = [
        'harness' => 'kumwe-perf-harness',
        'schema' => 'docs/quality/perf-report.schema.json',
        'role' => 'baseline fact about this host and this commit; never a capacity claim',
        'seed' => $seed,
        'result_binding' => $binding(),
        'sizes' => $sizes,
        'passes' => $passes,
        'knee' => [
            'first_pass_lines' => $knees[0],
            'second_pass_lines' => $knees[1],
            'meaning' => $knees[0] === null
                ? 'no measured size crossed its interpolated commit objective on this host'
                : sprintf('p95 first crossed the interpolated commit objective at %d lines', $knees[0]),
        ],
        'stable' => $stable,
    ];
    $divergences = schemaDivergences($breakpoint, 'breakpoint', $root);
    if ($divergences !== []) {
        fwrite(STDERR, "The breakpoint document violates the declared result schema:\n");
        foreach ($divergences as $divergence) {
            fwrite(STDERR, '  - ' . $divergence . "\n");
        }
        exit(1);
    }
    if (!is_dir($root . '/build/perf')) {
        mkdir($root . '/build/perf', 0775, true);
    }
    file_put_contents(
        $root . '/build/perf/breakpoint.json',
        json_encode($breakpoint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    foreach ($sizes as $index => $size) {
        printf(
            "%5d lines  p95 %8.1fms / budget %8.1fms   p95 %8.1fms / budget %8.1fms\n",
            $size,
            $passes[0][$index]['p95_ms'],
            $passes[0][$index]['budget_p95_ms'],
            $passes[1][$index]['p95_ms'],
            $passes[1][$index]['budget_p95_ms'],
        );
    }
    printf(
        "Breakpoint report written to build/perf/breakpoint.json (%s).\n",
        $stable ? 'stable across both passes' : 'UNSTABLE: the two passes disagree',
    );
    exit($stable ? 0 : 1);
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

// Write amplification, counted the way the contract counts it: PRM/LBT from real row deltas across
// every ledger one commit feeds, around exactly one logical business transaction per document class.
$amplification = [];
foreach ([100 => 'document_100_line_commit', 1000 => 'document_1000_line_commit'] as $size => $class) {
    $before = ledgerRowCounts($connection, $ledgerTables);
    $documentId = Uuid::uuid7()->toString();
    $stem = $nonce . '-prm-' . $size;
    $result = $records->writeDocument(new WriteDocumentCommand(
        $context,
        $header->handle,
        'lines',
        ['title' => 'Amplification document ' . $stem, 'total' => number_format($size, 2, '.', '')],
        documentLines($size, $stem),
        NeutralBusinessFixture::idempotencyKey('perf-prm-' . $stem),
        recordId: $documentId,
    ));
    $after = ledgerRowCounts($connection, $ledgerTables);
    $mutations = [];
    $total = 0;
    foreach ($ledgerTables as $ledger => $_quoted) {
        $mutations[$ledger] = $after[$ledger] - $before[$ledger];
        $total += max(0, $after[$ledger] - $before[$ledger]);
    }
    $amplification[$class] = [
        'lbt' => 1,
        'physical_row_mutations' => $total,
        'prm_per_lbt' => $total,
        'rows_by_ledger' => $mutations,
    ];
    $records->delete(new DeleteRecordCommand(
        $context,
        $header->handle,
        $documentId,
        $result->version,
        NeutralBusinessFixture::idempotencyKey('perf-prm-drop-' . $stem),
    ));
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

$report = [
    'plan' => $plan,
    'result_binding' => $binding(),
    'measurements' => $results,
    'write_amplification' => $amplification,
    'slo_verdicts' => $verdicts,
    'limitations' => [
        'single worker: no concurrency, contention or fence profile is measured',
        'breakpoint characterisation is its own mode (--breakpoint) and its own document',
        'hot-plan capture lives in the integration gate, where every engine runs it',
        'document_1000_line_commit may run below the contract sample minimum; its plan entry says so',
    ],
];
$divergences = schemaDivergences($report, 'report', $root);
if ($divergences !== []) {
    fwrite(STDERR, "The report violates the declared result schema and was not written:\n");
    foreach ($divergences as $divergence) {
        fwrite(STDERR, '  - ' . $divergence . "\n");
    }
    exit(1);
}

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
foreach ($amplification as $class => $figures) {
    printf(
        "%-30s PRM/LBT %d\n",
        $class,
        $figures['prm_per_lbt'],
    );
}
printf("Report written to build/perf/report.json (seed %d, profile %s).\n", $seed, $profile);
