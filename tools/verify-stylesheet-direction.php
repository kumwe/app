#!/usr/bin/env php
<?php

/**
 * Fail the build when emitted or fallback CSS pins layout to one writing direction.
 *
 * The committed Vite manifest is authoritative for emitted CSS: every CSS output it names is
 * checked, and its CSS set must equal the recursive build-directory CSS set. The three explicitly
 * owned manifest-less runtime fallbacks are checked as well. CI rebuilds both forms and refuses any
 * tracked or untracked difference, binding this check to the source graph Vite actually consumed.
 *
 * Usage:
 *   php tools/verify-stylesheet-direction.php [--json]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

/**
 * Physical declarations that pin a rule to one direction, and the logical property to use instead.
 *
 * CSS property names and keywords are ASCII case-insensitive, so every expression is too.
 *
 * @var array<string, string>
 * @since  2.0.0
 */
const DIRECTION_BOUND = [
    '/(?<![\w-])margin-left\s*:/i' => 'margin-inline-start',
    '/(?<![\w-])margin-right\s*:/i' => 'margin-inline-end',
    '/(?<![\w-])padding-left\s*:/i' => 'padding-inline-start',
    '/(?<![\w-])padding-right\s*:/i' => 'padding-inline-end',
    '/(?<![\w-])border-left(-\w+)?\s*:/i' => 'border-inline-start',
    '/(?<![\w-])border-right(-\w+)?\s*:/i' => 'border-inline-end',
    '/(?<![\w-])border-(top|bottom)-(left|right)-radius\s*:/i' =>
        'border-start-start-radius and its siblings',
    '/(?<![\w-])left\s*:/i' => 'inset-inline-start',
    '/(?<![\w-])right\s*:/i' => 'inset-inline-end',
    '/text-align\s*:\s*left(?![\w-])/i' => 'text-align: start',
    '/text-align\s*:\s*right(?![\w-])/i' => 'text-align: end',
    '/float\s*:\s*(left|right)(?![\w-])/i' => 'a flex or grid placement',
    '/clear\s*:\s*(left|right)(?![\w-])/i' => 'clear: both',
];

/**
 * Exact manifest-less stylesheet ownership and the renderer call that serves each file.
 *
 * @var array<string, array{renderer: string, entry: string, url: string}>
 * @since  2.0.0
 */
const RUNTIME_FALLBACKS = [
    'public/assets/administrator.css' => [
        'renderer' => 'src/Administrator/Presentation/AdministratorRenderer.php',
        'entry' => 'assets/administrator/main.ts',
        'url' => '/assets/administrator.css',
    ],
    'public/assets/portal.css' => [
        'renderer' => 'src/Portal/Presentation/PortalRenderer.php',
        'entry' => 'assets/portal/main.ts',
        'url' => '/assets/portal.css',
    ],
    'public/assets/site.css' => [
        'renderer' => 'src/Presentation/SiteRenderer.php',
        'entry' => 'assets/site/main.ts',
        'url' => '/assets/site.css',
    ],
];

/** @var list<string> Fields emitted by Vite 8's manifest chunk schema. */
const MANIFEST_FIELDS = [
    'file',
    'name',
    'names',
    'src',
    'isEntry',
    'isDynamicEntry',
    'imports',
    'dynamicImports',
    'css',
    'assets',
];

/** @var list<string> Frontend source suffixes inspected for CSS-as-string escape hatches. */
const FRONTEND_SCRIPT_EXTENSIONS = ['cjs', 'cts', 'js', 'jsx', 'mjs', 'mts', 'ts', 'tsx'];

/**
 * Require a normalized, repository-relative POSIX path.
 *
 * @throws RuntimeException  When the value is empty, absolute, platform-dependent, or traversing.
 */
function relativePath(string $value, string $kind): string
{
    if (
        $value === ''
        || str_contains($value, "\0")
        || str_contains($value, '\\')
        || str_starts_with($value, '/')
        || preg_match('/^[A-Za-z]:/', $value) === 1
    ) {
        throw new RuntimeException(sprintf('The %s path %s is not a normalized relative POSIX path.', $kind, $value));
    }
    foreach (explode('/', $value) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException(sprintf(
                'The %s path %s contains an empty or traversal segment.',
                $kind,
                $value,
            ));
        }
    }

    return $value;
}

/**
 * Resolve one owned regular file without following any symlink or leaving the repository.
 *
 * @return string  Absolute canonical filename.
 *
 * @throws RuntimeException  When the path is missing, non-regular, linked, or outside the root.
 */
