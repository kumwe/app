<?php

/**
 * Evaluate every dependency edge in `src/` against the declared layer graph.
 *
 * The architecture gate used to be four textual predicates — a product-name spelling, two forbidden import
 * prefixes and two static-locator symbols — and it printed "verified" without ever resolving a single
 * dependency edge. A file could import Doctrine into the application layer, or reach from the domain into a
 * delivery adapter, and nothing said so.
 *
 * This check reads the layer graph in `docs/architecture/layers.json`, resolves each file under `src/` to
 * the layer its namespace places it in, extracts every first-party symbol the file actually references —
 * imports, grouped imports, aliased imports and inline fully qualified names, taken from the token stream
 * rather than matched with a regular expression — and fails on any edge the graph forbids.
 *
 * Existing violations live in `docs/architecture/dependency-baseline.json`, each with the finding that owns
 * it, an owner and an expiry. The baseline only ever shrinks: a new violation fails immediately, an entry
 * that no longer violates fails as stale so it has to be deleted, and an entry past its expiry fails so the
 * exemption cannot outlive the work that justified it.
 *
 * Usage:
 *   php tools/verify-dependency-graph.php [--layers=PATH] [--baseline=PATH] [--emit-baseline] [--today=DATE]
 *
 * `--emit-baseline` prints the baseline the current tree would need, which is how the committed one was
 * produced. It never writes the file itself, so a violation cannot be waved through by running a tool.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$layersPath = $root . '/docs/architecture/layers.json';
$baselinePath = $root . '/docs/architecture/dependency-baseline.json';
$emit = false;
$today = date('Y-m-d');
$errors = [];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--emit-baseline') {
        $emit = true;
        continue;
    }
    if (str_starts_with($argument, '--layers=')) {
        $layersPath = substr($argument, strlen('--layers='));
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

    $errors[] = sprintf('Unknown argument %s.', $argument);
}

if ($errors !== []) {
    reportGraphFailure($errors);
}

$graph = readGraphDocument($layersPath);
$layers = readLayerRules($graph);
$classification = readClassification($graph);
$violations = collectViolations($root . '/src', $layers, $classification, $errors);

if ($errors !== []) {
    reportGraphFailure($errors);
}

if ($emit) {
    fwrite(STDOUT, emitBaseline($violations) . "\n");
    exit(0);
}

exit(compareWithBaseline($violations, $baselinePath, $today));

/**
 * Read one JSON document, refusing anything that is not a decodable object.
 *
 * @param   string  $path  Absolute path to the document.
 *
 * @return  array<string, mixed>  The decoded document.
 *
 * @since   2.0.0
 */
