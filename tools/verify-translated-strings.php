#!/usr/bin/env php
<?php

/**
 * Fail the build when a template introduces user-facing text outside the message catalogue.
 *
 * A convention without a gate is a suggestion. This is the gate: it walks every Twig template,
 * decides which text a person actually reads, and refuses any of it that was written inline instead
 * of being looked up. It also proves the two directions of the catalogue contract — that every
 * identifier a template references exists in the source catalogue, and that every identifier the
 * source catalogue carries is referenced by something.
 *
 * What counts as user-facing is stated rather than guessed, and the register of what is deliberately
 * exempt lives in `tools/translation-extraction.json` with a reason on every entry. A template that
 * appears in neither the enforced set nor the register is enforced, so a newly added template cannot
 * quietly reintroduce hardcoded text. Studio shell messages are the sole runtime-owned exception:
 * the vendored web component references them, while `sync-studio-localization.mjs --check` separately
 * proves the exact namespace is byte-for-byte complete against the coordinated release corpus.
 *
 * Usage:
 *   php tools/verify-translated-strings.php [--json]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

/**
 * One piece of text the gate refuses, located precisely enough to fix without searching.
 *
 * @since  2.0.0
 */
final class TranslationViolation
{
    /**
     * Record one refusal.
     *
     * @param  string  $file    Repository-relative path of the template.
     * @param  int     $line    One-based line the text begins on.
     * @param  string  $kind    Where the text was found: `text`, `attribute` or `expression`.
     * @param  string  $detail  The offending text, bounded for display.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $kind,
        public readonly string $detail,
    ) {
    }
}

/**
 * Read the register that says what is exempt and why.
 *
 * @param  string  $path  Absolute path of the register.
 *
 * @return array{
 *     translatable_attributes: list<string>,
 *     ignored_elements: list<string>,
 *     allowed_literals: list<array{value: string, reason: string}>,
 *     pending_extraction: list<array{path: string, reason: string}>,
 *     user_facing_keys: list<string>,
 *     untranslatable_categories: list<array{category: string, reason: string}>,
 *     untranslatable_sources: list<array{path: string, category: string, reason: string}>
 * } The register, validated.
 *
 * @since  2.0.0
 */
function translation_register(string $path): array
{
    $encoded = file_get_contents($path);
    if (!is_string($encoded)) {
        fwrite(STDERR, sprintf("The extraction register %s cannot be read.\n", $path));
        exit(66);
    }
    /** @var mixed $decoded */
    $decoded = json_decode($encoded, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, sprintf("The extraction register %s is not a JSON object.\n", $path));
        exit(65);
    }
    $required = [
        'translatable_attributes',
        'ignored_elements',
        'allowed_literals',
        'pending_extraction',
        'user_facing_keys',
        'untranslatable_categories',
        'untranslatable_sources',
    ];
    foreach ($required as $key) {
        if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
            fwrite(STDERR, sprintf("The extraction register is missing the %s list.\n", $key));
            exit(65);
        }
    }
    foreach ($decoded['allowed_literals'] as $entry) {
        if (!is_array($entry) || !isset($entry['value'], $entry['reason']) || $entry['reason'] === '') {
            fwrite(STDERR, "Every allowed literal must name the reason it is not translated.\n");
            exit(65);
        }
    }
    foreach ($decoded['pending_extraction'] as $entry) {
        if (!is_array($entry) || !isset($entry['path'], $entry['reason']) || $entry['reason'] === '') {
            fwrite(STDERR, "Every pending template must name the reason it is not extracted yet.\n");
            exit(65);
        }
    }
    $categories = [];
    foreach ($decoded['untranslatable_categories'] as $entry) {
        if (!is_array($entry) || !isset($entry['category'], $entry['reason']) || $entry['reason'] === '') {
            fwrite(STDERR, "Every untranslatable category must name the reason it is not translated.\n");
            exit(65);
        }
        $categories[$entry['category']] = true;
    }
    foreach ($decoded['untranslatable_sources'] as $entry) {
        if (
            !is_array($entry)
            || !isset($entry['path'], $entry['category'], $entry['reason'])
            || $entry['reason'] === ''
        ) {
            fwrite(STDERR, "Every untranslatable source must name its category and the reason for it.\n");
            exit(65);
        }
        if (!isset($categories[$entry['category']])) {
            fwrite(STDERR, sprintf(
                "The untranslatable source %s claims the category %s, which the register does not declare.\n",
                (string) $entry['path'],
                (string) $entry['category'],
            ));
            exit(65);
        }
    }

    /**
     * @var array{
     *     translatable_attributes: list<string>,
     *     ignored_elements: list<string>,
     *     allowed_literals: list<array{value: string, reason: string}>,
     *     pending_extraction: list<array{path: string, reason: string}>,
     *     user_facing_keys: list<string>,
     *     untranslatable_categories: list<array{category: string, reason: string}>,
     *     untranslatable_sources: list<array{path: string, category: string, reason: string}>
     * } $decoded
     */
    return $decoded;
}

