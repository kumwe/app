<?php

/**
 * Record the reproducible baseline this release is made of (`P0-A`).
 *
 * The programme has always had this document; it was written by hand, which is why the copy in
 * docs/roadmap/STATUS.md described a revision nine hundred commits behind before anyone noticed. A
 * baseline nobody can regenerate is a baseline nobody can trust, so this derives every figure from the
 * repository and refuses to invent any of them.
 *
 * Determinism is the contract: run twice at one commit and the semantic result is identical. Nothing
 * here reads the clock, the machine or the network. Values that genuinely belong to a verification run
 * rather than to the tree — the runs that produced the evidence — arrive as arguments.
 *
 * Usage:
 *   php tools/record-baseline.php --emit --commit=SHA --recorded-at=YYYY-MM-DD [--run=URL ...]
 *       [--write]
 *   php tools/record-baseline.php --check
 *
 * @since  2.0.0
 */

declare(strict_types=1);

/**
 * Read a file, or fail loudly rather than silently recording nothing.
 *
 * @param   string  $path  Absolute path to read.
 *
 * @return  string  File contents.
 *
 * @since   2.0.0
 */
function baselineRead(string $path): string
{
    $contents = @file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, sprintf("The baseline needs %s and could not read it.\n", $path));

        exit(1);
    }

    return $contents;
}

/**
 * Decode a JSON document the baseline derives figures from.
 *
 * @param   string  $path  Absolute path to a JSON file.
 *
 * @return  array<string, mixed>  Decoded document.
 *
 * @since   2.0.0
 */
function baselineJson(string $path): array
{
    /** @var mixed $decoded */
    $decoded = json_decode(baselineRead($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, sprintf("%s is not a JSON object.\n", $path));

        exit(1);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * List every PHP file below a directory, in a stable order.
 *
 * Sorting is explicit because filesystem iteration order is not a contract, and a document whose
 * ordering depends on the machine that produced it cannot be diffed against the committed one.
 *
 * @param   string  $directory  Directory to walk.
 *
 * @return  list<string>  Absolute paths, sorted.
 *
 * @since   2.0.0
 */
function baselinePhpFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }
    $files = [];
    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($tree as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files, SORT_STRING);

    return $files;
}

/**
 * Count the test files and test methods each declared suite carries.
 *
 * @param   string  $root  Repository root.
 *
 * @return  array<string, array{files: int, test_methods: int}>  Inventory by suite name.
 *
 * @since   2.0.0
 */
function baselineTestInventory(string $root): array
{
    $configuration = baselineRead($root . '/phpunit.xml.dist');
    $suites = [];
    if (preg_match_all('#<testsuite name="([^"]+)">(.*?)</testsuite>#s', $configuration, $matches) < 1) {
        return $suites;
    }
    foreach ($matches[1] as $index => $name) {
        preg_match_all('#<directory[^>]*>([^<]+)</directory>#', $matches[2][$index], $directories);
        $files = 0;
        $methods = 0;
        foreach ($directories[1] as $relative) {
            foreach (baselinePhpFiles($root . '/' . trim($relative)) as $file) {
                $files++;
                $methods += preg_match_all('/\n\s*public function test[A-Za-z0-9_]*\s*\(/', baselineRead($file));
            }
        }
        $suites[$name] = ['files' => $files, 'test_methods' => $methods];
    }
    ksort($suites, SORT_STRING);

    return $suites;
}

/**
 * Read every HTTP route the composition root declares, by name.
 *
 * @param   string  $root  Repository root.
 *
 * @return  list<array{method: string, path: string, name: string}>  Declared routes, sorted by name.
 *
 * @since   2.0.0
 */
function baselineRoutes(string $root): array
{
    $source = baselineRead($root . '/src/Kernel/ContainerFactory.php');
    preg_match_all(
        '/\$application->(get|post|put|patch|delete|any)\(\s*\'([^\']+)\'\s*,[^,]+,\s*\'([^\']+)\'/',
        $source,
        $matches,
        PREG_SET_ORDER,
    );
    $routes = [];
    foreach ($matches as $match) {
        $routes[] = ['method' => strtoupper($match[1]), 'path' => $match[2], 'name' => $match[3]];
    }
    usort($routes, static fn (array $a, array $b): int => [$a['name'], $a['method']] <=> [$b['name'], $b['method']]);

    return $routes;
}

