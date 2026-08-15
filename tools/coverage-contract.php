<?php

/**
 * Enforce the coverage contract: truthful attribution, and a ratchet that stops the number falling.
 *
 * Two problems shipped together. Attribution: 148 `#[CoversNothing]` attributes across 74 files, 36 of them
 * on integration tests that exercise real behaviour against real engines, so the coverage report described a
 * smaller product than the one being tested. And enforcement: the number was collected on one leg and gated
 * by nothing, which the workflow said out loud.
 *
 * `--attribution` holds `#[CoversNothing]` to the two lists in `docs/quality/coverage-contract.json`: the
 * reasoned one, for tests whose subject is not a class under `src/`, and the pending one, for the
 * behavioural tests that carried it when this gate was switched on. The pending list only ever shrinks — an
 * entry that no longer carries the attribute fails as stale, and one past its expiry fails outright — and a
 * new behavioural test cannot join it.
 *
 * `--ratchet` reads the clover report from the canonical engine and refuses a change that leaves its own new
 * lines uncovered, or that lowers the global figure past the declared tolerance. A ratchet the instrumented
 * driver cannot measure is reported as unenforced rather than quietly passed.
 *
 * Usage:
 *   php tools/coverage-contract.php --attribution [--contract=PATH]
 *   php tools/coverage-contract.php --ratchet [--clover=PATH] [--base=REF] [--record]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$contractPath = $root . '/docs/quality/coverage-contract.json';
$cloverPath = $root . '/build/coverage/clover.xml';
$mode = null;
$base = null;
$record = false;
$startup = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--attribution' || $argument === '--ratchet') {
        $mode = substr($argument, 2);
        continue;
    }
    if ($argument === '--record') {
        $record = true;
        continue;
    }
    if (str_starts_with($argument, '--contract=')) {
        $contractPath = substr($argument, strlen('--contract='));
        continue;
    }
    if (str_starts_with($argument, '--clover=')) {
        $cloverPath = substr($argument, strlen('--clover='));
        continue;
    }
    if (str_starts_with($argument, '--base=')) {
        $base = substr($argument, strlen('--base='));
        continue;
    }

    $startup[] = sprintf('Unknown argument %s.', $argument);
}

if ($mode === null) {
    $startup[] = 'Choose --attribution or --ratchet.';
}
if ($startup !== []) {
    reportCoverageFailure($startup);
}

$contract = readCoverageContract($contractPath);

exit($mode === 'attribution'
    ? checkAttribution($contract, $root)
    : checkRatchet($contract, $contractPath, $root, $cloverPath, $base, $record));

/**
 * Read the coverage contract, refusing anything that is not a decodable object.
 *
 * @param   string  $path  Absolute path to the contract.
 *
 * @return  array<string, mixed>  The decoded contract.
 *
 * @since   2.0.0
 */
function readCoverageContract(string $path): array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    /** @var mixed $decoded */
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        reportCoverageFailure([sprintf('%s is missing or is not well-formed JSON.', $path)]);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Hold `#[CoversNothing]` to the reasoned and pending lists, and require the pending list to shrink.
 *
 * @param   array<string, mixed>  $contract  Decoded coverage contract.
 * @param   string                $root      Repository root.
 *
 * @return  int  Process exit status: zero when every attribute is accounted for.
 *
 * @since   2.0.0
 */