/**
 * Blank out every Twig construct so what remains is the markup and its literal text.
 *
 * Length is preserved, so a byte offset in the blanked string still points at the same byte of the
 * original and a violation can report the line it really sits on.
 *
 * @param  string  $source  Template source.
 *
 * @return string The source with `{{ }}`, `{% %}` and `{# #}` replaced by spaces.
 *
 * @since  2.0.0
 */
function blank_twig(string $source): string
{
    return (string) preg_replace_callback(
        '/\{[{%#].*?[}%#]\}/s',
        static fn (array $match): string => str_repeat(' ', strlen($match[0])),
        $source,
    );
}

/**
 * Blank out the elements whose contents are never read as prose.
 *
 * @param  string        $source    Template source.
 * @param  list<string>  $elements  Element names to blank, contents and all.
 *
 * @return string The source with those elements replaced by spaces.
 *
 * @since  2.0.0
 */
function blank_elements(string $source, array $elements): string
{
    foreach ($elements as $element) {
        $source = (string) preg_replace_callback(
            '/<' . preg_quote($element, '/') . '\b.*?<\/' . preg_quote($element, '/') . '>/is',
            static fn (array $match): string => str_repeat(' ', strlen($match[0])),
            $source,
        );
    }

    return $source;
}

/**
 * Decide whether a fragment is text a person reads rather than a machine token.
 *
 * Two or more consecutive letters is the test. It admits every word and excludes punctuation,
 * numbers, single-letter keyboard hints, hexadecimal colours and path fragments, which is the line
 * this gate draws between prose and machinery.
 *
 * @param  string  $text  Candidate fragment, already stripped of Twig constructs.
 *
 * @return bool True when the fragment reads as words.
 *
 * @since  2.0.0
 */
function reads_as_prose(string $text): bool
{
    return preg_match('/[A-Za-z]{2,}/', $text) === 1;
}

/**
 * Count the line a byte offset falls on.
 *
 * @param  string  $source  Complete template source.
 * @param  int     $offset  Byte offset into that source.
 *
 * @return int One-based line number.
 *
 * @since  2.0.0
 */
function line_at(string $source, int $offset): int
{
    return substr_count(substr($source, 0, $offset), "\n") + 1;
}

/**
 * Scan one template for user-facing text written outside the catalogue.
 *
 * @param  string                                        $relative   Repository-relative path, used in reports.
 * @param  string                                        $source     Template source.
 * @param  list<string>                                  $attributes Attribute names whose values are read.
 * @param  list<string>                                  $elements   Element names whose contents are ignored.
 * @param  array<string, true>                           $allowed    Literals exempt by exact value.
 *
 * @return list<TranslationViolation> Every refusal in the file, in source order.
 *
 * @since  2.0.0
 */