function ownedFile(string $root, string $relative, string $kind): string
{
    $relative = relativePath($relative, $kind);
    $current = rtrim($root, DIRECTORY_SEPARATOR);
    foreach (explode('/', $relative) as $segment) {
        $current .= DIRECTORY_SEPARATOR . $segment;
        if (is_link($current)) {
            throw new RuntimeException(sprintf('The %s path %s contains a symlink.', $kind, $relative));
        }
    }
    if (!is_file($current)) {
        throw new RuntimeException(sprintf('The %s path %s is missing or is not a regular file.', $kind, $relative));
    }

    $canonicalRoot = realpath($root);
    $canonicalFile = realpath($current);
    if (
        !is_string($canonicalRoot)
        || !is_string($canonicalFile)
        || !str_starts_with($canonicalFile, $canonicalRoot . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException(sprintf('The %s path %s resolves outside the repository.', $kind, $relative));
    }

    return $canonicalFile;
}

/**
 * Read one owned file or fail with its ownership diagnostic.
 *
 * @throws RuntimeException  When the file is unsafe or unreadable.
 */
function ownedContents(string $root, string $relative, string $kind): string
{
    $contents = file_get_contents(ownedFile($root, $relative, $kind));
    if (!is_string($contents)) {
        throw new RuntimeException(sprintf('The %s path %s cannot be read.', $kind, $relative));
    }

    return $contents;
}

/**
 * Validate one list of unique non-empty strings in a manifest record.
 *
 * @param  array<string, mixed>  $record  Manifest chunk record.
 *
 * @return list<string>
 *
 * @throws RuntimeException  When the field is not a deterministic string list.
 */
function manifestStringList(array $record, string $field, string $key): array
{
    if (!array_key_exists($field, $record)) {
        return [];
    }
    $values = $record[$field];
    if (!is_array($values) || !array_is_list($values)) {
        throw new RuntimeException(sprintf('The Vite manifest field %s.%s must be a list.', $key, $field));
    }

    $seen = [];
    foreach ($values as $value) {
        if (!is_string($value) || $value === '' || isset($seen[$value])) {
            throw new RuntimeException(sprintf(
                'The Vite manifest field %s.%s must contain unique non-empty strings.',
                $key,
                $field,
            ));
        }
        $seen[$value] = true;
    }

    return array_keys($seen);
}

/**
 * Validate an emitted output and return its repository-relative path.
 *
 * @throws RuntimeException  When the output is unsafe, missing, or not regular.
 */
function manifestOutput(string $root, string $value, string $field): string
{
    relativePath($value, $field);
    $relative = 'public/assets/build/' . $value;
    ownedFile($root, $relative, $field);

    return $relative;
}

/**
 * Record a CSS output and enforce the build/css placement configured in vite.config.ts.
 *
 * @param  array<string, true>  $stylesheets  CSS output set populated in place.
 *
 * @throws RuntimeException  When a stylesheet has a non-canonical suffix or output directory.
 */
function recordCssOutput(string $relative, string $field, array &$stylesheets): void
{
    if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'css') {
        return;
    }
    if (!str_ends_with($relative, '.css') || !str_starts_with($relative, 'public/assets/build/css/')) {
        throw new RuntimeException(sprintf(
            'The CSS output %s named by %s must be a lowercase .css file below public/assets/build/css.',
            $relative,
            $field,
        ));
    }
    $stylesheets[$relative] = true;
}

/**
 * Enumerate every regular CSS file below the build root without following links.
 *
 * @param  array<string, true>  $stylesheets  CSS file set populated in place.
 *
 * @throws RuntimeException  When the tree contains a link, special file, or misplaced CSS output.
 */
function collectBuildCss(string $root, string $relative, array &$stylesheets): void
{
    $directory = $root . '/' . $relative;
    if (is_link($directory) || !is_dir($directory)) {
        throw new RuntimeException(sprintf(
            'The build directory %s is missing, linked, or not a directory.',
            $relative,
        ));
    }
    $entries = scandir($directory);
    if (!is_array($entries)) {
        throw new RuntimeException(sprintf('The build directory %s cannot be read.', $relative));
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $relative . '/' . $entry;
        $absolute = $root . '/' . $child;
        if (is_link($absolute)) {
            throw new RuntimeException(sprintf('The build output %s is a symlink.', $child));
        }
        if (is_dir($absolute)) {
            collectBuildCss($root, $child, $stylesheets);
            continue;
        }
        if (!is_file($absolute)) {
            throw new RuntimeException(sprintf('The build output %s is not a regular file.', $child));
        }
        if (strtolower(pathinfo($child, PATHINFO_EXTENSION)) !== 'css') {
            continue;
        }
        if (!str_ends_with($child, '.css') || !str_starts_with($child, 'public/assets/build/css/')) {
            throw new RuntimeException(sprintf(
                'The build CSS file %s must be a lowercase .css file below public/assets/build/css.',
                $child,
            ));
        }
        $stylesheets[$child] = true;
    }
}

/**
 * Add a manifest record's CSS-bearing `css`, `file` and `assets` outputs in fixed order.
 *
 * The explicit field order is independent of JSON object ordering. It also covers CSS entry chunks
 * and CSS assets Vite records outside `css`. First occurrence wins across the static import closure,
 * matching the production resolver.
 *
 * @param  array<string, mixed>  $record  Validated manifest record.
 * @param  array<string, true>   $seen    CSS paths already emitted into the closure.
 * @param  list<string>          $ordered CSS paths populated in deterministic order.
 */
function appendRecordCss(array $record, array &$seen, array &$ordered): void
{
    $values = $record['css'];
    if (str_ends_with($record['file'], '.css')) {
        $values[] = $record['file'];
    }
    foreach ($record['assets'] as $asset) {
        if (str_ends_with($asset, '.css')) {
            $values[] = $asset;
        }
    }
    foreach ($values as $value) {
        $relative = 'public/assets/build/' . $value;
        if (isset($seen[$relative])) {
            continue;
        }
        $seen[$relative] = true;
        $ordered[] = $relative;
    }
}

/**
 * Traverse entry CSS first, then recursive static imports in Vite's dependency-first post-order.
 *
 * Dynamic imports are intentionally excluded: their CSS belongs to a later module load and is not
 * render-blocking initial-document CSS. Imported chunks follow Vite's `importedChunks` algorithm:
 * dependencies precede their importer while direct-import sibling order remains stable.
 *
 * @param  array<string, array<string, mixed>>  $manifest  Validated manifest records.
 * @param  array<string, int>                   $state     DFS state, 1 active and 2 complete.
 * @param  array<string, true>                  $seenCss   CSS paths already added.
 * @param  list<string>                         $ordered   Site CSS paths populated in place.
 * @param  bool                                 $entry     Whether this is the leading site entry.
 *
 * @throws RuntimeException  When the recursive site graph contains a cycle.
 */