function checkAttribution(array $contract, string $root): int
{
    $errors = [];
    /** @var mixed $attribution */
    $attribution = $contract['attribution'] ?? null;
    if (!is_array($attribution)) {
        reportCoverageFailure(['The contract declares no "attribution" rules.']);
    }

    $today = date('Y-m-d');
    $reasoned = [];
    foreach (listOf($attribution['reasoned'] ?? null, 'attribution.reasoned', $errors) as $entry) {
        $path = $entry['path'] ?? null;
        $reason = $entry['reason'] ?? null;
        if (!is_string($path) || $path === '' || !is_string($reason) || trim($reason) === '') {
            $errors[] = 'Every reasoned allowlist entry needs a path and a reason.';
            continue;
        }
        $reasoned[] = $path;
    }

    $pending = [];
    foreach (listOf($attribution['pending'] ?? null, 'attribution.pending', $errors) as $entry) {
        $path = $entry['path'] ?? null;
        if (!is_string($path) || $path === '') {
            $errors[] = 'Every pending entry needs a path.';
            continue;
        }
        checkExemptionMetadata($entry, $path, $today, $errors);
        $pending[$path] = false;
    }

    $pendingDirectories = [];
    $declared = $attribution['pending_directories'] ?? null;
    foreach (listOf($declared, 'attribution.pending_directories', $errors) as $entry) {
        $path = $entry['path'] ?? null;
        if (!is_string($path) || $path === '') {
            $errors[] = 'Every pending directory needs a path.';
            continue;
        }
        checkExemptionMetadata($entry, $path, $today, $errors);
        $pendingDirectories[$path] = 0;
    }

    $unaccounted = [];
    $withoutMetadata = [];
    foreach (testFiles($root) as $relative) {
        $contents = file_get_contents($root . '/' . $relative);
        if ($contents === false) {
            $errors[] = sprintf('%s could not be read.', $relative);
            continue;
        }
        $coversNothing = str_contains($contents, '#[CoversNothing]');
        $attributes = $coversNothing
            || str_contains($contents, '#[CoversClass(')
            || str_contains($contents, '#[CoversTrait(')
            || str_contains($contents, '#[CoversFunction(')
            || str_contains($contents, '#[CoversMethod(');
        if (!$attributes) {
            $withoutMetadata[] = $relative;
            continue;
        }
        if (!$coversNothing) {
            continue;
        }

        if (matchesPrefix($relative, $reasoned)) {
            continue;
        }
        if (array_key_exists($relative, $pending)) {
            $pending[$relative] = true;
            continue;
        }
        $directory = matchingDirectory($relative, array_keys($pendingDirectories));
        if ($directory !== null) {
            $pendingDirectories[$directory]++;
            continue;
        }
        $unaccounted[] = $relative;
    }

    if ($unaccounted !== []) {
        sort($unaccounted, SORT_STRING);
        $errors[] = sprintf(
            "These tests attribute nothing and are on neither list:\n     - %s\n     Name the classes the "
            . 'test exercises with #[CoversClass], or add the file to the reasoned list with the reason its '
            . 'subject is not a class under src/.',
            implode("\n     - ", $unaccounted),
        );
    }

    $stale = [];
    foreach ($pending as $path => $found) {
        if (!$found) {
            $stale[] = $path;
        }
    }
    foreach ($pendingDirectories as $path => $count) {
        if ($count === 0) {
            $stale[] = $path;
        }
    }
    if ($stale !== []) {
        sort($stale, SORT_STRING);
        $errors[] = sprintf(
            "These pending entries no longer hold anything that attributes nothing, so they must be deleted "
            . "— the list only ever shrinks:\n     - %s",
            implode("\n     - ", $stale),
        );
    }

    if ($withoutMetadata !== []) {
        sort($withoutMetadata, SORT_STRING);
        $errors[] = sprintf(
            "These tests carry no coverage metadata at all:\n     - %s",
            implode("\n     - ", $withoutMetadata),
        );
    }

    if ($errors !== []) {
        reportCoverageFailure($errors);
    }

    $outstanding = count($pending) + array_sum($pendingDirectories);
    fwrite(
        STDOUT,
        sprintf(
            "Kumwe coverage attribution verified: %d reasoned rule(s), %d test(s) still owing attribution.\n",
            count($reasoned),
            $outstanding,
        ),
    );

    return 0;
}