/**
 * Read every console command the release ships, by the token an operator types.
 *
 * @param   string  $root  Repository root.
 *
 * @return  list<string>  Command names, sorted.
 *
 * @since   2.0.0
 */
function baselineConsoleCommands(string $root): array
{
    $names = [];
    foreach (['/src/Delivery/Console/Command', '/src/BusinessReporting/Delivery/Console'] as $relative) {
        foreach (baselinePhpFiles($root . $relative) as $file) {
            $source = baselineRead($file);
            if (preg_match('/public function name\(\): string\s*\{\s*return \'([^\']+)\'/', $source, $match) === 1) {
                $names[] = $match[1];
            }
        }
    }
    sort($names, SORT_STRING);

    return $names;
}

/**
 * Read the operations the published OpenAPI contract declares.
 *
 * @param   string  $root  Repository root.
 *
 * @return  array{title: string, version: string, paths: int, operations: int}  Contract shape.
 *
 * @since   2.0.0
 */
function baselineOpenApi(string $root): array
{
    $document = baselineJson($root . '/api/openapi/kumwe-v1.json');
    $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];
    $operations = 0;
    foreach ($paths as $item) {
        if (!is_array($item)) {
            continue;
        }
        foreach (array_keys($item) as $method) {
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
                $operations++;
            }
        }
    }
    $info = is_array($document['info'] ?? null) ? $document['info'] : [];

    return [
        'title' => is_string($info['title'] ?? null) ? $info['title'] : '',
        'version' => is_string($info['version'] ?? null) ? $info['version'] : '',
        'paths' => count($paths),
        'operations' => $operations,
    ];
}

/**
 * Read the published migrations, whose applied bytes are immutable.
 *
 * @param   string  $root  Repository root.
 *
 * @return  list<string>  Migration identifiers, sorted.
 *
 * @since   2.0.0
 */
function baselineMigrations(string $root): array
{
    $identifiers = [];
    foreach (baselinePhpFiles($root . '/src/Infrastructure/Persistence/Migration') as $file) {
        // The identifier is a class constant the runner reads; the declaration is what is immutable.
        if (preg_match('/const string ID = \'([^\']+)\'/', baselineRead($file), $m) === 1) {
            $identifiers[] = $m[1];
        }
    }
    sort($identifiers, SORT_STRING);

    return $identifiers;
}

/**
 * Record every test the suite is allowed to skip, and every exemption a gate still carries.
 *
 * This is the half of the baseline that a hand-written one always flatters: what did not run, and what
 * is excused. Each figure is derived, so it moves when the debt moves.
 *
 * @param   string  $root  Repository root.
 *
 * @return  array<string, mixed>  Skips and recorded exemptions.
 *
 * @since   2.0.0
 */
function baselineQuarantine(string $root): array
{
    $skips = [];
    foreach (['Unit', 'Integration', 'Functional', 'Architecture'] as $suite) {
        foreach (baselinePhpFiles($root . '/tests/' . $suite) as $file) {
            $count = preg_match_all('/\bmarkTestSkipped\s*\(/', baselineRead($file));
            if ($count > 0) {
                $skips[substr($file, strlen($root) + 1)] = $count;
            }
        }
    }
    ksort($skips, SORT_STRING);

    $exemptions = [];
    foreach ([
        'dependency-graph' => '/docs/architecture/dependency-baseline.json',
        'idempotency' => '/docs/quality/idempotency-baseline.json',
        'test-documentation' => '/docs/quality/test-docblock-baseline.json',
    ] as $name => $relative) {
        if (!is_file($root . $relative)) {
            continue;
        }
        $document = baselineJson($root . $relative);
        $entries = $document['entries'] ?? $document['violations'] ?? [];
        $exemptions[$name] = is_array($entries) ? count($entries) : 0;
    }
    ksort($exemptions, SORT_STRING);

    return ['skip_sites_by_file' => $skips, 'recorded_exemptions' => $exemptions];
}