function visitSiteChunk(
    string $key,
    array $manifest,
    array &$state,
    array &$seenCss,
    array &$ordered,
    bool $entry = false,
): void {
    if (($state[$key] ?? 0) === 1) {
        throw new RuntimeException(sprintf('The Vite site manifest import closure contains a cycle at %s.', $key));
    }
    if (($state[$key] ?? 0) === 2) {
        return;
    }
    $state[$key] = 1;
    $record = $manifest[$key];
    if ($entry) {
        appendRecordCss($record, $seenCss, $ordered);
    }
    foreach ($record['imports'] as $dependency) {
        visitSiteChunk($dependency, $manifest, $state, $seenCss, $ordered);
    }
    if (!$entry) {
        appendRecordCss($record, $seenCss, $ordered);
    }
    $state[$key] = 2;
}

/**
 * Read and validate every Vite manifest record and derive emitted and site-closure CSS.
 *
 * @return array{all: list<string>, site: list<string>}
 *
 * @throws RuntimeException  When the manifest or emitted tree is incomplete, unsafe, or ambiguous.
 */
function stylesheetGraph(string $root): array
{
    $encoded = ownedContents(
        $root,
        'public/assets/build/.vite/manifest.json',
        'Vite manifest',
    );
    try {
        /** @var mixed $decoded */
        $decoded = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('The Vite manifest is invalid JSON.', 0, $exception);
    }
    if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
        throw new RuntimeException('The Vite manifest must contain a non-empty object.');
    }

    /** @var array<string, array<string, mixed>> $manifest */
    $manifest = [];
    $manifestCss = [];
    foreach ($decoded as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException('Every Vite manifest key must be a string.');
        }
        relativePath($key, 'Vite manifest key');
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw new RuntimeException(sprintf('The Vite manifest record %s must be a non-empty object.', $key));
        }
        foreach (array_keys($value) as $field) {
            if (!is_string($field) || !in_array($field, MANIFEST_FIELDS, true)) {
                throw new RuntimeException(sprintf(
                    'The Vite manifest record %s has unsupported field %s.',
                    $key,
                    $field,
                ));
            }
        }
        $file = $value['file'] ?? null;
        if (!is_string($file) || $file === '') {
            throw new RuntimeException(sprintf('The Vite manifest record %s has no output file.', $key));
        }
        $filePath = manifestOutput($root, $file, $key . '.file');
        recordCssOutput($filePath, $key . '.file', $manifestCss);

        $css = manifestStringList($value, 'css', $key);
        foreach ($css as $stylesheet) {
            if (!str_ends_with($stylesheet, '.css')) {
                throw new RuntimeException(sprintf(
                    'The Vite manifest field %s.css names non-CSS output %s.',
                    $key,
                    $stylesheet,
                ));
            }
            $stylesheetPath = manifestOutput($root, $stylesheet, $key . '.css');
            recordCssOutput($stylesheetPath, $key . '.css', $manifestCss);
        }
        $assets = manifestStringList($value, 'assets', $key);
        foreach ($assets as $asset) {
            $assetPath = manifestOutput($root, $asset, $key . '.assets');
            recordCssOutput($assetPath, $key . '.assets', $manifestCss);
        }

        $src = null;
        if (array_key_exists('src', $value)) {
            $src = $value['src'];
            if (!is_string($src) || $src === '') {
                throw new RuntimeException(sprintf('The Vite manifest field %s.src must be a source path.', $key));
            }
            relativePath($src, $key . '.src');
        }
        $name = $value['name'] ?? null;
        if ($name !== null && (!is_string($name) || $name === '')) {
            throw new RuntimeException(sprintf('The Vite manifest field %s.name must be a non-empty string.', $key));
        }
        manifestStringList($value, 'names', $key);
        foreach (['isEntry', 'isDynamicEntry'] as $booleanField) {
            if (array_key_exists($booleanField, $value) && !is_bool($value[$booleanField])) {
                throw new RuntimeException(sprintf(
                    'The Vite manifest field %s.%s must be boolean.',
                    $key,
                    $booleanField,
                ));
            }
        }

        $manifest[$key] = [
            ...$value,
            'file' => $file,
            'css' => $css,
            'assets' => $assets,
            'imports' => manifestStringList($value, 'imports', $key),
            'dynamicImports' => manifestStringList($value, 'dynamicImports', $key),
        ];
    }

    foreach ($manifest as $key => $record) {
        foreach (array_merge($record['imports'], $record['dynamicImports']) as $dependency) {
            if (!isset($manifest[$dependency])) {
                throw new RuntimeException(sprintf(
                    'The Vite manifest record %s references missing chunk %s.',
                    $key,
                    $dependency,
                ));
            }
        }
    }
    foreach (RUNTIME_FALLBACKS as $binding) {
        $entry = $manifest[$binding['entry']] ?? null;
        if (
            !is_array($entry)
            || ($entry['isEntry'] ?? false) !== true
            || ($entry['src'] ?? null) !== $binding['entry']
        ) {
            throw new RuntimeException(sprintf(
                'The Vite manifest must carry the owned entry %s with matching src and isEntry.',
                $binding['entry'],
            ));
        }
        ownedFile($root, $binding['entry'], $binding['entry'] . '.src');
    }

    $buildCss = [];
    collectBuildCss($root, 'public/assets/build', $buildCss);
    $declared = array_keys($manifestCss);
    $emitted = array_keys($buildCss);
    sort($declared, SORT_STRING);
    sort($emitted, SORT_STRING);
    $missing = array_values(array_diff($declared, $emitted));
    $orphan = array_values(array_diff($emitted, $declared));
    if ($missing !== [] || $orphan !== []) {
        throw new RuntimeException(sprintf(
            'The Vite manifest CSS set does not equal the build CSS set (missing: %s; orphan: %s).',
            $missing === [] ? 'none' : implode(', ', $missing),
            $orphan === [] ? 'none' : implode(', ', $orphan),
        ));
    }
    if ($declared === []) {
        throw new RuntimeException('The Vite manifest declares no emitted CSS.');
    }

    $state = [];
    $seenCss = [];
    $site = [];
    visitSiteChunk('assets/site/main.ts', $manifest, $state, $seenCss, $site, true);
    if ($site === []) {
        throw new RuntimeException('The recursive Vite site entry closure declares no CSS.');
    }

    return ['all' => $declared, 'site' => $site];
}

