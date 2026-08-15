<?php

/**
 * Run the integration suite again against a used database, and judge the result against the baseline.
 *
 * The suite is not idempotent: run it a second time against the database the first run left behind and
 * tests fail because state a previous class installed is still there. That is `V2-QA-004`, and this check
 * is its reproduction rather than its fix.
 *
 * A reproduction that simply fails is useless — it blocks every pull request and reports the same thing
 * every time — and a reproduction that is allowed to fail is not a gate. So the currently non-idempotent
 * tests are recorded in `docs/quality/idempotency-baseline.json` with an owner, an expiry and what removing
 * each one takes, and this tool fails on anything outside that record: a test that starts failing, an entry
 * whose test now passes, or an entry that has outlived its expiry. The list only ever shrinks. It is the
 * same shape the dependency baseline uses, for the same reason.
 *
 * Both passes always run. Aborting at the first failure would hide whatever the second pass has to say, and
 * the second pass — reverse class order — is the one nothing has ever executed.
 *
 * Usage:
 *   php tools/verify-suite-idempotency.php --engine=mariadb|mysql|pgsql|postgresql
 *   php tools/verify-suite-idempotency.php --engine=ID --junit=repeat:PATH --junit=reverse:PATH
 *
 * The second form judges results that were collected elsewhere, which is how the architecture suite proves
 * this check fails in the right direction without needing a database.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$baselinePath = $root . '/docs/quality/idempotency-baseline.json';
$engine = null;
$today = date('Y-m-d');
$supplied = [];
$startup = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--engine=')) {
        $engine = substr($argument, strlen('--engine='));
        continue;
    }
    if (str_starts_with($argument, '--baseline=')) {
        $baselinePath = substr($argument, strlen('--baseline='));
        continue;
    }
    if (str_starts_with($argument, '--today=')) {
        $today = substr($argument, strlen('--today='));
        continue;
    }
    if (str_starts_with($argument, '--junit=')) {
        $value = substr($argument, strlen('--junit='));
        $separator = strpos($value, ':');
        if ($separator === false) {
            $startup[] = sprintf('--junit expects PASS:PATH, and %s has no pass name.', $value);
            continue;
        }
        $supplied[substr($value, 0, $separator)] = substr($value, $separator + 1);
        continue;
    }

    $startup[] = sprintf('Unknown argument %s.', $argument);
}

if ($engine === null || $engine === '') {
    $startup[] = 'This check needs --engine=ID naming the engine the suite just ran against.';
}
if ($startup !== []) {
    reportIdempotencyFailure($startup);
}

/** @var string $engine */
$engine = normalizeEngine($engine);
$baseline = readIdempotencyBaseline($baselinePath);
$entries = readIdempotencyEntries($baseline, $engine, $today);
$passes = declaredPasses($baseline);

$observed = $supplied === []
    ? executePasses($root, $passes)
    : readSuppliedResults($supplied, $passes);

exit(judge($entries, $observed, $engine, $passes));

/**
 * Reduce a driver name to the engine identifier the baseline uses.
 *
 * @param   string  $engine  Driver or engine name as the workflow spells it.
 *
 * @return  string  The engine identifier.
 *
 * @since   2.0.0
 */
function normalizeEngine(string $engine): string
{
    return match ($engine) {
        'pgsql', 'postgres' => 'postgresql',
        default => $engine,
    };
}

/**
 * Read the baseline document, refusing anything that is not a decodable object.
 *
 * @param   string  $path  Absolute path to the baseline.
 *
 * @return  array<string, mixed>  The decoded baseline.
 *
 * @since   2.0.0
 */
