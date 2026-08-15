<?php

/**
 * Verify that the roadmap ledger holds forward work only.
 *
 * `docs/roadmap/` is the single place open objectives live, and `CHANGELOG.md` is the single place finished
 * work lives. The rule that keeps them apart is that a completed work package leaves the ledger in the pull
 * request that completes it. This check is what stops that rule rotting the way the qualification gap matrix
 * did: it fails when `docs/roadmap/findings.json` still carries an entry in state `closed`, and names the
 * entries so the author knows exactly what to move. The same check also resolves every abbreviated commit
 * cited by the changelog, so a rebase cannot leave the historical record pointing at vanished objects.
 *
 * It has no PHP dependencies, so it runs before `composer install`; Git is the only executable it needs,
 * because repository ancestry rather than the local object cache decides whether a citation survives.
 *
 * Usage:
 *   php tools/verify-roadmap.php [--findings=PATH] [--changelog=PATH] [--repository=PATH]
 *
 * `--findings` exists so the architecture suite can prove the check fails in the right direction against a
 * ledger with a closed entry reintroduced, without writing that entry into the committed one.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$ledgerPath = $root . '/docs/roadmap/findings.json';
$changelogPath = $root . '/CHANGELOG.md';
$repositoryRoot = $root;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--findings=')) {
        $ledgerPath = substr($argument, strlen('--findings='));
        continue;
    }
    if (str_starts_with($argument, '--changelog=')) {
        $changelogPath = substr($argument, strlen('--changelog='));
        continue;
    }
    if (str_starts_with($argument, '--repository=')) {
        $repositoryRoot = substr($argument, strlen('--repository='));
        continue;
    }

    $errors[] = sprintf(
        'Unknown argument %s. Usage: php tools/verify-roadmap.php [--findings=PATH] [--changelog=PATH] '
        . '[--repository=PATH]',
        $argument,
    );
}

$findings = readLedger($ledgerPath, $errors);
readLedger($root . '/docs/roadmap/capacity-contract.json', $errors);

if (!is_file($changelogPath)) {
    $errors[] = 'CHANGELOG.md is missing. It is where completed work goes when it leaves the roadmap.';
} else {
    verifyChangelogCitations($changelogPath, $repositoryRoot, $errors);
}

if ($errors !== []) {
    reportRoadmapFailure($errors);
}

/** @var list<string> $allowedStates */
$allowedStates = [];
foreach (expectRoadmapList($findings['states'] ?? null, 'states', $errors) as $state) {
    if (!is_string($state) || $state === '') {
        $errors[] = 'Every entry in "states" must be a non-empty string.';
        continue;
    }
    $allowedStates[] = $state;
}

if (in_array('closed', $allowedStates, true)) {
    $errors[] = 'findings.json lists "closed" in "states". The ledger holds forward work only: remove it, '
        . 'and record completed work in CHANGELOG.md instead.';
}

$closed = [];
$open = 0;
$identifiers = [];
foreach (expectRoadmapList($findings['findings'] ?? null, 'findings', $errors) as $index => $finding) {
    if (!is_array($finding)) {
        $errors[] = sprintf('Finding at position %d is not an object.', $index);
        continue;
    }
    $id = $finding['id'] ?? null;
    if (!is_string($id) || $id === '') {
        $errors[] = sprintf('Finding at position %d has no identifier.', $index);
        continue;
    }
    if (isset($identifiers[$id])) {
        $errors[] = sprintf('Finding %s is declared more than once.', $id);
    }
    $identifiers[$id] = true;

    $state = $finding['state'] ?? null;
    if (!is_string($state) || $state === '') {
        $errors[] = sprintf('Finding %s has no state.', $id);
        continue;
    }
    if ($state === 'closed') {
        $closed[] = $id;
        continue;
    }
    if ($allowedStates !== [] && !in_array($state, $allowedStates, true)) {
        $errors[] = sprintf(
            'Finding %s carries state "%s", which findings.json does not declare in "states".',
            $id,
            $state,
        );
        continue;
    }
    $open++;
}