function readGraphDocument(string $path): array
{
    if (!is_file($path)) {
        reportGraphFailure([sprintf('%s is missing.', $path)]);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        reportGraphFailure([sprintf('%s could not be read.', $path)]);
    }
    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        reportGraphFailure([sprintf('%s is not well-formed JSON: %s.', $path, json_last_error_msg())]);
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Read the per-layer permission table.
 *
 * @param   array<string, mixed>  $graph  Decoded layer document.
 *
 * @return  array<string, list<string>>  Layers each layer may depend on, by layer name.
 *
 * @since   2.0.0
 */
function readLayerRules(array $graph): array
{
    /** @var mixed $declared */
    $declared = $graph['layers'] ?? null;
    if (!is_array($declared) || $declared === []) {
        reportGraphFailure(['The layer graph must declare a non-empty "layers" object.']);
    }

    $rules = [];
    foreach ($declared as $name => $layer) {
        if (!is_string($name) || !is_array($layer)) {
            reportGraphFailure(['Every entry in "layers" must map a layer name to an object.']);
        }
        /** @var mixed $allowed */
        $allowed = $layer['may_depend_on'] ?? null;
        if (!is_array($allowed) || !array_is_list($allowed)) {
            reportGraphFailure([sprintf('Layer "%s" must declare may_depend_on as an array.', $name)]);
        }
        $names = [];
        foreach ($allowed as $entry) {
            if (!is_string($entry) || $entry === '') {
                reportGraphFailure([sprintf('Layer "%s" has a malformed may_depend_on entry.', $name)]);
            }
            $names[] = $entry;
        }
        $rules[$name] = $names;
    }

    foreach ($rules as $name => $allowed) {
        foreach ($allowed as $target) {
            if ($target === '*' || isset($rules[$target])) {
                continue;
            }
            reportGraphFailure([sprintf('Layer "%s" may depend on "%s", which is not a declared layer.', $name, $target)]);
        }
    }

    return $rules;
}

/**
 * Read the namespace-to-layer classification rules.
 *
 * @param   array<string, mixed>  $graph  Decoded layer document.
 *
 * @return  array{segments: array<string, string>, prefixes: array<string, string>}  Classification rules.
 *
 * @since   2.0.0
 */
function readClassification(array $graph): array
{
    /** @var mixed $segments */
    $segments = $graph['namespace_segments'] ?? null;
    /** @var mixed $prefixes */
    $prefixes = $graph['namespace_prefixes'] ?? null;
    if (!is_array($segments) || !is_array($prefixes)) {
        reportGraphFailure(['The layer graph must declare "namespace_segments" and "namespace_prefixes".']);
    }

    $bySegment = [];
    foreach ($segments as $segment => $layer) {
        if (!is_string($segment) || !is_string($layer)) {
            reportGraphFailure(['Every namespace_segments entry maps a segment name to a layer name.']);
        }
        $bySegment[$segment] = $layer;
    }

    $byPrefix = [];
    foreach ($prefixes as $prefix => $layer) {
        if (!is_string($prefix) || !is_string($layer)) {
            reportGraphFailure(['Every namespace_prefixes entry maps a namespace prefix to a layer name.']);
        }
        $byPrefix[$prefix] = $layer;
    }

    return ['segments' => $bySegment, 'prefixes' => $byPrefix];
}

/**
 * Resolve the layer a first-party class name belongs to.
 *
 * The longest declared prefix wins, so a module that places one subtree in a different layer than its
 * namespace segment would suggest can say so without disturbing the general rule. Otherwise the first
 * namespace segment that names a layer decides, which is what makes `BusinessRecord\Domain` a domain
 * namespace and `Extension\Application` an application one without either being listed by hand.
 *
 * @param   string                                                             $class           Fully
 *          qualified first-party class name.
 * @param   array{segments: array<string, string>, prefixes: array<string, string>}  $classification  Rules.
 *
 * @return  string|null  The layer name, or null when nothing classifies the namespace.
 *
 * @since   2.0.0
 */
function layerFor(string $class, array $classification): ?string
{
    $best = null;
    $bestLength = -1;
    foreach ($classification['prefixes'] as $prefix => $layer) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $length = strlen($prefix);
        if ($length > $bestLength) {
            $best = $layer;
            $bestLength = $length;
        }
    }
    if ($best !== null) {
        return $best;
    }

    $segments = explode('\\', $class);
    foreach ($segments as $segment) {
        if (isset($classification['segments'][$segment])) {
            return $classification['segments'][$segment];
        }
    }

    return null;
}

/**
 * Walk `src/` and return every dependency edge the layer graph forbids.
 *
 * @param   string                       $sourceRoot      Absolute path to `src/`.
 * @param   array<string, list<string>>  $layers          Layer permission table.
 * @param   array{segments: array<string, string>, prefixes: array<string, string>}  $classification  Rules.
 * @param   list<string>                 $errors          Accumulated failures.
 *
 * @return  array<string, array{from: string, to: string, from_layer: string, to_layer: string,
 *          file: string, line: int}>  Violating edges keyed by "from -> to".
 *
 * @since   2.0.0
 */
function collectViolations(string $sourceRoot, array $layers, array $classification, array &$errors): array
{
    $violations = [];
    $unclassified = [];

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $contents = file_get_contents($path);
        if ($contents === false) {
            $errors[] = sprintf('%s could not be read.', $path);
            continue;
        }

        $references = extractReferences($contents);
        $owner = $references['namespace'] === ''
            ? ''
            : $references['namespace'] . '\\' . basename($path, '.php');
        if ($owner === '' || !str_starts_with($owner, 'Kumwe\\CMS\\')) {
            continue;
        }

        $fromLayer = layerFor($owner, $classification);
        if ($fromLayer === null) {
            $unclassified[$references['namespace']] = true;
            continue;
        }
        $allowed = $layers[$fromLayer] ?? null;
        if ($allowed === null) {
            $errors[] = sprintf('%s resolves to layer "%s", which the graph does not declare.', $owner, $fromLayer);
            continue;
        }
        if (in_array('*', $allowed, true)) {
            continue;
        }

        foreach ($references['targets'] as $target => $line) {
            if (!str_starts_with($target, 'Kumwe\\CMS\\') || $target === $owner) {
                continue;
            }
            $toLayer = layerFor($target, $classification);
            if ($toLayer === null || $toLayer === $fromLayer || in_array($toLayer, $allowed, true)) {
                continue;
            }
            $key = $owner . ' -> ' . $target;
            $violations[$key] = [
                'from' => $owner,
                'to' => $target,
                'from_layer' => $fromLayer,
                'to_layer' => $toLayer,
                'file' => substr($path, strlen(dirname($sourceRoot)) + 1),
                'line' => $line,
            ];
        }
    }

    if ($unclassified !== []) {
        $names = array_keys($unclassified);
        sort($names, SORT_STRING);
        $errors[] = sprintf(
            'These namespaces belong to no declared layer, so nothing governs what they may depend on: %s. '
            . 'Add each to namespace_prefixes in docs/architecture/layers.json.',
            implode(', ', $names),
        );
    }

    ksort($violations, SORT_STRING);

    return $violations;
}