function scan_template(
    string $relative,
    string $source,
    array $attributes,
    array $elements,
    array $allowed,
): array {
    $violations = [];
    $markup = blank_twig(blank_elements($source, $elements));

    // Text nodes: whatever sits between two tags once Twig and ignored elements are blanked.
    $offset = 0;
    $length = strlen($markup);
    while ($offset < $length) {
        $open = strpos($markup, '<', $offset);
        $text = $open === false ? substr($markup, $offset) : substr($markup, $offset, $open - $offset);
        foreach (preg_split('/\n/', $text) === false ? [] : explode("\n", $text) as $index => $line) {
            $trimmed = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($trimmed === '' || isset($allowed[$trimmed]) || !reads_as_prose($trimmed)) {
                continue;
            }
            $violations[] = new TranslationViolation(
                $relative,
                line_at($source, $offset) + $index,
                'text',
                $trimmed,
            );
        }
        if ($open === false) {
            break;
        }
        $close = strpos($markup, '>', $open);
        if ($close === false) {
            break;
        }
        $tag = substr($markup, $open, $close - $open + 1);
        foreach (scan_attributes($tag, $attributes, $allowed) as $value) {
            $violations[] = new TranslationViolation($relative, line_at($source, $open), 'attribute', $value);
        }
        $offset = $close + 1;
    }

    foreach (scan_expressions($source, $allowed) as $offsetInSource => $literal) {
        $violations[] = new TranslationViolation(
            $relative,
            line_at($source, $offsetInSource),
            'expression',
            $literal,
        );
    }

    return $violations;
}

/**
 * Read the translatable attributes of one tag.
 *
 * @param  string               $tag         Complete tag, Twig already blanked.
 * @param  list<string>         $attributes  Attribute names whose values are read.
 * @param  array<string, true>  $allowed     Literals exempt by exact value.
 *
 * @return list<string> Offending attribute values, formatted for a report.
 *
 * @since  2.0.0
 */
function scan_attributes(string $tag, array $attributes, array $allowed): array
{
    $offending = [];
    $matched = preg_match_all('/([A-Za-z][A-Za-z0-9-]*)\s*=\s*("[^"]*"|\'[^\']*\')/', $tag, $matches, PREG_SET_ORDER);
    if ($matched === false) {
        return [];
    }
    foreach ($matches as $match) {
        if (!in_array(strtolower($match[1]), $attributes, true)) {
            continue;
        }
        $value = trim(html_entity_decode(substr($match[2], 1, -1), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || isset($allowed[$value]) || !reads_as_prose($value)) {
            continue;
        }
        $offending[] = $match[1] . '="' . $value . '"';
    }

    return $offending;
}

/**
 * Read the string literals inside Twig expressions that carry a sentence rather than a token.
 *
 * A literal with a space in it is prose; `core.public.page`, `layout.twig` and `ltr` are not. That
 * is a heuristic, and it is the right one here: an identifier with a space in it is already refused
 * by the message-identifier grammar, so the only thing this can catch is wording.
 *
 * @param  string               $source   Template source, Twig constructs intact.
 * @param  array<string, true>  $allowed  Literals exempt by exact value.
 *
 * @return array<int, string> Offending literals keyed by their byte offset in the source.
 *
 * @since  2.0.0
 */
function scan_expressions(string $source, array $allowed): array
{
    $offending = [];
    $matched = preg_match_all('/\{[{%].*?[}%]\}/s', $source, $constructs, PREG_OFFSET_CAPTURE);
    if ($matched === false) {
        return [];
    }
    foreach ($constructs[0] as $construct) {
        [$expression, $start] = [$construct[0], $construct[1]];
        $found = preg_match_all('/\'[^\']*\'|"[^"]*"/', $expression, $literals, PREG_OFFSET_CAPTURE);
        if ($found === false) {
            continue;
        }
        foreach ($literals[0] as $literal) {
            $value = substr($literal[0], 1, -1);
            if (!str_contains($value, ' ') || isset($allowed[$value]) || !reads_as_prose($value)) {
                continue;
            }
            $offending[$start + $literal[1]] = $value;
        }
    }

    return $offending;
}

/**
 * Scan one PHP source for user-facing text written inline rather than looked up.
 *
 * Two surfaces are covered, and each is recognised by a shape rather than by a guess.
 *
 * Console output is text a command hands to the sink the `Command` contract names `$output`. A
 * command that writes wording writes it through `message()` or `failure()`, which take an
 * identifier; a prose literal reaching `line()` or `error()` — directly or through `sprintf()` — is
 * therefore text that never entered the catalogue. Machine output keeps using `line()` and
 * `error()` deliberately: a JSON envelope, an identifier and a secret printed once are not wording,
 * and none of them is prose by this scanner's test.
 *
 * A user-facing error path is recognised by the key the text is filed under on its way to a
 * renderer or a response: `error`, `detail`, `summary` and their siblings are read by a person, so
 * a prose literal sitting under one is wording. Text that exists for a machine or a developer is
 * exempt by path, and every exemption names its category and the reason that category is not
 * translatable.
 *
 * @param  string               $relative  Repository-relative path, used in reports.
 * @param  string               $source    PHP source.
 * @param  list<string>         $keys      Array keys whose string values a person reads.
 * @param  array<string, true>  $exempt    Repository-relative paths exempt from the rendered-text rule.
 *
 * @return list<TranslationViolation> Every refusal in the file, in source order.
 *
 * @since  2.0.0
 */
function scan_source(string $relative, string $source, array $keys, array $exempt): array
{
    $violations = [];
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $value = substr($token[1], 1, -1);
        if (!str_contains($value, ' ') || !reads_as_prose($value)) {
            continue;
        }
        $preceding = [];
        for ($back = $index - 1; $back >= 0 && count($preceding) < 8; $back--) {
            $candidate = $tokens[$back];
            if (
                is_array($candidate)
                && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }
            $preceding[] = is_array($candidate) ? [$candidate[0], $candidate[1]] : [null, $candidate];
        }
        if (writes_console_output($preceding)) {
            $violations[] = new TranslationViolation($relative, $token[2], 'console', $value);
            continue;
        }
        if (isset($exempt[$relative])) {
            continue;
        }
        if (
            ($preceding[0][1] ?? '') === '=>'
            && ($preceding[1][0] ?? null) === T_CONSTANT_ENCAPSED_STRING
            && in_array(substr($preceding[1][1], 1, -1), $keys, true)
        ) {
            $violations[] = new TranslationViolation(
                $relative,
                $token[2],
                'rendered ' . substr($preceding[1][1], 1, -1),
                $value,
            );
        }
    }

    return $violations;
}