/**
 * Build the whole baseline document.
 *
 * @param   string        $root        Repository root.
 * @param   string        $commit      Commit the baseline describes.
 * @param   string        $recordedAt  Date the record was taken.
 * @param   list<string>  $runs        Verification runs the evidence came from.
 *
 * @return  array<string, mixed>  The baseline document.
 *
 * @since   2.0.0
 */
function baselineDocument(string $root, string $commit, string $recordedAt, array $runs): array
{
    $composer = baselineJson($root . '/composer.json');
    $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
    $coverage = baselineJson($root . '/docs/quality/coverage-contract.json');
    $attribution = is_array($coverage['attribution'] ?? null) ? $coverage['attribution'] : [];
    $extensions = array_values(array_filter(
        array_keys($require),
        static fn (string $name): bool => str_starts_with($name, 'ext-'),
    ));
    sort($extensions, SORT_STRING);

    return [
        'baseline' => 'kumwe-reproducible-baseline',
        'work_package' => 'P0-A',
        'authority' => 'docs/roadmap/README.md',
        'note' => 'Every figure below is derived from the repository at the recorded commit. Nothing is '
            . 'typed by hand, nothing reads the clock or the machine, and running the generator twice at '
            . 'one commit produces the same document. composer baseline:check fails when it drifts.',
        'commit' => $commit,
        'recorded_at' => $recordedAt,
        'recorded_from' => $runs,
        'toolchain' => [
            'php' => is_string($require['php'] ?? null) ? $require['php'] : '',
            'required_extensions' => $extensions,
            'composer_lock_sha256' => hash('sha256', baselineRead($root . '/composer.lock')),
            'package_lock_sha256' => is_file($root . '/package-lock.json')
                ? hash('sha256', baselineRead($root . '/package-lock.json'))
                : null,
        ],
        'tests' => baselineTestInventory($root),
        'coverage' => [
            'canonical_engine' => is_string($coverage['canonical_engine'] ?? null)
                ? $coverage['canonical_engine']
                : '',
            'ratchets' => is_array($coverage['ratchets'] ?? null) ? count($coverage['ratchets']) : 0,
            'recorded_line_coverage_percent' => is_array($coverage['baseline'] ?? null)
                ? ($coverage['baseline']['line_coverage_percent'] ?? null)
                : null,
            'reasoned_attribution_rules' => is_array($attribution['reasoned'] ?? null)
                ? count($attribution['reasoned'])
                : 0,
            'tests_owing_attribution' => is_array($attribution['pending'] ?? null)
                ? count($attribution['pending'])
                : 0,
        ],
        'surfaces' => [
            'http_routes' => baselineRoutes($root),
            'console_commands' => baselineConsoleCommands($root),
            'openapi' => baselineOpenApi($root),
            'migrations' => baselineMigrations($root),
        ],
        'quarantine' => baselineQuarantine($root),
    ];
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$emit = in_array('--emit', $arguments, true);
$check = in_array('--check', $arguments, true);
$write = in_array('--write', $arguments, true);
$recordedAt = '';
$commit = '';
$runs = [];
$path = $root . '/docs/quality/baseline.json';

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--recorded-at=')) {
        $recordedAt = substr($argument, strlen('--recorded-at='));
        continue;
    }
    if (str_starts_with($argument, '--commit=')) {
        $commit = substr($argument, strlen('--commit='));
        continue;
    }
    if (str_starts_with($argument, '--run=')) {
        $runs[] = substr($argument, strlen('--run='));
        continue;
    }
    if (str_starts_with($argument, '--baseline=')) {
        $path = substr($argument, strlen('--baseline='));
    }
}

if (!$emit && !$check) {
    fwrite(STDERR, "Usage: php tools/record-baseline.php --emit [--recorded-at=DATE] [--run=URL ...] | --check\n");

    exit(1);
}

// `--emit` is documented as writing to the record's own path, and a shell redirect truncates that file
// before this process starts. Reading it back would then fail on a document the redirect emptied, so an
// empty file is treated as no record at all — exactly as a missing one is. A file with bytes in it that
// are not a JSON object is still an error, because that is corruption rather than a redirect in flight.
$recorded = is_file($path) && trim(baselineRead($path)) !== '' ? baselineJson($path) : [];