/**
 * Extract a file's namespace and every first-party symbol it references, from the token stream.
 *
 * Imports, grouped imports and aliased imports are read from `use` statements; inline references are read
 * from fully qualified name tokens. Function and constant imports are skipped because they carry no layer
 * meaning, and a relative qualified name is resolved against the file's own namespace.
 *
 * @param   string  $contents  The file's source.
 *
 * @return  array{namespace: string, targets: array<string, int>}  The namespace and the referenced class
 *          names mapped to the first line each was seen on.
 *
 * @since   2.0.0
 */
function extractReferences(string $contents): array
{
    $tokens = token_get_all($contents);
    $namespace = '';
    $targets = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAMESPACE) {
            $namespace = readQualifiedName($tokens, $index);
            continue;
        }

        if ($token[0] === T_USE) {
            $line = $token[2];
            foreach (readUseStatement($tokens, $index) as $imported) {
                $targets[$imported] ??= $line;
            }
            continue;
        }

        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            $targets[ltrim($token[1], '\\')] ??= $token[2];
            continue;
        }

        if ($token[0] === T_NAME_QUALIFIED && $namespace !== '') {
            $targets[$namespace . '\\' . $token[1]] ??= $token[2];
        }
    }

    return ['namespace' => $namespace, 'targets' => $targets];
}

/**
 * Read the qualified name that follows a keyword token.
 *
 * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  The token stream.
 * @param   int                                            $index   Position of the keyword.
 *
 * @return  string  The name, or an empty string when the keyword introduces no name.
 *
 * @since   2.0.0
 */
function readQualifiedName(array $tokens, int $index): string
{
    $count = count($tokens);
    for ($cursor = $index + 1; $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_string($token)) {
            return '';
        }
        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return ltrim($token[1], '\\');
        }

        return '';
    }

    return '';
}

/**
 * Read every class name one `use` statement imports, including grouped and aliased forms.
 *
 * @param   list<array{0: int, 1: string, 2: int}|string>  $tokens  The token stream.
 * @param   int                                            $index   Position of the `use` keyword.
 *
 * @return  list<string>  Imported class names; empty for a function import, a constant import or a trait
 *          use inside a class body.
 *
 * @since   2.0.0
 */
function readUseStatement(array $tokens, int $index): array
{
    $count = count($tokens);
    $names = [];
    $prefix = '';
    $current = '';
    $grouped = false;

    for ($cursor = $index + 1; $cursor < $count; $cursor++) {
        $token = $tokens[$cursor];
        if (is_string($token)) {
            if ($token === ';') {
                break;
            }
            if ($token === '{') {
                $grouped = true;
                $prefix = rtrim($current, '\\');
                $current = '';
                continue;
            }
            if ($token === '}') {
                break;
            }
            if ($token === ',') {
                $names[] = $grouped && $prefix !== '' ? $prefix . '\\' . $current : $current;
                $current = '';
                continue;
            }
            if ($token === '(') {
                return [];
            }
            continue;
        }
        if (in_array($token[0], [T_FUNCTION, T_CONST], true)) {
            return [];
        }
        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($token[0] === T_AS) {
            $names[] = $grouped && $prefix !== '' ? $prefix . '\\' . $current : $current;
            $current = '';
            for ($cursor++; $cursor < $count; $cursor++) {
                $next = $tokens[$cursor];
                if (is_string($next) && ($next === ',' || $next === ';')) {
                    break;
                }
            }
            if (is_string($tokens[$cursor] ?? null) && $tokens[$cursor] === ';') {
                break;
            }
            continue;
        }
        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $current .= ltrim($token[1], '\\');
            continue;
        }
        if ($token[0] === T_NS_SEPARATOR) {
            $current .= '\\';
        }
    }

    if ($current !== '') {
        $names[] = $grouped && $prefix !== '' ? $prefix . '\\' . $current : $current;
    }

    return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
}

/**
 * Render the baseline document the current tree would need.
 *
 * @param   array<string, array{from: string, to: string, from_layer: string, to_layer: string,
 *          file: string, line: int}>  $violations  Violating edges.
 *
 * @return  string  A JSON document ready to be reviewed and committed.
 *
 * @since   2.0.0
 */