/**
 * Require an exemption to name an owner, the finding that removes it, and a live expiry.
 *
 * @param   array<string, mixed>  $entry   The exemption entry.
 * @param   string                $path    Path the entry exempts, for diagnostics.
 * @param   string                $today   Date the expiry is judged against, as `Y-m-d`.
 * @param   list<string>          $errors  Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function checkExemptionMetadata(array $entry, string $path, string $today, array &$errors): void
{
    $owner = $entry['owner'] ?? null;
    $finding = $entry['finding'] ?? null;
    $expires = $entry['expires'] ?? null;
    if (!is_string($owner) || trim($owner) === '' || !is_string($finding) || trim($finding) === '') {
        $errors[] = sprintf('Pending entry %s needs an owner and the finding that removes it.', $path);
    }
    if (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
        $errors[] = sprintf('Pending entry %s needs an expiry date as YYYY-MM-DD.', $path);

        return;
    }
    if ($expires < $today) {
        $errors[] = sprintf(
            'Pending entry %s expired on %s. Attribute the tests or record a new decision; an exemption does '
            . 'not outlive the work that justified it.',
            $path,
            $expires,
        );
    }
}

/**
 * Compare the canonical clover report against the declared ratchets.
 *
 * @param   array<string, mixed>  $contract      Decoded coverage contract.
 * @param   string                $contractPath  Path the contract was read from.
 * @param   string                $root          Repository root.
 * @param   string                $cloverPath    Clover report from the canonical engine.
 * @param   string|null           $base          Git reference the change is measured against.
 * @param   bool                  $record        Whether to print the baseline the run measured.
 *
 * @return  int  Process exit status: zero when every armed ratchet holds.
 *
 * @since   2.0.0
 */
function checkRatchet(
    array $contract,
    string $contractPath,
    string $root,
    string $cloverPath,
    ?string $base,
    bool $record,
): int {
    if (!is_file($cloverPath)) {
        reportCoverageFailure([sprintf(
            '%s is missing. The ratchet reads the clover report the canonical %s leg produces; without it '
            . 'there is nothing to judge and the step must fail rather than pass silently.',
            $cloverPath,
            is_string($contract['canonical_engine'] ?? null) ? $contract['canonical_engine'] : 'coverage',
        )]);
    }

    $coverage = readClover($cloverPath);
    $errors = [];
    $global = $coverage['statements'] === 0
        ? 0.0
        : round($coverage['covered'] / $coverage['statements'] * 100, 4);

    fwrite(STDOUT, sprintf(
        "Global line coverage on the canonical engine: %.2f%% (%d of %d statements).\n",
        $global,
        $coverage['covered'],
        $coverage['statements'],
    ));

    foreach (listOf($contract['ratchets'] ?? null, 'ratchets', $errors) as $ratchet) {
        $id = is_string($ratchet['id'] ?? null) ? $ratchet['id'] : '(unnamed)';
        if (($ratchet['enforced'] ?? false) !== true) {
            $reason = is_string($ratchet['unenforceable_reason'] ?? null) ? $ratchet['unenforceable_reason'] : '';
            fwrite(STDOUT, sprintf("- %s is declared and not enforced: %s\n", $id, $reason));
            continue;
        }
        if ($id === 'changed-line-floor') {
            checkChangedLineFloor($ratchet, $root, $coverage['lines'], $base, $errors);
            continue;
        }
        if ($id === 'global-decrease') {
            checkGlobalDecrease($contract, $contractPath, $global, $errors);
        }
    }

    if ($record) {
        fwrite(STDOUT, sprintf(
            "\nTo arm the global ratchet, record this measurement in %s:\n  \"line_coverage_percent\": %.2f\n",
            basename($contractPath),
            $global,
        ));
    }

    if ($errors !== []) {
        reportCoverageFailure($errors);
    }

    fwrite(STDOUT, "Kumwe coverage ratchets hold.\n");

    return 0;
}