if ($closed !== []) {
    sort($closed, SORT_STRING);
    $errors[] = sprintf(
        'These findings are in state "closed" and do not belong in the roadmap: %s. '
        . 'Completed work leaves docs/roadmap/ and lands in CHANGELOG.md in the same pull request that '
        . 'completes it: delete each entry from docs/roadmap/findings.json, write what changed and what it '
        . 'means into CHANGELOG.md under Added, Changed, Fixed, Security, Deprecated or Removed, cite the '
        . 'commits that closed it, and lower the counts in docs/roadmap/STATUS.md.',
        implode(', ', $closed),
    );
}

if ($errors !== []) {
    reportRoadmapFailure($errors);
}

fwrite(
    STDOUT,
    sprintf(
        "Kumwe roadmap verified: %d open findings, no completed work left behind.\n",
        $open,
    ),
);
exit(0);

/**
 * Require every abbreviated commit cited by the changelog to resolve in repository history.
 *
 * @param   string        $path    Changelog document whose backtick-delimited hashes are citations.
 * @param   string        $root    Repository root whose current history must contain those commits.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyChangelogCitations(string $path, string $root, array &$errors): void
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = 'CHANGELOG.md could not be read.';

        return;
    }

    /** @var array<int, array<int, string>> $matched */
    $matched = [];
    preg_match_all('/`([0-9a-f]{7,40})`/', $contents, $matched);
    $citations = array_values(array_unique($matched[1] ?? []));
    if ($citations === []) {
        $errors[] = 'CHANGELOG.md cites no commits, so completed work has no reachable evidence.';

        return;
    }

    $status = 0;
    $reachable = [];
    exec(sprintf('git -C %s rev-list HEAD 2>&1', escapeshellarg($root)), $reachable, $status);
    if ($status !== 0 || $reachable === []) {
        $errors[] = 'CHANGELOG.md commit citations cannot be verified because repository history is unavailable.';

        return;
    }

    $unreachable = [];
    $ambiguous = [];
    foreach ($citations as $citation) {
        $matches = array_values(array_filter(
            $reachable,
            static fn (string $commit): bool => str_starts_with($commit, $citation),
        ));
        if ($matches === []) {
            $unreachable[] = $citation;
        } elseif (count($matches) > 1) {
            $ambiguous[] = $citation;
        }
    }

    if ($unreachable !== []) {
        sort($unreachable, SORT_STRING);
        $errors[] = sprintf(
            'CHANGELOG.md cites commit(s) that are not reachable from HEAD: %s. Repoint each citation '
            . 'after a rebase so the historical claim keeps its evidence.',
            implode(', ', $unreachable),
        );
    }
    if ($ambiguous !== []) {
        sort($ambiguous, SORT_STRING);
        $errors[] = sprintf(
            'CHANGELOG.md abbreviates commit(s) ambiguously in reachable history: %s. Lengthen each citation '
            . 'until it names exactly one commit.',
            implode(', ', $ambiguous),
        );
    }
}

/**
 * Read one JSON document and record a failure when it is absent or malformed.
 *
 * @param   string        $path    Absolute path to the document.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  array<string, mixed> Decoded document, or an empty array when it could not be read.
 *
 * @since   2.0.0
 */
function readLedger(string $path, array &$errors): array
{
    $name = basename($path);
    if (!is_file($path)) {
        $errors[] = sprintf('%s is missing.', $name);

        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        $errors[] = sprintf('%s could not be read.', $name);

        return [];
    }

    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errors[] = sprintf('%s is not well-formed JSON: %s.', $name, json_last_error_msg());

        return [];
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Require a value to be a JSON array and return it as a list.
 *
 * @param   mixed         $value   Candidate value.
 * @param   string        $label   Diagnostic label naming the member.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  list<mixed> The list, or an empty list when the value was the wrong shape.
 *
 * @since   2.0.0
 */
function expectRoadmapList(mixed $value, string $label, array &$errors): array
{
    if (!is_array($value) || array_is_list($value) === false) {
        $errors[] = sprintf('findings.json member "%s" must be a JSON array.', $label);

        return [];
    }

    return $value;
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
function reportRoadmapFailure(array $errors): never
{
    $errors = array_values(array_unique($errors));
    fwrite(STDERR, "Kumwe roadmap verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
