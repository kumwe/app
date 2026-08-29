<?php

/**
 * Prove that author-facing packages and examples depend only on extracted public libraries.
 *
 * An extension that imports `Kumwe\App\` is coupled to the host's private implementation even when the
 * imported class happens to exist today. The SDK contract ledgers, every retained generation source, the
 * scaffold templates, and the installable examples must therefore be clean at the same time. There is no
 * alias, remapping, or historical-fixture exception: a retained generation is regenerated and re-signed
 * against canonical package namespaces before it can ship.
 *
 * Usage:
 *   php tools/verify-author-package-independence.php [--examples=PATH] [--sdk-resources=PATH]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$examplesRoot = $root . '/examples/extensions';
$sdkResources = $root . '/vendor/kumwe/extension-sdk/resources';
$errors = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--examples=')) {
        $examplesRoot = rtrim(substr($argument, strlen('--examples=')), '/');
        continue;
    }
    if (str_starts_with($argument, '--sdk-resources=')) {
        $sdkResources = rtrim(substr($argument, strlen('--sdk-resources=')), '/');
        continue;
    }

    $errors[] = sprintf(
        'Unknown argument %s. Usage: php tools/verify-author-package-independence.php '
        . '[--examples=PATH] [--sdk-resources=PATH]',
        $argument,
    );
}

$files = [];
collectAuthorFiles($examplesRoot, 'examples/extensions', true, $files, $errors);
collectAuthorFiles(
    $sdkResources . '/contract/classification.json',
    'sdk-resources/contract/classification.json',
    false,
    $files,
    $errors,
);
collectAuthorFiles(
    $sdkResources . '/contract/generations.json',
    'sdk-resources/contract/generations.json',
    false,
    $files,
    $errors,
);
collectAuthorFiles(
    $sdkResources . '/fixtures/generations',
    'sdk-resources/fixtures/generations',
    true,
    $files,
    $errors,
);
collectAuthorFiles(
    $sdkResources . '/extension-scaffold',
    'sdk-resources/extension-scaffold',
    true,
    $files,
    $errors,
);

ksort($files, SORT_STRING);
foreach ($files as $display => $path) {
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        $errors[] = sprintf('%s could not be read.', $display);
        continue;
    }

    $lines = preg_split('/\R/', $bytes) ?: [];
    foreach ($lines as $offset => $line) {
        if (!containsAppNamespace($line)) {
            continue;
        }
        $errors[] = sprintf(
            '%s:%d imports or publishes the private Kumwe\\App\\ namespace.',
            $display,
            $offset + 1,
        );
    }

    foreach (appComposerDependencyLines($display, $bytes, $lines) as $line) {
        $errors[] = sprintf(
            '%s:%d declares the forbidden kumwe/app Composer dependency; author packages must depend '
            . 'on extracted libraries.',
            $display,
            $line,
        );
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'Author-package independence: ' . $error . PHP_EOL);
    }
    exit(1);
}

fwrite(
    STDOUT,
    sprintf(
        "Author-package independence verified (%d files; no Kumwe\\App\\ imports).\n",
        count($files),
    ),
);

/**
 * Add one required file or every file under one required directory to the scan set.
 *
 * Symlinks are refused so an apparently clean package tree cannot redirect the gate around the artifact
 * Composer actually installs. Display paths remain stable when a test supplies isolated source roots.
 *
 * @param   string                 $path       Required file or directory.
 * @param   string                 $display    Stable category path shown in failures.
 * @param   bool                   $directory  Whether the required path is a directory.
 * @param   array<string, string>  $files      Files to scan, keyed by display path.
 * @param   list<string>           $errors     Accumulated deterministic failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function collectAuthorFiles(
    string $path,
    string $display,
    bool $directory,
    array &$files,
    array &$errors,
): void {
    if (!$directory) {
        if (!is_file($path) || is_link($path)) {
            $errors[] = sprintf('%s is missing or is not a regular package file.', $display);
            return;
        }
        $files[$display] = $path;

        return;
    }

    if (!is_dir($path) || is_link($path)) {
        $errors[] = sprintf('%s is missing or is not a regular package directory.', $display);
        return;
    }

    /** @var iterable<string, SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );
    $found = false;
    foreach ($entries as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($path) + 1));
        if ($entry->isLink()) {
            $errors[] = sprintf('%s/%s is a symlink rather than a regular package file.', $display, $relative);
            continue;
        }
        $files[$display . '/' . $relative] = $entry->getPathname();
        $found = true;
    }

    if (!$found) {
        $errors[] = sprintf('%s contains no package files to verify.', $display);
    }
}

/**
 * Find a private App namespace in PHP source or in an escaped JSON string.
 *
 * Repeated backslashes are collapsed first, so the same rule catches `Kumwe\App\Type` in PHP and
 * `Kumwe\\App\\Type` in serialized contract evidence without maintaining two compatibility patterns.
 *
 * @param   string  $line  One source or evidence line.
 *
 * @return  bool  True when the line names a private App namespace.
 *
 * @since   2.0.0
 */