function readIdempotencyBaseline(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    /** @var mixed $decoded */
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        reportIdempotencyFailure([sprintf('%s is missing or is not well-formed JSON.', $path)]);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Read the passes the baseline declares.
 *
 * @param   array<string, mixed>  $baseline  Decoded baseline.
 *
 * @return  list<string>  Pass names, in execution order.
 *
 * @since   2.0.0
 */
function declaredPasses(array $baseline): array
{
    /** @var mixed $scope */
    $scope = $baseline['scope'] ?? null;
    /** @var mixed $passes */
    $passes = is_array($scope) ? ($scope['passes'] ?? null) : null;
    if (!is_array($passes) || !array_is_list($passes) || $passes === []) {
        reportIdempotencyFailure(['The baseline must declare scope.passes.']);
    }

    $names = [];
    foreach ($passes as $pass) {
        if (!is_string($pass) || $pass === '') {
            reportIdempotencyFailure(['Every declared pass must be a non-empty string.']);
        }
        $names[] = $pass;
    }

    return $names;
}

/**
 * Read and validate the baseline entries, refusing an exemption nobody owns or one that has expired.
 *
 * @param   array<string, mixed>  $baseline  Decoded baseline.
 * @param   string                $engine    Engine the suite just ran against.
 * @param   string                $today     Date the expiries are judged against, as `Y-m-d`.
 *
 * @return  array<string, array{observed: bool, applies: bool}>  Entries by test identifier.
 *
 * @since   2.0.0
 */
function readIdempotencyEntries(array $baseline, string $engine, string $today): array
{
    /** @var mixed $declared */
    $declared = $baseline['entries'] ?? null;
    if (!is_array($declared) || !array_is_list($declared)) {
        reportIdempotencyFailure(['The baseline must declare "entries" as an array.']);
    }

    $errors = [];
    $entries = [];
    foreach ($declared as $index => $entry) {
        if (!is_array($entry)) {
            $errors[] = sprintf('Baseline entry at position %d is not an object.', $index);
            continue;
        }
        $test = $entry['test'] ?? null;
        if (!is_string($test) || $test === '') {
            $errors[] = sprintf('Baseline entry at position %d names no test.', $index);
            continue;
        }
        foreach (['owner', 'finding', 'removal'] as $field) {
            $value = $entry[$field] ?? null;
            if (is_string($value) && trim($value) !== '' && $value !== 'UNASSIGNED') {
                continue;
            }
            $errors[] = sprintf(
                'Baseline entry %s needs a non-empty "%s". An exemption nobody owns, that names no finding, '
                . 'or that does not say what removing it takes, is a permission.',
                $test,
                $field,
            );
        }
        $expires = $entry['expires'] ?? null;
        if (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
            $errors[] = sprintf('Baseline entry %s needs an expiry date as YYYY-MM-DD.', $test);
        } elseif ($expires < $today) {
            $errors[] = sprintf(
                'Baseline entry %s expired on %s. Make the test idempotent or record a new decision; an '
                . 'exemption does not outlive the work that justified it.',
                $test,
                $expires,
            );
        }

        $entries[$test] = [
            'observed' => in_array($engine, engineList($entry, 'observed_on'), true),
            'applies' => in_array($engine, engineList($entry, 'applies_to'), true),
        ];
    }

    if ($errors !== []) {
        reportIdempotencyFailure($errors);
    }

    return $entries;
}

/**
 * Read one of an entry's engine lists.
 *
 * @param   array<string, mixed>  $entry  A baseline entry.
 * @param   string                $field  Either `observed_on` or `applies_to`.
 *
 * @return  list<string>  Engine identifiers.
 *
 * @since   2.0.0
 */
function engineList(array $entry, string $field): array
{
    /** @var mixed $value */
    $value = $entry[$field] ?? null;
    if (!is_array($value) || !array_is_list($value)) {
        return [];
    }

    $engines = [];
    foreach ($value as $engine) {
        if (is_string($engine) && $engine !== '') {
            $engines[] = normalizeEngine($engine);
        }
    }

    return $engines;
}

/**
 * Run every declared pass and collect the tests that failed in each.
 *
 * Both passes always run. Stopping at the first failure would leave the reverse-order pass unexecuted, and
 * that pass is the one nothing has ever run, so a report that omitted it would be the same omission this
 * whole check exists to remove.
 *
 * @param   string        $root    Repository root.
 * @param   list<string>  $passes  Declared pass names.
 *
 * @return  array<string, list<string>>  Failing test identifiers by pass.
 *
 * @since   2.0.0
 */
function executePasses(string $root, array $passes): array
{
    $observed = [];
    foreach ($passes as $pass) {
        $log = sprintf('%s/build/idempotency/%s.junit.xml', $root, $pass);
        @mkdir(dirname($log), 0o755, true);
        $command = sprintf(
            'cd %s && vendor/bin/phpunit --testsuite integration --colors=never --log-junit %s%s',
            escapeshellarg($root),
            escapeshellarg($log),
            $pass === 'reverse' ? ' --order-by=reverse' : '',
        );

        fwrite(STDOUT, sprintf("== idempotency pass \"%s\"\n", $pass));
        $status = 0;
        passthru($command, $status);

        if (!is_file($log)) {
            reportIdempotencyFailure([sprintf(
                'The "%s" pass produced no JUnit report (exit %d), so nothing can be judged. That is a broken '
                . 'run rather than a known non-idempotent test, and it fails here on purpose.',
                $pass,
                $status,
            )]);
        }
        $observed[$pass] = failingTests($log);
    }

    return $observed;
}

/**
 * Read results collected elsewhere.
 *
 * @param   array<string, string>  $supplied  Report path by pass name.
 * @param   list<string>           $passes    Declared pass names.
 *
 * @return  array<string, list<string>>  Failing test identifiers by pass.
 *
 * @since   2.0.0
 */
function readSuppliedResults(array $supplied, array $passes): array
{
    $observed = [];
    foreach ($passes as $pass) {
        $path = $supplied[$pass] ?? null;
        if ($path === null) {
            continue;
        }
        if (!is_file($path)) {
            reportIdempotencyFailure([sprintf('The "%s" pass report %s does not exist.', $pass, $path)]);
        }
        $observed[$pass] = failingTests($path);
    }

    return $observed;
}

/**
 * Read the tests that errored or failed out of one JUnit report.
 *
 * @param   string  $path  Absolute path to a JUnit report.
 *
 * @return  list<string>  Failing test identifiers as `Class::method`, in document order.
 *
 * @since   2.0.0
 */
function failingTests(string $path): array
{
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_file($path);
    libxml_use_internal_errors($previous);
    if ($document === false) {
        reportIdempotencyFailure([sprintf('%s is not readable XML.', $path)]);
    }

    $failing = [];
    foreach ($document->xpath('//testcase') ?: [] as $case) {
        // SimpleXML hands back an empty element rather than null for a child that is not there, so a
        // self-closing <testcase/> would read as failing if this asked for null.
        if (!isset($case->failure) && !isset($case->error)) {
            continue;
        }
        $class = (string) ($case['class'] ?? '');
        if ($class === '') {
            $class = str_replace('.', '\\', (string) ($case['classname'] ?? ''));
        }
        $name = (string) ($case['name'] ?? '');
        if ($class === '' || $name === '') {
            continue;
        }
        $identifier = $class . '::' . $name;
        if (!in_array($identifier, $failing, true)) {
            $failing[] = $identifier;
        }
    }

    return $failing;
}

/**
 * Compare what the passes observed with what the baseline records.
 *
 * @param   array<string, array{observed: bool, applies: bool}>  $entries   Baseline entries by test.
 * @param   array<string, list<string>>                          $observed  Failing tests by pass.
 * @param   string                                               $engine    Engine under test.
 * @param   list<string>                                         $passes    Declared pass names.
 *
 * @return  int  Process exit status: zero when the run matched the record exactly.
 *
 * @since   2.0.0
 */
function judge(array $entries, array $observed, string $engine, array $passes): int
{
    $errors = [];
    $failedSomewhere = [];
    foreach ($observed as $pass => $tests) {
        foreach ($tests as $test) {
            $failedSomewhere[$test][] = $pass;
        }
    }

    $unrecorded = [];
    foreach ($failedSomewhere as $test => $inPasses) {
        $entry = $entries[$test] ?? null;
        if ($entry !== null && $entry['applies']) {
            continue;
        }
        $unrecorded[] = sprintf(
            '%s (failed in the %s pass on %s)',
            $test,
            implode(' and ', $inPasses),
            $engine,
        );
    }

    if ($unrecorded !== []) {
        sort($unrecorded, SORT_STRING);
        $errors[] = sprintf(
            "%d test(s) failed against a reused database and are not in the baseline:\n     - %s\n"
            . "     Either the change made a test non-idempotent — fix it — or the run found a case the "
            . "record does not carry, in which case add it to docs/quality/idempotency-baseline.json with an "
            . "owner, an expiry and what removing it takes.",
            count($unrecorded),
            implode("\n     - ", $unrecorded),
        );
    }

    $stale = [];
    foreach ($entries as $test => $entry) {
        if (!$entry['observed'] || isset($failedSomewhere[$test])) {
            continue;
        }
        $stale[] = $test;
    }

    if ($stale !== []) {
        sort($stale, SORT_STRING);
        $errors[] = sprintf(
            "These baseline entries record a failure on %s that no longer happens, so they must be deleted — "
            . "the record only ever shrinks:\n     - %s",
            $engine,
            implode("\n     - ", $stale),
        );
    }

    if ($errors !== []) {
        reportIdempotencyFailure($errors);
    }

    fwrite(STDOUT, sprintf(
        "Kumwe suite idempotency verified on %s: %d pass(es) executed, %d recorded non-idempotent test(s), "
        . "nothing new.\n",
        $engine,
        count($observed),
        count($failedSomewhere),
    ));
    foreach ($passes as $pass) {
        if (!array_key_exists($pass, $observed)) {
            continue;
        }
        fwrite(STDOUT, sprintf("  %-8s %d recorded failure(s)\n", $pass, count($observed[$pass])));
    }

    return 0;
}

/**
 * Print every failure and terminate with a non-zero status.
 *
 * @param   list<string>  $errors  Validation failures.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function reportIdempotencyFailure(array $errors): never
{
    $errors = array_values(array_unique($errors));
    fwrite(STDERR, "Kumwe suite idempotency check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