if ($check) {
    // The committed record carries its own commit, date and runs; the check re-derives everything else
    // and compares. Those three are what the run knew and the tree cannot, so they are carried over
    // rather than re-invented, and every other figure has to match exactly.
    $carriedCommit = is_string($recorded['commit'] ?? null) ? $recorded['commit'] : '';
    $carriedDate = is_string($recorded['recorded_at'] ?? null) ? $recorded['recorded_at'] : '';
    $carriedRuns = is_array($recorded['recorded_from'] ?? null) ? array_values($recorded['recorded_from']) : [];
    /** @var list<string> $carriedRuns */
    $expected = baselineDocument($root, $carriedCommit, $carriedDate, $carriedRuns);
    if ($recorded === []) {
        fwrite(STDERR, sprintf("%s is missing. Record it with composer baseline:record.\n", $path));

        exit(1);
    }
    if ($recorded !== $expected) {
        fwrite(
            STDERR,
            "The recorded baseline no longer describes the tree. Re-record it with composer "
            . "baseline:record and commit the result.\n",
        );
        foreach (array_keys($expected) as $section) {
            if (($recorded[$section] ?? null) !== $expected[$section]) {
                fwrite(STDERR, sprintf("  - %s has drifted.\n", $section));
            }
        }

        exit(1);
    }
    fwrite(STDOUT, sprintf(
        "Kumwe baseline verified: %d route(s), %d command(s), %d migration(s), %d OpenAPI operation(s).\n",
        count($expected['surfaces']['http_routes']),
        count($expected['surfaces']['console_commands']),
        count($expected['surfaces']['migrations']),
        $expected['surfaces']['openapi']['operations'],
    ));

    exit(0);
}

// Provenance is never inherited. Carrying the previous commit, date or run forward is how a record
// came to describe a revision it had not been generated from: the figures were re-derived correctly and
// the three fields that say where they came from kept pointing at an older tree, through a rebase that
// replayed cleanly because a data file always does. `--check` still carries them over, because the tree
// genuinely cannot know them; recording is the one moment they are known, so they are demanded here.
if ($commit === '') {
    fwrite(STDERR, "--emit needs --commit=SHA naming the revision these figures were derived from.\n");
    fwrite(STDERR, "composer baseline:record supplies it, and the date, from the checkout.\n");

    exit(1);
}
if (preg_match('/^[0-9a-f]{40}$/D', $commit) !== 1) {
    // A short or malformed SHA reads as provenance while naming nothing that can be resolved later,
    // which is the same failure as carrying an older one forward: the record looks sourced and is not.
    fwrite(STDERR, "--commit must be a full 40-character commit SHA.\n");

    exit(1);
}
if ($recordedAt === '') {
    fwrite(STDERR, "--emit needs --recorded-at=YYYY-MM-DD.\n");
    fwrite(STDERR, "composer baseline:record supplies it, and the commit, from the checkout.\n");

    exit(1);
}
$recordedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $recordedAt);
if ($recordedDate === false || $recordedDate->format('Y-m-d') !== $recordedAt) {
    fwrite(STDERR, "--recorded-at must be a calendar date as YYYY-MM-DD.\n");

    exit(1);
}

$document = json_encode(
    baselineDocument($root, $commit, $recordedAt, $runs),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
) . "\n";

if (!$write) {
    fwrite(STDOUT, $document);

    exit(0);
}

// Written through a neighbouring temporary file and renamed over the record, so an interrupted run
// leaves the previous document intact rather than a truncated one. A shell redirect cannot do this: it
// empties the target before this process starts, which is why `--write` exists at all.
$temporary = $path . '.tmp';
if (file_put_contents($temporary, $document) === false) {
    fwrite(STDERR, sprintf("%s could not be written.\n", $temporary));

    exit(1);
}
if (!rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, sprintf("%s could not be replaced.\n", $path));

    exit(1);
}
fwrite(STDOUT, sprintf("Recorded %s at %s.\n", $path, $commit));

exit(0);