function emitBaseline(array $violations): string
{
    $entries = [];
    foreach ($violations as $violation) {
        $entries[] = [
            'from' => $violation['from'],
            'to' => $violation['to'],
            'from_layer' => $violation['from_layer'],
            'to_layer' => $violation['to_layer'],
            'owner' => 'UNASSIGNED',
            'finding' => 'UNASSIGNED',
            'expires' => '',
            'justification' => '',
        ];
    }

    $encoded = json_encode(
        ['baseline' => 'kumwe-dependency-direction', 'violations' => $entries],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    return $encoded === false ? '{}' : $encoded;
}

/**
 * Compare the tree's violations with the recorded baseline and report the difference.
 *
 * @param   array<string, array{from: string, to: string, from_layer: string, to_layer: string,
 *          file: string, line: int}>  $violations  Violating edges.
 * @param   string  $baselinePath  Absolute path to the baseline document.
 * @param   string  $today         Date the expiries are judged against, as `Y-m-d`.
 *
 * @return  int  Process exit status: zero when no new, stale or expired entry remains.
 *
 * @since   2.0.0
 */
function compareWithBaseline(array $violations, string $baselinePath, string $today): int
{
    $document = readGraphDocument($baselinePath);
    /** @var mixed $declared */
    $declared = $document['violations'] ?? null;
    if (!is_array($declared) || !array_is_list($declared)) {
        reportGraphFailure([sprintf('%s must declare "violations" as an array.', basename($baselinePath))]);
    }

    $errors = [];
    $recorded = [];
    foreach ($declared as $index => $entry) {
        if (!is_array($entry)) {
            $errors[] = sprintf('Baseline entry at position %d is not an object.', $index);
            continue;
        }
        $from = $entry['from'] ?? null;
        $to = $entry['to'] ?? null;
        $owner = $entry['owner'] ?? null;
        $finding = $entry['finding'] ?? null;
        $expires = $entry['expires'] ?? null;
        $justification = $entry['justification'] ?? null;
        if (!is_string($from) || !is_string($to) || $from === '' || $to === '') {
            $errors[] = sprintf('Baseline entry at position %d needs "from" and "to".', $index);
            continue;
        }
        $key = $from . ' -> ' . $to;
        if (
            !is_string($owner) || trim($owner) === '' || $owner === 'UNASSIGNED'
            || !is_string($finding) || trim($finding) === '' || $finding === 'UNASSIGNED'
            || !is_string($justification) || trim($justification) === ''
        ) {
            $errors[] = sprintf(
                'Baseline entry %s needs a named owner, the finding that removes it, and a justification. '
                . 'An exemption nobody owns is a permission.',
                $key,
            );
        }
        if (!is_string($expires) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
            $errors[] = sprintf('Baseline entry %s needs an expiry date as YYYY-MM-DD.', $key);
        } elseif ($expires < $today) {
            $errors[] = sprintf(
                'Baseline entry %s expired on %s. Fix the edge or record a new decision; an exemption does '
                . 'not outlive the work that justified it.',
                $key,
                $expires,
            );
        }
        $recorded[$key] = true;
    }

    $new = [];
    foreach ($violations as $key => $violation) {
        if (isset($recorded[$key])) {
            continue;
        }
        $new[] = sprintf(
            '%s (%s) depends on %s (%s) at %s line %d.',
            $violation['from'],
            $violation['from_layer'],
            $violation['to'],
            $violation['to_layer'],
            $violation['file'],
            $violation['line'],
        );
    }

    if ($new !== []) {
        sort($new, SORT_STRING);
        $errors[] = sprintf(
            "%d dependency edge(s) point the wrong way and are not in the baseline:\n     - %s",
            count($new),
            implode("\n     - ", $new),
        );
    }

    $stale = [];
    foreach (array_keys($recorded) as $key) {
        if (!isset($violations[$key])) {
            $stale[] = $key;
        }
    }
    if ($stale !== []) {
        sort($stale, SORT_STRING);
        $errors[] = sprintf(
            "These baseline entries no longer violate anything and must be deleted, so the baseline only "
            . "ever shrinks:\n     - %s",
            implode("\n     - ", $stale),
        );
    }

    if ($errors !== []) {
        reportGraphFailure($errors);
    }

    fwrite(
        STDOUT,
        sprintf(
            "Kumwe dependency direction verified: %d recorded exemption(s), no new violation.\n",
            count($recorded),
        ),
    );

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
function reportGraphFailure(array $errors): never
{
    $errors = array_values(array_unique($errors));
    fwrite(STDERR, "Kumwe dependency direction check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