/**
 * Reproduce the site fallback bytes from ordered emitted CSS without normalizing source bytes.
 *
 * @param  list<string>  $stylesheets  Ordered site-closure CSS paths.
 *
 * @throws RuntimeException  When an owned stylesheet cannot be read.
 */
function siteFallbackBytes(string $root, array $stylesheets): string
{
    $chunks = [];
    foreach ($stylesheets as $stylesheet) {
        $chunks[] = ownedContents($root, $stylesheet, 'site manifest stylesheet');
    }

    return implode("\n", $chunks);
}

/**
 * Prove the configured renderer has the exact entry/fallback pair as executable PHP tokens.
 *
 * @param  array{renderer: string, entry: string, url: string}  $binding  Expected renderer binding.
 *
 * @throws RuntimeException  When the live call no longer names the registered fallback.
 */
function assertRendererBinding(string $root, array $binding): void
{
    $source = ownedContents($root, $binding['renderer'], 'runtime fallback renderer');
    $tokens = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = [$token[0], $token[1]];
            continue;
        }
        $tokens[] = [null, $token];
    }
    $expected = [
        [T_OBJECT_OPERATOR, '->'],
        [T_STRING, 'entry'],
        [null, '('],
        [T_CONSTANT_ENCAPSED_STRING, "'" . $binding['entry'] . "'"],
        [null, ','],
        [T_CONSTANT_ENCAPSED_STRING, "'" . $binding['url'] . "'"],
    ];
    $calls = 0;
    $matches = 0;
    for ($offset = 0; $offset <= count($tokens) - count($expected); $offset++) {
        if (
            $tokens[$offset] === [T_OBJECT_OPERATOR, '->']
            && ($tokens[$offset + 1] ?? null) === [T_STRING, 'entry']
            && ($tokens[$offset + 2] ?? null) === [null, '(']
        ) {
            $calls++;
        }
        if (array_slice($tokens, $offset, count($expected)) === $expected) {
            $matches++;
        }
    }
    if ($calls !== 1 || $matches !== 1) {
        throw new RuntimeException(sprintf(
            'The renderer %s must contain exactly one asset entry call, serving manifest entry %s '
                . 'with registered fallback %s.',
            $binding['renderer'],
            $binding['entry'],
            $binding['url'],
        ));
    }
}