/**
 * Require the executable lines a change adds or edits to be covered.
 *
 * @param   array<string, mixed>              $ratchet  The ratchet's declaration.
 * @param   string                            $root     Repository root.
 * @param   array<string, array<int, int>>    $lines    Hit counts by repository-relative file and line.
 * @param   string|null                       $base     Git reference the change is measured against.
 * @param   list<string>                      $errors   Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function checkChangedLineFloor(array $ratchet, string $root, array $lines, ?string $base, array &$errors): void
{
    $floor = $ratchet['floor_percent'] ?? null;
    if (!is_int($floor) && !is_float($floor)) {
        $errors[] = 'The changed-line ratchet declares no floor.';

        return;
    }
    if ($base === null || $base === '') {
        $errors[] = 'The changed-line ratchet needs --base=REF naming what the change is measured against.';

        return;
    }

    $command = sprintf(
        'cd %s && git diff --unified=0 --no-color %s -- src 2>&1',
        escapeshellarg($root),
        escapeshellarg($base . '...HEAD'),
    );
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) {
        $errors[] = sprintf('The change could not be resolved against %s: %s', $base, implode(' ', $output));

        return;
    }

    $changed = [];
    $file = null;
    foreach ($output as $line) {
        if (str_starts_with($line, '+++ b/')) {
            $file = substr($line, 6);
            continue;
        }
        if ($file === null || !str_starts_with($line, '@@')) {
            continue;
        }
        if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matched) !== 1) {
            continue;
        }
        $start = (int) $matched[1];
        $count = isset($matched[2]) ? (int) $matched[2] : 1;
        for ($number = $start; $number < $start + $count; $number++) {
            $changed[$file][$number] = true;
        }
    }

    $executable = 0;
    $covered = 0;
    $uncovered = [];
    foreach ($changed as $path => $numbers) {
        foreach (array_keys($numbers) as $number) {
            if (!isset($lines[$path][$number])) {
                continue;
            }
            $executable++;
            if ($lines[$path][$number] > 0) {
                $covered++;
                continue;
            }
            $uncovered[] = sprintf('%s:%d', $path, $number);
        }
    }

    if ($executable === 0) {
        fwrite(STDOUT, "- changed-line-floor: the change adds no executable line under src/.\n");

        return;
    }

    $percent = round($covered / $executable * 100, 2);
    fwrite(STDOUT, sprintf(
        "- changed-line-floor: %.2f%% of %d changed executable line(s) covered, floor %s%%.\n",
        $percent,
        $executable,
        (string) $floor,
    ));

    if ($percent >= (float) $floor) {
        return;
    }

    sort($uncovered, SORT_STRING);
    $errors[] = sprintf(
        "Only %.2f%% of the executable lines this change adds or edits are covered; the floor is %s%%. "
        . "Uncovered:\n     - %s",
        $percent,
        (string) $floor,
        implode("\n     - ", array_slice($uncovered, 0, 40)),
    );
}

/**
 * Compare the measured figure with the recorded baseline, when one has been recorded.
 *
 * @param   array<string, mixed>  $contract      Decoded coverage contract.
 * @param   string                $contractPath  Path the contract was read from.
 * @param   float                 $global        Measured global line coverage.
 * @param   list<string>          $errors        Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function checkGlobalDecrease(array $contract, string $contractPath, float $global, array &$errors): void
{
    /** @var mixed $baseline */
    $baseline = $contract['baseline'] ?? null;
    $recorded = is_array($baseline) ? ($baseline['line_coverage_percent'] ?? null) : null;
    if ($recorded === null) {
        fwrite(STDOUT, sprintf(
            "- global-decrease is unarmed: %s records no baseline yet. This run measured %.2f%%; committing "
            . "that figure arms the ratchet.\n",
            basename($contractPath),
            $global,
        ));

        return;
    }
    if (!is_int($recorded) && !is_float($recorded)) {
        $errors[] = 'The recorded baseline is not a number.';

        return;
    }

    $tolerance = 0.25;
    foreach ($contract['ratchets'] ?? [] as $ratchet) {
        if (is_array($ratchet) && ($ratchet['id'] ?? null) === 'global-decrease') {
            $declared = $ratchet['tolerance_points'] ?? null;
            $tolerance = is_int($declared) || is_float($declared) ? (float) $declared : $tolerance;
        }
    }

    $drop = (float) $recorded - $global;
    fwrite(STDOUT, sprintf(
        "- global-decrease: baseline %.2f%%, measured %.2f%%, tolerance %.2f point(s).\n",
        (float) $recorded,
        $global,
        $tolerance,
    ));
    if ($drop <= $tolerance) {
        return;
    }

    $errors[] = sprintf(
        'Global line coverage fell %.2f points, from %.2f%% to %.2f%%, and the tolerance is %.2f.',
        $drop,
        (float) $recorded,
        $global,
        $tolerance,
    );
}

