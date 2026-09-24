<?php

/**
 * Verify the one Version 2 acceptance record and generate its summary from it.
 *
 * `docs/roadmap/acceptance-record.json` links every requirement this programme accepts by merge — a finding,
 * a work package or a Gate B criterion — to the runtime that owns it, the tests that prove it, the workflow
 * artifacts that carry the evidence and the decision that governs it. Under ADR 0021 the maintainer's merge
 * is the sole human acceptance, so the record must already be truthful inside the pull request: a delivered
 * entry has left the findings ledger and reached the changelog, an open entry is still in the ledger and
 * says what is outstanding, and every referenced path exists. The summary page is derived from the record,
 * never hand-written, so a claim cannot drift from the evidence that supports it.
 *
 * Usage:
 *   php tools/verify-acceptance-record.php --check     # verify the record and the committed summary
 *   php tools/verify-acceptance-record.php --write     # verify the record and regenerate the summary
 *
 * Git is not needed; the check reads only committed files, so it runs before `composer install`.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$recordPath = $root . '/docs/roadmap/acceptance-record.json';
$summaryPath = $root . '/docs/roadmap/acceptance-summary.md';
$ledgerPath = $root . '/docs/roadmap/findings.json';
$changelogPath = $root . '/CHANGELOG.md';
$arguments = array_slice($argv, 1);
$write = $arguments === ['--write'];
if ($arguments !== [] && !$write && $arguments !== ['--check']) {
    fwrite(STDERR, "Usage: php tools/verify-acceptance-record.php [--check|--write]\n");
    exit(64);
}

$errors = [];
$record = readJson($recordPath, $errors);
$ledger = readJson($ledgerPath, $errors);
if ($errors !== []) {
    reportAcceptanceFailure($errors);
}

$states = ['delivered', 'open', 'separate-track'];
$openFindings = [];
foreach (is_array($ledger['findings'] ?? null) ? $ledger['findings'] : [] as $finding) {
    if (is_array($finding) && is_string($finding['id'] ?? null)) {
        $openFindings[$finding['id']] = $finding;
    }
}
$changelog = file_get_contents($changelogPath);
if (!is_string($changelog)) {
    reportAcceptanceFailure(['CHANGELOG.md could not be read.']);
}

foreach (['record', 'version', 'authority', 'pull_request', 'entries'] as $key) {
    if (!array_key_exists($key, $record)) {
        $errors[] = sprintf('The acceptance record lacks the required key %s.', $key);
    }
}
$entries = is_array($record['entries'] ?? null) ? $record['entries'] : [];
if ($entries === []) {
    $errors[] = 'The acceptance record carries no entries.';
}
$seen = [];
$criteria = [];
$rows = [];
foreach ($entries as $index => $entry) {
    if (!is_array($entry)) {
        $errors[] = sprintf('Entry %d is not an object.', $index);
        continue;
    }
    $id = $entry['id'] ?? null;
    if (!is_string($id) || $id === '') {
        $errors[] = sprintf('Entry %d has no identifier.', $index);
        continue;
    }
    if (isset($seen[$id])) {
        $errors[] = sprintf('Entry %s is declared twice.', $id);
        continue;
    }
    $seen[$id] = true;
    foreach (['requirement', 'state', 'decision'] as $key) {
        if (!is_string($entry[$key] ?? null) || trim((string) $entry[$key]) === '') {
            $errors[] = sprintf('Entry %s must carry a non-empty %s.', $id, $key);
        }
    }
    $state = is_string($entry['state'] ?? null) ? $entry['state'] : '';
    if (!in_array($state, $states, true)) {
        $errors[] = sprintf('Entry %s carries the unknown state %s.', $id, $state);
    }
    foreach (['runtime_owner', 'tests', 'artifacts', 'gate_b_criteria'] as $key) {
        if (!is_array($entry[$key] ?? null)) {
            $errors[] = sprintf('Entry %s must carry the list %s.', $id, $key);
        }
    }
    foreach (['runtime_owner', 'tests'] as $key) {
        foreach (is_array($entry[$key] ?? null) ? $entry[$key] : [] as $path) {
            if (!is_string($path) || !file_exists($root . '/' . $path)) {
                $errors[] = sprintf(
                    'Entry %s names the missing %s path %s.',
                    $id,
                    $key,
                    is_string($path) ? $path : '?',
                );
            }
        }
    }
    foreach (is_array($entry['artifacts'] ?? null) ? $entry['artifacts'] : [] as $artifact) {
        if (!is_string($artifact) || $artifact === '') {
            $errors[] = sprintf('Entry %s carries a malformed artifact reference.', $id);
            continue;
        }
        $file = explode('#', $artifact, 2)[0];
        if (str_starts_with($file, '.github/') || str_starts_with($file, 'tools/') || str_starts_with($file, 'docs/')) {
            if (!file_exists($root . '/' . $file)) {
                $errors[] = sprintf('Entry %s names the missing artifact file %s.', $id, $file);
            }
        }
    }
    foreach (is_array($entry['gate_b_criteria'] ?? null) ? $entry['gate_b_criteria'] : [] as $criterion) {
        if (!is_int($criterion) || $criterion < 1 || $criterion > 12) {
            $errors[] = sprintf('Entry %s cites a Gate B criterion outside 1 through 12.', $id);
            continue;
        }
        $criteria[$criterion][] = $id;
    }
    $isFinding = preg_match('/^(V2|V3|GM)-[A-Z]+-\d{2,3}$/', $id) === 1;
    $outstanding = $entry['outstanding'] ?? null;
    if ($state === 'delivered') {
        if ($isFinding && isset($openFindings[$id])) {
            $errors[] = sprintf('Entry %s is delivered but still sits in docs/roadmap/findings.json.', $id);
        }
        if (!str_contains($changelog, $id)) {
            $errors[] = sprintf('Entry %s is delivered but CHANGELOG.md never cites it.', $id);
        }
        if ($outstanding !== null) {
            $errors[] = sprintf('Entry %s is delivered and may not carry an outstanding note.', $id);
        }
        if (($entry['tests'] ?? []) === []) {
            $errors[] = sprintf('Entry %s is delivered without naming a test.', $id);
        }
    } else {
        if (!is_string($outstanding) || trim($outstanding) === '') {
            $errors[] = sprintf('Entry %s is %s and must say what is outstanding.', $id, $state);
        }
        if ($isFinding && !isset($openFindings[$id])) {
            $errors[] = sprintf('Entry %s is %s but docs/roadmap/findings.json no longer carries it.', $id, $state);
        }
    }
    $rows[] = [
        'id' => $id,
        'state' => $state,
        'requirement' => is_string($entry['requirement'] ?? null) ? $entry['requirement'] : '',
        'criteria' => is_array($entry['gate_b_criteria'] ?? null) ? $entry['gate_b_criteria'] : [],
        'owner' => is_array($entry['runtime_owner'] ?? null) ? $entry['runtime_owner'] : [],
        'tests' => is_array($entry['tests'] ?? null) ? $entry['tests'] : [],
        'artifacts' => is_array($entry['artifacts'] ?? null) ? $entry['artifacts'] : [],
        'decision' => is_string($entry['decision'] ?? null) ? $entry['decision'] : '',
        'outstanding' => is_string($outstanding) ? $outstanding : '',
    ];
}
for ($criterion = 1; $criterion <= 12; $criterion++) {
    if (!isset($criteria[$criterion])) {
        $errors[] = sprintf('No acceptance entry cites Gate B criterion %d.', $criterion);
    }
}
if ($errors !== []) {
    reportAcceptanceFailure($errors);
}

$summary = renderSummary($record, $rows, $criteria);
$current = is_file($summaryPath) ? file_get_contents($summaryPath) : null;
if ($write) {
    if (file_put_contents($summaryPath, $summary) === false) {
        reportAcceptanceFailure(['docs/roadmap/acceptance-summary.md could not be written.']);
    }
    fwrite(STDOUT, sprintf("Acceptance record verified and summary written: %d entries.\n", count($rows)));
    exit(0);
}
if (!is_string($current) || !hash_equals($summary, $current)) {
    reportAcceptanceFailure([
        'docs/roadmap/acceptance-summary.md is stale; run composer acceptance:summary and commit the result.',
    ]);
}
$delivered = count(array_filter($rows, static fn(array $row): bool => $row['state'] === 'delivered'));
fwrite(STDOUT, sprintf(
    "Acceptance record verified: %d entries, %d delivered, %d open, %d on a separate track.\n",
    count($rows),
    $delivered,
    count(array_filter($rows, static fn(array $row): bool => $row['state'] === 'open')),
    count($rows) - $delivered - count(array_filter($rows, static fn(array $row): bool => $row['state'] === 'open')),
));
exit(0);

/**
 * Decode a JSON document into an array, recording a failure instead of throwing.
 *
 * @param   string         $path    Absolute path of the document.
 * @param   list<string>   $errors  Accumulated failures.
 *
 * @return  array<string, mixed>  Decoded document, empty on failure.
 *
 * @since   2.0.0
 */