/**
 * Canonicalize CSS for conservative contract inspection.
 *
 * CSS escapes can spell every contract-sensitive identifier another way, so this repository refuses
 * them instead of maintaining a partial decoder. Comments are removed outside quoted strings with
 * line endings preserved; removing rather than spacing them exposes identifiers split as
 * `mar/**/gin-left` without corrupting diagnostics.
 *
 * @return string  Escape-free CSS with comments removed and source line numbers preserved.
 *
 * @throws RuntimeException  When CSS contains an escape, unterminated string or comment.
 */
function cssInspectionSource(string $source, string $relative): string
{
    if (str_contains($source, '\\')) {
        throw new RuntimeException(sprintf(
            'The stylesheet %s contains a CSS escape that can hide a contract-sensitive identifier.',
            $relative,
        ));
    }

    $canonical = '';
    $quote = null;
    $length = strlen($source);
    for ($offset = 0; $offset < $length; $offset++) {
        $character = $source[$offset];
        if ($quote !== null) {
            $canonical .= $character;
            if ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === "'" || $character === '"') {
            $quote = $character;
            $canonical .= $character;
            continue;
        }
        if ($character !== '/' || ($source[$offset + 1] ?? '') !== '*') {
            $canonical .= $character;
            continue;
        }

        $end = strpos($source, '*/', $offset + 2);
        if ($end === false) {
            throw new RuntimeException(sprintf('The stylesheet %s contains an unterminated comment.', $relative));
        }
        $before = $source[$offset - 1] ?? '';
        $after = $source[$end + 2] ?? '';
        if (
            preg_match('/[A-Za-z0-9_-]/', $before) === 1
            && preg_match('/[A-Za-z0-9_-]/', $after) === 1
        ) {
            throw new RuntimeException(sprintf(
                'The stylesheet %s contains a comment that splits a CSS identifier.',
                $relative,
            ));
        }
        $comment = substr($source, $offset, $end + 2 - $offset);
        $lineEndings = preg_replace('/[^\r\n]/', '', $comment);
        if (!is_string($lineEndings)) {
            throw new RuntimeException(sprintf('The stylesheet %s comment cannot be inspected.', $relative));
        }
        $canonical .= $lineEndings;
        $offset = $end + 1;
    }
    if ($quote !== null) {
        throw new RuntimeException(sprintf('The stylesheet %s contains an unterminated string.', $relative));
    }

    return $canonical;
}

/**
 * Extract every static Lit `css` tagged-template body from one frontend module.
 *
 * Named aliases are followed deliberately, while namespace imports, interpolations, style arrays
 * and `unsafeCSS` fail closed. That leaves one auditable form: a direct static tagged template whose
 * complete CSS bytes can enter the same physical-axis scanner as emitted stylesheets.
 *
 * @return array<string, string>  Virtual stylesheet name to static CSS body.
 *
 * @throws RuntimeException  When Lit CSS is composed in a form the gate cannot inspect completely.
 */
function staticLitStylesheets(string $source, string $relative): array
{
    if (preg_match('/\bunsafeCSS\b/', $source) === 1) {
        throw new RuntimeException(sprintf('The owned frontend source %s uses Lit unsafeCSS.', $relative));
    }
    if (preg_match('/import\s*\*\s*as\s+[A-Za-z_$][\w$]*\s*from\s*([\'"])lit\1/is', $source) === 1) {
        throw new RuntimeException(sprintf(
            'The owned frontend source %s uses an opaque namespace import for Lit CSS.',
            $relative,
        ));
    }

    $aliases = [];
    $matchedImports = preg_match_all(
        '/import\s*\{([^}]*)\}\s*from\s*([\'"])lit\2\s*;?/is',
        $source,
        $imports,
        PREG_SET_ORDER,
    );
    if ($matchedImports === false) {
        throw new RuntimeException(sprintf('The Lit imports in %s cannot be inspected.', $relative));
    }
    foreach ($imports as $import) {
        foreach (explode(',', $import[1]) as $specifier) {
            if (preg_match('/^\s*css(?:\s+as\s+([A-Za-z_$][\w$]*))?\s*$/', $specifier, $alias) !== 1) {
                continue;
            }
            $localName = isset($alias[1]) && $alias[1] !== '' ? $alias[1] : 'css';
            $aliases[$localName] = true;
        }
    }

    $assignmentPattern = '/\bstatic\b(?:(?![;{}]).)*\bstyles\b(?:(?![;{}=]).)*=/s';
    $matchedAssignments = preg_match_all($assignmentPattern, $source, $assignments, PREG_OFFSET_CAPTURE);
    $matchedDeclarations = preg_match_all(
        '/\bstatic\b(?:(?![;{}]).)*\bstyles\b/s',
        $source,
        $styleDeclarations,
    );
    if ($matchedAssignments === false || $matchedDeclarations === false) {
        throw new RuntimeException(sprintf('The Lit style assignments in %s cannot be inspected.', $relative));
    }
    if ($matchedAssignments !== $matchedDeclarations) {
        throw new RuntimeException(sprintf(
            'The owned frontend source %s contains an unrecognized static Lit styles declaration.',
            $relative,
        ));
    }
    $aliasPattern = $aliases === []
        ? '(?!)'
        : '(?:' . implode('|', array_map(static fn (string $alias): string => preg_quote($alias, '/'), array_keys($aliases))) . ')';
    foreach ($assignments[0] as $assignment) {
        $after = substr($source, $assignment[1] + strlen($assignment[0]));
        if (preg_match('/^\s*' . $aliasPattern . '\s*`/', $after) !== 1) {
            throw new RuntimeException(sprintf(
                'The owned frontend source %s must express static Lit styles as exactly one direct css tagged template.',
                $relative,
            ));
        }
        $opening = strpos($after, '`');
        $closing = is_int($opening) ? strpos($after, '`', $opening + 1) : false;
        if (
            !is_int($opening)
            || !is_int($closing)
            || preg_match('/^\s*;/', substr($after, $closing + 1)) !== 1
        ) {
            throw new RuntimeException(sprintf(
                'The owned frontend source %s must express static Lit styles as exactly one direct css tagged template.',
                $relative,
            ));
        }
    }

    foreach (array_keys($aliases) as $alias) {
        if (preg_match('/\b(?:const|let|var)\s+[A-Za-z_$][\w$]*\s*=\s*' . preg_quote($alias, '/') . '\b/', $source) === 1) {
            throw new RuntimeException(sprintf(
                'The owned frontend source %s aliases the Lit css tag outside its import.',
                $relative,
            ));
        }
    }

    $stylesheets = [];
    foreach (array_keys($aliases) as $alias) {
        $matchedTags = preg_match_all(
            '/(?<![\w$])' . preg_quote($alias, '/') . '\s*`/',
            $source,
            $tags,
            PREG_OFFSET_CAPTURE,
        );
        if ($matchedTags === false) {
            throw new RuntimeException(sprintf('The Lit css templates in %s cannot be inspected.', $relative));
        }
        foreach ($tags[0] as $tag) {
            $opening = $tag[1] + strlen($tag[0]) - 1;
            $closing = strpos($source, '`', $opening + 1);
            if ($closing === false) {
                throw new RuntimeException(sprintf('The owned frontend source %s has an unterminated Lit css template.', $relative));
            }
            $css = substr($source, $opening + 1, $closing - $opening - 1);
            if (str_contains($css, '${')) {
                throw new RuntimeException(sprintf(
                    'The owned frontend source %s interpolates a Lit css template.',
                    $relative,
                ));
            }
            $virtual = $relative . '#lit-css-' . (count($stylesheets) + 1);
            $stylesheets[$virtual] = cssInspectionSource($css, $virtual);
        }
    }

    return $stylesheets;
}