/**
 * Read a clover report into per-file line hit counts.
 *
 * @param   string  $path  Absolute path to the clover report.
 *
 * @return  array{statements: int, covered: int, lines: array<string, array<int, int>>}  The measurement.
 *
 * @since   2.0.0
 */
function readClover(string $path): array
{
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_file($path);
    libxml_use_internal_errors($previous);
    if ($document === false) {
        reportCoverageFailure([sprintf('%s is not readable XML.', $path)]);
    }

    $statements = 0;
    $covered = 0;
    $lines = [];
    foreach ($document->xpath('//file') ?: [] as $file) {
        $name = (string) ($file['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $relative = str_contains($name, '/src/')
            ? 'src/' . substr($name, strpos($name, '/src/') + 5)
            : $name;
        foreach ($file->line as $line) {
            if ((string) ($line['type'] ?? '') !== 'stmt') {
                continue;
            }
            $number = (int) ($line['num'] ?? 0);
            $count = (int) ($line['count'] ?? 0);
            $statements++;
            $covered += $count > 0 ? 1 : 0;
            $lines[$relative][$number] = $count;
        }
    }

    return ['statements' => $statements, 'covered' => $covered, 'lines' => $lines];
}

/**
 * List every test class file the attribution rules apply to.
 *
 * @param   string  $root  Repository root.
 *
 * @return  list<string>  Repository-relative paths, sorted.
 *
 * @since   2.0.0
 */
function testFiles(string $root): array
{
    $paths = [];
    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/tests', FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if (!str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (str_starts_with($relative, 'tests/Support/') || str_starts_with($relative, 'tests/Fixtures/')) {
            continue;
        }
        $paths[] = $relative;
    }
    sort($paths, SORT_STRING);

    return $paths;
}

/**
 * Decide whether a path is covered by one of a set of prefixes.
 *
 * @param   string        $path      Repository-relative path.
 * @param   list<string>  $prefixes  Declared prefixes.
 *
 * @return  bool  Whether any prefix matches.
 *
 * @since   2.0.0
 */
function matchesPrefix(string $path, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Return the declared directory a path falls under, when one does.
 *
 * @param   string        $path         Repository-relative path.
 * @param   list<string>  $directories  Declared directory prefixes.
 *
 * @return  string|null  The matching directory, or null.
 *
 * @since   2.0.0
 */
function matchingDirectory(string $path, array $directories): ?string
{
    foreach ($directories as $directory) {
        if (str_starts_with($path, $directory)) {
            return $directory;
        }
    }

    return null;
}

/**
 * Require a value to be a JSON array of objects.
 *
 * @param   mixed         $value   Candidate value.
 * @param   string        $label   Diagnostic label naming the member.
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  list<array<string, mixed>>  The entries, or an empty list.
 *
 * @since   2.0.0
 */
function listOf(mixed $value, string $label, array &$errors): array
{
    if ($value === null) {
        return [];
    }
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = sprintf('Contract member "%s" must be a JSON array.', $label);

        return [];
    }

    $entries = [];
    foreach ($value as $entry) {
        if (!is_array($entry)) {
            $errors[] = sprintf('Every entry in "%s" must be an object.', $label);
            continue;
        }
        /** @var array<string, mixed> $entry */
        $entries[] = $entry;
    }

    return $entries;
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
function reportCoverageFailure(array $errors): never
{
    $errors = array_values(array_unique($errors));
    fwrite(STDERR, "Kumwe coverage contract failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