/**
 * Decide whether a literal is being handed straight to the console output sink.
 *
 * The receiver has to be the `$output` the `Command` contract names, so a PSR-3 logger's `error()`
 * — which is a log line, and deliberately not translated — is not mistaken for console wording.
 *
 * @param  list<array{0: ?int, 1: string}>  $preceding  Significant tokens before the literal, nearest first.
 *
 * @return bool True when the literal is the first argument of `$output->line()` or `$output->error()`.
 *
 * @since  2.0.0
 */
function writes_console_output(array $preceding): bool
{
    if (($preceding[0][1] ?? '') !== '(') {
        return false;
    }
    $offset = 1;
    if (($preceding[$offset][1] ?? '') === 'sprintf' && ($preceding[$offset + 1][1] ?? '') === '(') {
        $offset += 2;
    }
    $method = $preceding[$offset][1] ?? '';
    $arrow = $preceding[$offset + 1][0] ?? null;
    $receiver = $preceding[$offset + 2][1] ?? '';

    return in_array($method, ['line', 'error'], true)
        && $arrow === T_OBJECT_OPERATOR
        && str_starts_with($receiver, '$output');
}

/**
 * Collect every message identifier the templates reference through `t` or `t_html`.
 *
 * @param  string  $source  Template source.
 *
 * @return list<string> Identifiers in the order they appear.
 *
 * @since  2.0.0
 */
function referenced_identifiers(string $source): array
{
    $matched = preg_match_all('/\bt(?:_html)?\(\s*\'([^\']+)\'/', $source, $matches);

    return $matched === false ? [] : $matches[1];
}

/**
 * Collect every message identifier a PHP source references through the translation surfaces.
 *
 * Three shapes count as a reference: a call on the translator port (`translate`, `has`), a call on
 * the console output surface (`message`, `failure`, `text`), and the literal a console command's
 * `description()` returns, which the command listing resolves. Only literals satisfying the frozen
 * three-segment identifier grammar count, so a stable machine code such as
 * `business_record.not_found` is never mistaken for a catalogue reference.
 *
 * @param  string  $source  PHP source.
 *
 * @return list<string> Identifiers in the order they appear.
 *
 * @since  2.0.0
 */
