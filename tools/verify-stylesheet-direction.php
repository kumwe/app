#!/usr/bin/env php
<?php

/**
 * Fail the build when a stylesheet pins a layout to one writing direction.
 *
 * Two of the languages this platform states support for are written from the right, and their layout
 * work is one piece of work rather than a second set of stylesheets: every inline-axis rule is
 * written with logical properties, so the browser derives start and end from the `dir` attribute the
 * layouts emit. A single physical declaration is enough to break that, and it breaks it silently —
 * the page still renders, it is just wrong for half the languages — which is exactly the kind of
 * regression that needs a gate rather than a review.
 *
 * A physical declaration that is genuinely correct earns an entry in
 * `tools/stylesheet-direction.json` naming the reason.
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
 * @var array<string, string>
 * @since  2.0.0
 */
const DIRECTION_BOUND = [
    '/(?<![\w-])margin-left\s*:/' => 'margin-inline-start',
    '/(?<![\w-])margin-right\s*:/' => 'margin-inline-end',
    '/(?<![\w-])padding-left\s*:/' => 'padding-inline-start',
    '/(?<![\w-])padding-right\s*:/' => 'padding-inline-end',
    '/(?<![\w-])border-left(-\w+)?\s*:/' => 'border-inline-start',
    '/(?<![\w-])border-right(-\w+)?\s*:/' => 'border-inline-end',
    '/(?<![\w-])border-(top|bottom)-(left|right)-radius\s*:/' => 'border-start-start-radius and its siblings',
    '/(?<![\w-])left\s*:/' => 'inset-inline-start',
    '/(?<![\w-])right\s*:/' => 'inset-inline-end',
    '/text-align\s*:\s*left(?![\w-])/' => 'text-align: start',
    '/text-align\s*:\s*right(?![\w-])/' => 'text-align: end',
    '/float\s*:\s*(left|right)(?![\w-])/' => 'a flex or grid placement',
    '/clear\s*:\s*(left|right)(?![\w-])/' => 'clear: both',
];

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
if (!is_array($register) || !isset($register['allowed_declarations']) || !is_array($register['allowed_declarations'])) {
    fwrite(STDERR, "The direction register must carry an allowed_declarations list.\n");
    exit(65);
}
$allowed = [];
foreach ($register['allowed_declarations'] as $entry) {
    if (
        !is_array($entry)
        || !isset($entry['file'], $entry['declaration'], $entry['reason'])
        || !is_string($entry['file'])
        || !is_string($entry['declaration'])
        || $entry['reason'] === ''
    ) {
        fwrite(STDERR, "Every allowed physical declaration must name its file and the reason it is correct.\n");
        exit(65);
    }
    $allowed[$entry['file'] . '|' . $entry['declaration']] = true;
}

$stylesheets = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $root . '/assets',
    FilesystemIterator::SKIP_DOTS,
));
foreach ($iterator as $file) {
    if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'css') {
        $stylesheets[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
sort($stylesheets, SORT_STRING);
if ($stylesheets === []) {
    fwrite(STDERR, "No stylesheet was found to check.\n");
    exit(66);
}

$violations = [];
$logical = 0;
foreach ($stylesheets as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        fwrite(STDERR, sprintf("The stylesheet %s cannot be read.\n", $relative));
        exit(66);
    }
    $logical += (int) preg_match_all(
        '/(?<![\w-])(margin|padding|border|inset)-(inline|block)(-|\s*:)|text-align\s*:\s*(start|end)|'
            . '(?<![\w-])border-(start|end)-(start|end)-radius\s*:/',
        $source,
    );
    foreach (explode("\n", $source) as $index => $line) {
        $statement = trim($line);
        if ($statement === '' || str_starts_with($statement, '/*') || str_starts_with($statement, '*')) {
            continue;
        }
        foreach (DIRECTION_BOUND as $pattern => $replacement) {
            if (preg_match($pattern, $line) !== 1) {
                continue;
            }
            if (isset($allowed[$relative . '|' . $statement])) {
                continue;
            }
            $violations[] = [
                'file' => $relative,
                'line' => $index + 1,
                'expected' => $replacement,
                'statement' => strlen($statement) > 110 ? substr($statement, 0, 107) . '...' : $statement,
            ];
        }
    }
}

if ($asJson) {
    fwrite(STDOUT, json_encode([
        'stylesheets' => count($stylesheets),
        'logical_declarations' => $logical,
        'violations' => $violations,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit($violations === [] ? 0 : 1);
}

foreach ($violations as $violation) {
    fwrite(STDERR, sprintf(
        "%s:%d: %s pins this rule to one writing direction; use %s.\n  %s\n",
        $violation['file'],
        $violation['line'],
        'a physical inline-axis declaration',
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
    "%d stylesheet(s) are direction independent (%d logical declarations).\n",
    count($stylesheets),
    $logical,
));