function readJson(string $path, array &$errors): array
{
    $contents = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($contents)) {
        $errors[] = sprintf('%s is missing or unreadable.', $path);
        return [];
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        $errors[] = sprintf('%s is not a JSON object.', $path);
        return [];
    }
    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Render the derived summary page.
 *
 * @param   array<string, mixed>                $record    Decoded record.
 * @param   list<array<string, mixed>>          $rows      Normalized entries.
 * @param   array<int, list<string>>            $criteria  Gate B criterion to entry identifiers.
 *
 * @return  string  Markdown document.
 *
 * @since   2.0.0
 */
function renderSummary(array $record, array $rows, array $criteria): string
{
    $lines = [
        '# Version 2 acceptance summary',
        '',
        'Generated by `composer acceptance:summary` from [`acceptance-record.json`](acceptance-record.json).',
        'Do not edit: `composer acceptance:check` fails when this page differs from the record.',
        '',
        sprintf(
            'Authority: %s. Pull request %s. Maintainer merge is the sole human acceptance under ADR 0021;',
            is_string($record['authority'] ?? null) ? $record['authority'] : '',
            is_int($record['pull_request'] ?? null) ? (string) $record['pull_request'] : '',
        ),
        'a delivered row is accepted when this pull request merges, an open row remains in the findings ledger,',
        'and a separate-track row is owned by a later increment.',
        '',
        '## Gate B criteria',
        '',
        '| Criterion | Entries | Delivered | Open or separate track |',
        '|---|---|---|---|',
    ];
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }
    for ($criterion = 1; $criterion <= 12; $criterion++) {
        $ids = $criteria[$criterion] ?? [];
        $delivered = array_values(array_filter(
            $ids,
            static fn(string $id): bool => $byId[$id]['state'] === 'delivered',
        ));
        $other = array_values(array_diff($ids, $delivered));
        $lines[] = sprintf(
            '| %d | %d | %s | %s |',
            $criterion,
            count($ids),
            $delivered === [] ? '—' : '`' . implode('`, `', $delivered) . '`',
            $other === [] ? '—' : '`' . implode('`, `', $other) . '`',
        );
    }
    $lines[] = '';
    $lines[] = '## Requirements';
    $lines[] = '';
    $lines[] = '| Requirement | State | Runtime owner | Tests | Artifacts | Decision | Outstanding |';
    $lines[] = '|---|---|---|---|---|---|---|';
    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| `%s` — %s | %s | %s | %s | %s | %s | %s |',
            $row['id'],
            str_replace('|', '\\|', $row['requirement']),
            $row['state'],
            codeList($row['owner']),
            codeList($row['tests']),
            codeList($row['artifacts']),
            str_replace('|', '\\|', $row['decision']),
            $row['outstanding'] === '' ? '—' : str_replace('|', '\\|', $row['outstanding']),
        );
    }
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * Render a list of paths as inline code, or a dash when empty.
 *
 * @param   list<mixed>  $items  Paths or references.
 *
 * @return  string  Markdown fragment.
 *
 * @since   2.0.0
 */
function codeList(array $items): string
{
    $strings = array_values(array_filter($items, 'is_string'));
    return $strings === [] ? '—' : '`' . implode('`<br>`', $strings) . '`';
}

/**
 * Print every failure and exit non-zero.
 *
 * @param   list<string>  $errors  Failures to report.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function reportAcceptanceFailure(array $errors): never
{
    fwrite(STDERR, "The acceptance record is not truthful:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '  - ' . $error . "\n");
    }
    exit(1);
}