/**
 * Refuse frontend source modes that turn CSS into an untracked runtime string or asset URL.
 *
 * Query modes, constructed stylesheets and CSS resolved through `new URL` never enter Vite's emitted
 * stylesheet set. Static Lit templates are the one inline form admitted and are returned for the
 * same direction scan as manifest CSS.
 *
 * @return array<string, string>  Virtual static Lit stylesheets to scan.
 *
 * @throws RuntimeException  When owned frontend source uses an opaque CSS consumption mode.
 */
function assertNoOpaqueCssModes(string $root): array
{
    $litStylesheets = [];
    $pending = ['assets'];
    while ($pending !== []) {
        $relative = array_pop($pending);
        if (!is_string($relative)) {
            continue;
        }
        $absolute = $root . '/' . $relative;
        if (is_link($absolute) || !is_dir($absolute)) {
            throw new RuntimeException(sprintf('The owned frontend directory %s is linked or missing.', $relative));
        }
        $entries = scandir($absolute);
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('The owned frontend directory %s cannot be read.', $relative));
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $relative . '/' . $entry;
            $path = $root . '/' . $child;
            if (is_link($path)) {
                throw new RuntimeException(sprintf('The owned frontend source %s is a symlink.', $child));
            }
            if (is_dir($path)) {
                $pending[] = $child;
                continue;
            }
            $extension = strtolower(pathinfo($child, PATHINFO_EXTENSION));
            if (!is_file($path) || !in_array($extension, FRONTEND_SCRIPT_EXTENSIONS, true)) {
                continue;
            }
            $source = ownedContents($root, $child, 'owned frontend source');
            $styleSuffix = '(?:css|less|sass|scss|styl|stylus)';
            if (preg_match(
                '/\.' . $styleSuffix . '(?=[?#\'"`])[^\'"`\r\n]*[?&](?:inline|raw|url)(?=[=&#\'"`]|$)/i',
                $source,
            ) === 1) {
                throw new RuntimeException(sprintf(
                    'The owned frontend source %s uses a CSS ?inline/?raw/?url opaque query mode.',
                    $child,
                ));
            }
            $gap = '(?:\s|/\*.*?\*/|//[^\r\n]*(?:\r?\n|$))*';
            if (preg_match(
                '/new' . $gap . 'URL' . $gap . '\(' . $gap
                    . '(?:([\'"])[^\'"]+\.' . $styleSuffix . '(?:[?#][^\'"]*)?\1|'
                    . '`[^`]*\.' . $styleSuffix . '(?:[?#][^`]*)?`)'
                    . $gap . ',' . $gap . 'import' . $gap . '\.' . $gap . 'meta' . $gap . '\.'
                    . $gap . 'url' . $gap . '\)/is',
                $source,
            ) === 1) {
                throw new RuntimeException(sprintf(
                    'The owned frontend source %s loads CSS through new URL(..., import.meta.url).',
                    $child,
                ));
            }
            if (
                preg_match('/\bCSSStyleSheet\b/', $source) === 1
                || preg_match('/\badoptedStyleSheets\b/', $source) === 1
            ) {
                throw new RuntimeException(sprintf(
                    'The owned frontend source %s constructs runtime CSS outside the emitted manifest.',
                    $child,
                ));
            }
            foreach (staticLitStylesheets($source, $child) as $virtual => $css) {
                $litStylesheets[$virtual] = $css;
            }
        }
        sort($pending, SORT_STRING);
    }

    return $litStylesheets;
}

/**
 * Refuse imports the emitted manifest cannot own and relative URLs the site fallback cannot relocate.
 *
 * @throws RuntimeException  When a stylesheet retains an import or site-relative URL.
 */
function assertClosedCss(string $source, string $relative, bool $relocated): void
{
    if (preg_match('/@import\b[^;]*(?:[\'"(]\s*)data\s*:/i', $source) === 1) {
        throw new RuntimeException(sprintf('The stylesheet %s contains a data: CSS @import.', $relative));
    }
    if (preg_match('/@import\b/i', $source) === 1) {
        throw new RuntimeException(sprintf(
            'The stylesheet %s retains an @import outside the emitted manifest graph.',
            $relative,
        ));
    }
    if (!$relocated) {
        return;
    }
    $matched = preg_match_all(
        '/url\(\s*(?:([\'"])(.*?)\1|([^)]*))\s*\)/is',
        $source,
        $urls,
        PREG_SET_ORDER,
    );
    if ($matched === false) {
        throw new RuntimeException(sprintf('The stylesheet %s cannot be checked for relative URLs.', $relative));
    }
    foreach ($urls as $url) {
        $quoted = $url[2] ?? '';
        $value = trim((string) ($quoted !== '' ? $quoted : ($url[3] ?? '')));
        if (
            $value === ''
            || (
                !str_starts_with($value, '/')
                && !str_starts_with($value, '#')
                && preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) !== 1
            )
        ) {
            throw new RuntimeException(sprintf(
                'The site manifest stylesheet %s contains relative url() value %s that the fallback cannot relocate.',
                $relative,
                $value,
            ));
        }
    }
}

/**
 * Split a CSS shorthand value at top-level whitespace.
 *
 * Parentheses and quoted strings stay in one component, so functions such as `clamp()` and `var()`
 * cannot manufacture a false fourth side. CSS escapes have already been refused.
 *
 * @return list<string>  Canonical top-level components.
 */
