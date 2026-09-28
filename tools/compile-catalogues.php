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

use Kumwe\App\Localization\Infrastructure\MessageCatalogueCompiler;
use Kumwe\App\Localization\Infrastructure\XliffCatalogueReader;
use Kumwe\Localization\Application\SupportedLocales;

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

// Version 2 ships nine catalogues, and each translated catalogue must carry every identifier the source
// declares with a translated target: a missing unit or an untranslated target would silently fall back
// to English on one surface, which is exactly the per-locale completeness Gate B criterion 11 forbids.
$reader = new XliffCatalogueReader();
$sourceUnits = $reader->readFile($sourceRoot . '/' . SupportedLocales::SOURCE . '.xlf')->units;
$sourceIdentifiers = array_keys($sourceUnits);
$completeness = [];
foreach ((new SupportedLocales())->tags() as $tag) {
    $path = $sourceRoot . '/' . $tag . '.xlf';
    if (!is_file($path)) {
        $completeness[] = sprintf('%s has no authored catalogue', $tag);
        continue;
    }
    if ($tag === SupportedLocales::SOURCE) {
        continue;
    }
    $catalogue = $reader->readFile($path);
    if ($catalogue->targetLanguage !== $tag) {
        $completeness[] = sprintf('%s declares trgLang %s', $tag, $catalogue->targetLanguage ?? '(none)');
    }
    $identifiers = array_keys($catalogue->units);
    $missing = array_diff($sourceIdentifiers, $identifiers);
    $extra = array_diff($identifiers, $sourceIdentifiers);
    $untranslated = [];
    foreach ($catalogue->units as $identifier => $unit) {
        if ($unit['target'] === null || trim($unit['target']) === '') {
            $untranslated[] = $identifier;
        }
    }
    if ($missing !== []) {
        $completeness[] = sprintf(
            '%s is missing %d identifier(s), first %s',
            $tag,
            count($missing),
            reset($missing),
        );
    }
    if ($extra !== []) {
        $completeness[] = sprintf(
            '%s carries %d identifier(s) the source does not, first %s',
            $tag,
            count($extra),
            reset($extra),
        );
    }
    if ($untranslated !== []) {
        $completeness[] = sprintf(
            '%s leaves %d unit(s) without a target, first %s',
            $tag,
            count($untranslated),
            reset($untranslated),
        );
    }
}
if ($completeness !== []) {
    fwrite(STDERR, sprintf(
        "The Version 2 language set is incomplete:\n  - %s\n",
        implode("\n  - ", $completeness),
    ));
    exit(1);
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