function php_referenced_identifiers(string $source): array
{
    $identifiers = [];
    $matched = preg_match_all(
        '/->(?:translate|has|message|failure|text)\(\s*\'([a-z0-9_.-]+)\'/',
        $source,
        $matches,
    );
    foreach ($matched === false ? [] : $matches[1] as $candidate) {
        $identifiers[] = $candidate;
    }
    $matched = preg_match_all(
        '/function description\(\): string\s*\{\s*return \'([a-z0-9_.-]+)\';/s',
        $source,
        $matches,
    );
    foreach ($matched === false ? [] : $matches[1] as $candidate) {
        $identifiers[] = $candidate;
    }

    return array_values(array_filter(
        $identifiers,
        static fn (string $identifier): bool =>
            preg_match('/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*){2,}$/D', $identifier) === 1,
    ));
}

/**
 * Collect every grammar-shaped literal a PHP source carries, wherever it sits.
 *
 * A handler that maps stable keys onto message identifiers and resolves them through a variable —
 * a notice table, a step-label map, a class constant — references its identifiers without the call
 * shapes above. Such a literal anchors its catalogue entry against the orphan check, but it is
 * deliberately not treated as a resolvable reference: only an explicit call proves the identifier
 * must exist, so a stable machine code that happens to have three segments cannot fail the build.
 *
 * @param  string  $source  PHP source.
 *
 * @return list<string> Identifier-shaped literals in the order they appear.
 *
 * @since  2.0.0
 */
function php_anchored_identifiers(string $source): array
{
    $matched = preg_match_all(
        '/\'([a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*){2,})\'/',
        $source,
        $matches,
    );

    return $matched === false ? [] : $matches[1];
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$asJson = $arguments === ['--json'];
if ($arguments !== [] && !$asJson) {
    fwrite(STDERR, "Usage: php tools/verify-translated-strings.php [--json]\n");
    exit(64);
}

$register = translation_register($root . '/tools/translation-extraction.json');
$attributes = array_map(strtolower(...), $register['translatable_attributes']);
$allowed = [];
foreach ($register['allowed_literals'] as $entry) {
    $allowed[$entry['value']] = true;
}
$pending = [];
foreach ($register['pending_extraction'] as $entry) {
    $pending[$entry['path']] = $entry['reason'];
}
$exemptSources = [];
foreach ($register['untranslatable_sources'] as $entry) {
    $exemptSources[$entry['path']] = $entry['reason'];
}

$templates = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $root . '/templates',
    FilesystemIterator::SKIP_DOTS,
));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'twig') {
        $templates[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
sort($templates, SORT_STRING);
if ($templates === []) {
    fwrite(STDERR, "No Twig template was found to check.\n");
    exit(66);
}

$violations = [];
$referenced = [];
$promotable = [];
foreach ($templates as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        fwrite(STDERR, sprintf("The template %s cannot be read.\n", $relative));
        exit(66);
    }
    foreach (referenced_identifiers($source) as $identifier) {
        $referenced[$identifier] = true;
    }
    $found = scan_template($relative, $source, $attributes, $register['ignored_elements'], $allowed);
    if (isset($pending[$relative])) {
        if ($found === []) {
            $promotable[] = $relative;
        }
        continue;
    }
    foreach ($found as $violation) {
        $violations[] = $violation;
    }
}

$sources = [];
$anchored = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $root . '/src',
    FilesystemIterator::SKIP_DOTS,
));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
        $sources[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
sort($sources, SORT_STRING);
foreach ($sources as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        fwrite(STDERR, sprintf("The source file %s cannot be read.\n", $relative));
        exit(66);
    }
    foreach (php_referenced_identifiers($source) as $identifier) {
        $referenced[$identifier] = true;
    }
    foreach (php_anchored_identifiers($source) as $identifier) {
        $anchored[$identifier] = true;
    }
    foreach (scan_source($relative, $source, $register['user_facing_keys'], $exemptSources) as $violation) {
        $violations[] = $violation;
    }
}