function cssTopLevelComponents(string $value): array
{
    $withoutImportance = preg_replace('/\s*!important\s*$/i', '', trim($value));
    $value = is_string($withoutImportance) ? $withoutImportance : trim($value);
    $components = [];
    $component = '';
    $quote = null;
    $depth = 0;
    $length = strlen($value);
    for ($offset = 0; $offset < $length; $offset++) {
        $character = $value[$offset];
        if ($quote !== null) {
            $component .= $character;
            if ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === "'" || $character === '"') {
            $quote = $character;
            $component .= $character;
            continue;
        }
        if ($character === '(' || $character === '[') {
            $depth++;
            $component .= $character;
            continue;
        }
        if ($character === ')' || $character === ']') {
            $depth--;
            $component .= $character;
            continue;
        }
        if ($depth === 0 && ctype_space($character)) {
            if ($component !== '') {
                $canonical = preg_replace('/\s+/', ' ', trim($component));
                $components[] = is_string($canonical) ? $canonical : trim($component);
                $component = '';
            }
            continue;
        }
        $component .= $character;
    }
    if ($component !== '') {
        $canonical = preg_replace('/\s+/', ' ', trim($component));
        $components[] = is_string($canonical) ? $canonical : trim($component);
    }

    return $components;
}

/**
 * Split an elliptical border-radius value at its one top-level slash.
 *
 * @return list<string>  Horizontal and optional vertical radius groups.
 */
function cssRadiusGroups(string $value): array
{
    $groups = [];
    $group = '';
    $quote = null;
    $depth = 0;
    $length = strlen($value);
    for ($offset = 0; $offset < $length; $offset++) {
        $character = $value[$offset];
        if ($quote !== null) {
            $group .= $character;
            if ($character === $quote) {
                $quote = null;
            }
            continue;
        }
        if ($character === "'" || $character === '"') {
            $quote = $character;
            $group .= $character;
            continue;
        }
        if ($character === '(' || $character === '[') {
            $depth++;
        } elseif ($character === ')' || $character === ']') {
            $depth--;
        }
        if ($character === '/' && $depth === 0) {
            $groups[] = trim($group);
            $group = '';
            continue;
        }
        $group .= $character;
    }
    $groups[] = trim($group);

    return $groups;
}

/**
 * Expand one-to-four physical corner values into top-left, top-right, bottom-right, bottom-left.
 *
 * @param  list<string>  $components  Border-radius components.
 *
 * @return list<string>|null  Four corners, or null when the declaration is not valid shorthand.
 */
function expandedCssCorners(array $components): ?array
{
    return match (count($components)) {
        1 => [$components[0], $components[0], $components[0], $components[0]],
        2 => [$components[0], $components[1], $components[0], $components[1]],
        3 => [$components[0], $components[1], $components[2], $components[1]],
        4 => [$components[0], $components[1], $components[2], $components[3]],
        default => null,
    };
}

/**
 * Find physical four-side shorthands whose inline sides differ.
 *
 * One-, two- and three-value forms apply the same value to both inline sides. A four-value form is
 * direction-neutral only when its second (right) and fourth (left) components are identical.
 *
 * @return list<array{file: string, line: int, expected: string, statement: string}>  Violations.
 */