function containsAppNamespace(string $line): bool
{
    do {
        $before = $line;
        $line = str_replace('\\\\', '\\', $line);
    } while ($line !== $before);

    return str_contains($line, 'Kumwe\\App\\');
}

/**
 * Find host-package declarations in one author-facing Composer manifest or template.
 *
 * Valid JSON is inspected only in Composer's package-link sections, so prose in a description is not a
 * dependency. A template that is not yet valid JSON receives a deliberately narrow textual fallback for
 * quoted package keys and Composer link-section declarations. Package names are case-insensitive.
 *
 * @param   string        $display  Stable artifact path.
 * @param   string        $bytes    Complete manifest or template bytes.
 * @param   list<string>  $lines    Manifest bytes split into source lines.
 *
 * @return  list<int>  One-based source lines containing forbidden dependency declarations.
 *
 * @since   2.0.0
 */
function appComposerDependencyLines(string $display, string $bytes, array $lines): array
{
    if (preg_match('#(?:^|/)composer\.json(?:\.tpl)?$#iD', $display) !== 1) {
        return [];
    }

    /** @var mixed $document */
    $document = json_decode($bytes, true);
    if (is_array($document) && !array_is_list($document)) {
        $matches = [];
        foreach (['require', 'require-dev', 'conflict', 'provide', 'replace'] as $section) {
            $dependencies = $document[$section] ?? null;
            if (!is_array($dependencies) || array_is_list($dependencies)) {
                continue;
            }
            foreach ($dependencies as $package => $_constraint) {
                if (is_string($package) && strcasecmp($package, 'kumwe/app') === 0) {
                    $matches[] = composerPackageLine($lines, $package);
                }
            }
        }

        $matches = array_values(array_unique($matches));
        sort($matches, SORT_NUMERIC);

        return $matches;
    }

    $matches = [];
    foreach ($lines as $offset => $line) {
        if (
            preg_match('/["\']kumwe\/app["\']\s*:/i', $line) === 1
            || preg_match(
                '/^\s*(?:require(?:-dev)?|conflict|provide|replace)\s*[:=]?\s+kumwe\/app(?:\s|[:=]|$)/i',
                $line,
            ) === 1
        ) {
            $matches[] = $offset + 1;
        }
    }

    return $matches;
}

/**
 * Locate a decoded Composer package key in the original manifest bytes.
 *
 * @param   list<string>  $lines    Source lines.
 * @param   string        $package  Package spelling as decoded from JSON.
 *
 * @return  int  One-based source line, or line one for a minified/otherwise unusual document.
 *
 * @since   2.0.0
 */
function composerPackageLine(array $lines, string $package): int
{
    foreach ($lines as $offset => $line) {
        if (stripos($line, $package) !== false) {
            return $offset + 1;
        }
    }

    return 1;
}