$stale = [];
foreach (array_keys($pending) as $path) {
    if (!in_array($path, $templates, true)) {
        $stale[] = $path;
    }
}
foreach (array_keys($exemptSources) as $path) {
    if (!in_array($path, $sources, true)) {
        $stale[] = $path;
    }
}

$catalogue = [];
$compiled = $root . '/resources/localization/compiled/en-GB.php';
if (is_file($compiled)) {
    /** @var mixed $loaded */
    $loaded = require $compiled;
    if (is_array($loaded)) {
        foreach ($loaded as $identifier => $pattern) {
            if (is_string($identifier) && is_string($pattern)) {
                $catalogue[$identifier] = true;
            }
        }
    }
}
$missing = array_values(array_diff(array_keys($referenced), array_keys($catalogue)));
$runtimeOwned = [];
foreach (array_keys($catalogue) as $identifier) {
    if (str_starts_with($identifier, 'core.studio.shell.')) {
        $runtimeOwned[] = $identifier;
    }
}
$orphaned = array_values(array_diff(
    array_keys($catalogue),
    array_keys($referenced),
    array_keys($anchored),
    $runtimeOwned,
));
sort($missing, SORT_STRING);
sort($orphaned, SORT_STRING);

if ($asJson) {
    fwrite(STDOUT, json_encode([
        'templates' => count($templates),
        'sources' => count($sources),
        'exempt_sources' => count($exemptSources),
        'enforced' => count($templates) - count($pending),
        'pending' => count($pending),
        'messages' => count($catalogue),
        'violations' => array_map(static fn (TranslationViolation $violation): array => [
            'file' => $violation->file,
            'line' => $violation->line,
            'kind' => $violation->kind,
            'detail' => $violation->detail,
        ], $violations),
        'missing_identifiers' => $missing,
        'orphaned_identifiers' => $orphaned,
        'stale_register_entries' => $stale,
        'promotable_templates' => $promotable,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit($violations === [] && $missing === [] && $orphaned === [] && $stale === [] && $promotable === [] ? 0 : 1);
}

$failed = false;
foreach ($violations as $violation) {
    $failed = true;
    fwrite(STDERR, sprintf(
        "%s:%d: user-facing %s written inline: %s\n",
        $violation->file,
        $violation->line,
        $violation->kind,
        strlen($violation->detail) > 90 ? substr($violation->detail, 0, 87) . '...' : $violation->detail,
    ));
}
if ($violations !== []) {
    fwrite(STDERR, sprintf(
        "\n%d user-facing string(s) are written inline. Give each one a message identifier, add it to "
            . "resources/localization/messages/en-GB.xlf, run composer translation:compile, and look it up: "
            . "with t() in a template, with the console output's message() or failure(), or through the "
            . "translator on an error path. Text that exists for a machine or a developer instead earns an "
            . "untranslatable_sources entry in tools/translation-extraction.json naming its category.\n",
        count($violations),
    ));
}
foreach ($missing as $identifier) {
    $failed = true;
    fwrite(STDERR, sprintf("A template references %s, which the source catalogue does not carry.\n", $identifier));
}
foreach ($orphaned as $identifier) {
    $failed = true;
    fwrite(STDERR, sprintf("The source catalogue carries %s, which no template references.\n", $identifier));
}
foreach ($stale as $path) {
    $failed = true;
    fwrite(STDERR, sprintf("The extraction register names %s, which no longer exists.\n", $path));
}
foreach ($promotable as $path) {
    $failed = true;
    fwrite(STDERR, sprintf(
        "%s carries no inline user-facing text any more; remove it from the extraction register so it "
            . "stays extracted.\n",
        $path,
    ));
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, sprintf(
    "%d template(s) checked, %d enforced and %d awaiting extraction; %d source file(s) checked, %d exempt "
        . "by category; %d message(s) resolve.\n",
    count($templates),
    count($templates) - count($pending),
    count($pending),
    count($sources),
    count($exemptSources),
    count($catalogue),
));