function asymmetricShorthandViolations(string $source, string $relative): array
{
    $matched = preg_match_all(
        '/(?<![\w-])(margin|padding|inset|border-(?:width|style|color))\s*:\s*([^;{}]+)(?=;|})/i',
        $source,
        $declarations,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    if ($matched === false) {
        throw new RuntimeException(sprintf('The stylesheet %s shorthands cannot be inspected.', $relative));
    }

    $replacements = [
        'margin' => 'margin-inline-start and margin-inline-end',
        'padding' => 'padding-inline-start and padding-inline-end',
        'inset' => 'inset-inline-start and inset-inline-end',
        'border-width' => 'border-inline-start-width and border-inline-end-width',
        'border-style' => 'border-inline-start-style and border-inline-end-style',
        'border-color' => 'border-inline-start-color and border-inline-end-color',
    ];
    $violations = [];
    foreach ($declarations as $declaration) {
        $components = cssTopLevelComponents($declaration[2][0]);
        if (count($components) !== 4 || $components[1] === $components[3]) {
            continue;
        }
        $property = strtolower($declaration[1][0]);
        $statement = trim($declaration[0][0]);
        $violations[] = [
            'file' => $relative,
            'line' => substr_count(substr($source, 0, $declaration[0][1]), "\n") + 1,
            'expected' => $replacements[$property],
            'statement' => strlen($statement) > 110 ? substr($statement, 0, 107) . '...' : $statement,
        ];
    }

    $matchedRadii = preg_match_all(
        '/(?<![\w-])border-radius\s*:\s*([^;{}]+)(?=;|})/i',
        $source,
        $radii,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    if ($matchedRadii === false) {
        throw new RuntimeException(sprintf('The stylesheet %s border radii cannot be inspected.', $relative));
    }
    foreach ($radii as $radius) {
        $value = preg_replace('/\s*!important\s*$/i', '', trim($radius[1][0]));
        $groups = cssRadiusGroups(is_string($value) ? $value : trim($radius[1][0]));
        $asymmetric = count($groups) > 2 || in_array('', $groups, true);
        foreach ($groups as $group) {
            $corners = expandedCssCorners(cssTopLevelComponents($group));
            if ($corners === null || $corners[0] !== $corners[1] || $corners[2] !== $corners[3]) {
                $asymmetric = true;
                break;
            }
        }
        if (!$asymmetric) {
            continue;
        }
        $statement = trim($radius[0][0]);
        $violations[] = [
            'file' => $relative,
            'line' => substr_count(substr($source, 0, $radius[0][1]), "\n") + 1,
            'expected' => 'logical border-start/end-start/end-radius properties',
            'statement' => strlen($statement) > 110 ? substr($statement, 0, 107) . '...' : $statement,
        ];
    }

    return $violations;
}

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$asJson = $arguments === ['--json'];
if ($arguments !== [] && !$asJson) {
    fwrite(STDERR, "Usage: php tools/verify-stylesheet-direction.php [--json]\n");
    exit(64);
}

$registerPath = $root . '/tools/stylesheet-direction.json';
$encoded = file_get_contents($registerPath);
if (!is_string($encoded)) {
    fwrite(STDERR, sprintf("The direction register %s cannot be read.\n", $registerPath));
    exit(66);
}
/** @var mixed $register */
$register = json_decode($encoded, true);
if (
    !is_array($register)
    || array_keys($register) !== ['runtime_fallback_stylesheets', 'allowed_declarations']
    || !is_array($register['runtime_fallback_stylesheets'])
    || !array_is_list($register['runtime_fallback_stylesheets'])
    || !is_array($register['allowed_declarations'])
    || !array_is_list($register['allowed_declarations'])
    || $register['runtime_fallback_stylesheets'] !== array_keys(RUNTIME_FALLBACKS)
) {
    fwrite(
        STDERR,
        "The direction register must carry exactly the administrator, portal and site runtime fallbacks.\n",
    );
    exit(65);
}

$allowed = [];
foreach ($register['allowed_declarations'] as $entry) {
    if (
        !is_array($entry)
        || array_keys($entry) !== ['file', 'declaration', 'reason']
        || !is_string($entry['file'])
        || !is_string($entry['declaration'])
        || !is_string($entry['reason'])
        || $entry['file'] === ''
        || $entry['declaration'] === ''
        || strlen($entry['reason']) < 20
        || isset($allowed[$entry['file'] . '|' . $entry['declaration']])
    ) {
        fwrite(STDERR, "Every allowed physical declaration must uniquely name its file, declaration and reason.\n");
        exit(65);
    }
    $allowed[$entry['file'] . '|' . $entry['declaration']] = true;
}

try {
    foreach (RUNTIME_FALLBACKS as $relative => $binding) {
        ownedFile($root, $relative, 'runtime fallback stylesheet');
        assertRendererBinding($root, $binding);
    }
    $litStylesheets = assertNoOpaqueCssModes($root);
    $graph = stylesheetGraph($root);
    $fallback = ownedContents($root, 'public/assets/site.css', 'site runtime fallback stylesheet');
    if ($fallback !== siteFallbackBytes($root, $graph['site'])) {
        throw new RuntimeException(
            'The committed public/assets/site.css fallback is stale; run npm run build.',
        );
    }
    $stylesheets = array_values(array_unique([
        ...$graph['all'],
        ...array_keys(RUNTIME_FALLBACKS),
    ]));
    sort($stylesheets, SORT_STRING);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(66);
}

$siteStylesheets = array_fill_keys($graph['site'], true);
$violations = [];
$logical = 0;
try {
    $checkedSources = $litStylesheets;
    foreach ($stylesheets as $relative) {
        $checkedSources[$relative] = cssInspectionSource(
            ownedContents($root, $relative, 'checked stylesheet'),
            $relative,
        );
    }
    ksort($checkedSources, SORT_STRING);
    foreach ($checkedSources as $relative => $source) {
        assertClosedCss($source, $relative, isset($siteStylesheets[$relative]));
        $logical += (int) preg_match_all(
            '/(?<![\w-])(margin|padding|border|inset)-(inline|block)(-|\s*:)|'
                . 'text-align\s*:\s*(start|end)|(?<![\w-])border-(start|end)-(start|end)-radius\s*:/i',
            $source,
        );
        foreach (DIRECTION_BOUND as $pattern => $replacement) {
            $matched = preg_match_all($pattern, $source, $physical, PREG_OFFSET_CAPTURE);
            if ($matched === false) {
                throw new RuntimeException(sprintf('The stylesheet %s declarations cannot be inspected.', $relative));
            }
            foreach ($physical[0] as $match) {
                $lineStart = strrpos(substr($source, 0, $match[1]), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $lineEnd = strpos($source, "\n", $match[1]);
                $lineEnd = $lineEnd === false ? strlen($source) : $lineEnd;
                $statement = trim(substr($source, $lineStart, $lineEnd - $lineStart));
                if (isset($allowed[$relative . '|' . $statement])) {
                    continue;
                }
                $violations[] = [
                    'file' => $relative,
                    'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
                    'expected' => $replacement,
                    'statement' => strlen($statement) > 110 ? substr($statement, 0, 107) . '...' : $statement,
                ];
            }
        }
        foreach (asymmetricShorthandViolations($source, $relative) as $violation) {
            if (!isset($allowed[$relative . '|' . $violation['statement']])) {
                $violations[] = $violation;
            }
        }
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(66);
}

if ($asJson) {
    fwrite(STDOUT, json_encode([
        'stylesheets' => count($checkedSources),
        'logical_declarations' => $logical,
        'violations' => $violations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit($violations === [] ? 0 : 1);
}

foreach ($violations as $violation) {
    fwrite(STDERR, sprintf(
        "%s:%d: a physical inline-axis declaration pins this rule to one writing direction; use %s.\n  %s\n",
        $violation['file'],
        $violation['line'],
        $violation['expected'],
        $violation['statement'],
    ));
}
if ($violations !== []) {
    fwrite(STDERR, sprintf(
        "\n%d physical inline-axis declaration(s) found. Convert them to logical properties, or record "
            . "the exception in tools/stylesheet-direction.json with the reason it is correct.\n",
        count($violations),
    ));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "%d emitted, runtime fallback and static Lit stylesheet(s) are direction independent "
        . "(%d logical declarations).\n",
    count($checkedSources),
    $logical,
));
