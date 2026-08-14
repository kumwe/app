#!/usr/bin/env php
<?php

/**
 * Deterministically compile or verify the checked-in interface message catalogues.
 *
 * XLIFF under `resources/localization/messages/` is what a translator and a translation platform
 * read; the PHP under `resources/localization/compiled/` is what the request path reads. This script
 * is the only thing that turns one into the other, and `--check` is what proves the two have not
 * drifted, in the same shape `tools/compile-openapi.php` proves it for the API contract.
 *
 * Usage:
 *   php tools/compile-catalogues.php [--check]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\CMS\Localization\Infrastructure\MessageCatalogueCompiler;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer dependencies are required to compile message catalogues.\n");
    exit(78);
}
require $autoload;

$arguments = array_slice($argv, 1);
$check = $arguments === ['--check'];
if ($arguments !== [] && !$check) {
    fwrite(STDERR, "Usage: php tools/compile-catalogues.php [--check]\n");
    exit(64);
}

$sourceRoot = $root . '/resources/localization/messages';
$compiledRoot = $root . '/resources/localization/compiled';
$sources = glob($sourceRoot . '/*.xlf');
if ($sources === false || $sources === []) {
    fwrite(STDERR, sprintf("No XLIFF catalogue was found under %s.\n", $sourceRoot));
    exit(66);
}
sort($sources, SORT_STRING);

if (!is_dir($compiledRoot) && !mkdir($compiledRoot, 0o775, true) && !is_dir($compiledRoot)) {
    fwrite(STDERR, sprintf("The compiled catalogue directory %s cannot be created.\n", $compiledRoot));
    exit(73);
}

$compiler = new MessageCatalogueCompiler();
$stale = [];
$written = [];
$messages = 0;

foreach ($sources as $source) {
    $locale = basename($source, '.xlf');
    if (preg_match('/^[A-Za-z0-9-]{2,35}$/D', $locale) !== 1) {
        fwrite(STDERR, sprintf("The catalogue file name %s is not a canonical locale tag.\n", basename($source)));
        exit(65);
    }

    try {
        $expected = $compiler->compileFile($source, $locale);
    } catch (Throwable $exception) {
        fwrite(STDERR, sprintf("Catalogue compilation failed: %s\n", $exception->getMessage()));
        exit(65);
    }
    $messages += substr_count($expected, "\n    '");

    $target = $compiledRoot . '/' . $locale . '.php';
    $current = is_file($target) ? file_get_contents($target) : null;
    if (is_string($current) && hash_equals($expected, $current)) {
        continue;
    }
    if ($check) {
        $stale[] = $locale;
        continue;
    }

    $temporary = tempnam($compiledRoot, '.kumwe-catalogue-');
    if (!is_string($temporary)) {
        fwrite(STDERR, "The catalogue temporary file cannot be created.\n");
        exit(73);
    }
    try {
        if (file_put_contents($temporary, $expected, LOCK_EX) !== strlen($expected) || !rename($temporary, $target)) {
            fwrite(STDERR, "The compiled catalogue cannot be published atomically.\n");
            exit(73);
        }
        chmod($target, 0o644);
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
    $written[] = $locale;
}

$orphans = [];
$compiled = glob($compiledRoot . '/*.php');
foreach ($compiled === false ? [] : $compiled as $file) {
    if (!is_file($sourceRoot . '/' . basename($file, '.php') . '.xlf')) {
        $orphans[] = basename($file);
    }
}
if ($orphans !== []) {
    fwrite(STDERR, sprintf(
        "The compiled catalogue(s) %s have no XLIFF source; delete them or restore the source.\n",
        implode(', ', $orphans),
    ));
    exit(1);
}

if ($check) {
    if ($stale !== []) {
        fwrite(STDERR, sprintf(
            "The compiled catalogue(s) %s are stale; run composer translation:compile.\n",
            implode(', ', $stale),
        ));
        exit(1);
    }
    fwrite(STDOUT, sprintf(
        "The %d compiled message catalogue(s) are current (%d messages).\n",
        count($sources),
        $messages,
    ));
    exit(0);
}

fwrite(STDOUT, sprintf(
    "Compiled %d catalogue(s) carrying %d messages; %d rewritten.\n",
    count($sources),
    $messages,
    count($written),
));
