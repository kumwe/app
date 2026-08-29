<?php

/**
 * Reject runtime class aliases across App and every installed first-party package boundary.
 *
 * Canonical package namespaces are the only identities. App's tracked runtime, resources, tests, and examples
 * are scanned from Git's index so an ignored or platform-restored compatibility ghost cannot become authority.
 * Installed Conversion, Extension SDK, and Producer source/resources are scanned from their Composer trees.
 * PHP is tokenized to ignore prose and string fixtures; non-PHP resources receive the equivalent text check.
 *
 * Usage:
 *   php tools/verify-package-boundaries.php [--root=PATH ...]
 *
 * Supplying any --root replaces the repository defaults and exists for isolated negative fixtures.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$overrideRoots = [];
$errors = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $overrideRoots[] = rtrim(substr($argument, strlen('--root=')), '/');
        continue;
    }

    $errors[] = sprintf(
        'Unknown argument %s. Usage: php tools/verify-package-boundaries.php [--root=PATH ...]',
        $argument,
    );
}

$files = [];
if ($overrideRoots !== []) {
    foreach ($overrideRoots as $overrideRoot) {
        packageBoundaryCollectTree($overrideRoot, basename($overrideRoot), $files, $errors);
    }
} else {
    packageBoundaryCollectTrackedApp($root, $files, $errors);
    foreach (['conversion', 'extension-sdk', 'producer'] as $package) {
        $packageRoot = $root . '/vendor/kumwe/' . $package;
        if (!is_dir($packageRoot) || is_link($packageRoot)) {
            $errors[] = sprintf('Installed first-party package kumwe/%s is unavailable.', $package);
            continue;
        }
        foreach (['src', 'resources'] as $subtree) {
            $path = $packageRoot . '/' . $subtree;
            if (is_dir($path)) {
                packageBoundaryCollectTree(
                    $path,
                    'vendor/kumwe/' . $package . '/' . $subtree,
                    $files,
                    $errors,
                );
            }
        }
    }
}

ksort($files, SORT_STRING);
foreach ($files as $display => $path) {
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        $errors[] = sprintf('%s could not be read.', $display);
        continue;
    }

    $lines = packageBoundaryAliasLines($path, $bytes);
    foreach ($lines as $line) {
        $errors[] = sprintf(
            '%s:%d calls class_alias(); canonical package identities cannot be remapped.',
            $display,
            $line,
        );
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'Package boundary: ' . $error . PHP_EOL);
    }
    exit(1);
}

fwrite(
    STDOUT,
    sprintf("Package boundaries verified (%d tracked/source/resource files; no class aliases).\n", count($files)),
);

/**
 * Collect tracked App files from the Git index, excluding untracked compatibility ghosts by construction.
 *
 * @param   string                 $root    Repository root.
 * @param   array<string, string>  $files   Files to scan, keyed by repository-relative path.
 * @param   list<string>           $errors  Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function packageBoundaryCollectTrackedApp(string $root, array &$files, array &$errors): void
{
    $command = sprintf('git -C %s ls-files -- src resources tests examples', escapeshellarg($root));
    $tracked = [];
    $status = 0;
    exec($command . ' 2>&1', $tracked, $status);
    if ($status !== 0) {
        $errors[] = 'Tracked App boundaries could not be read from the Git index.';
        return;
    }

    foreach ($tracked as $relative) {
        if (!is_string($relative) || $relative === '') {
            continue;
        }
        $path = $root . '/' . $relative;
        if (!file_exists($path) && !is_link($path)) {
            // A worktree deletion has no runtime bytes to remap; its next commit also removes it from ls-files.
            continue;
        }
        if (!is_file($path) || is_link($path)) {
            $errors[] = sprintf('Tracked App boundary file %s is missing or is not regular.', $relative);
            continue;
        }
        $files[$relative] = $path;
    }
}

/**
 * Collect every regular file from one installed package or isolated fixture subtree.
 *
 * @param   string                 $root     Subtree root.
 * @param   string                 $display  Stable diagnostic prefix.
 * @param   array<string, string>  $files    Files to scan, keyed by display path.
 * @param   list<string>           $errors   Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function packageBoundaryCollectTree(string $root, string $display, array &$files, array &$errors): void
{
    if (!is_dir($root) || is_link($root)) {
        $errors[] = sprintf('%s is missing or is not a regular source/resource directory.', $display);
        return;
    }

    /** @var iterable<string, SplFileInfo> $entries */
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($entries as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        if ($entry->isLink()) {
            $errors[] = sprintf('%s/%s is a symlink rather than a regular package file.', $display, $relative);
            continue;
        }
        $files[$display . '/' . $relative] = $entry->getPathname();
    }
}

/**
 * Locate executable class_alias calls while ignoring PHP comments and string fixtures.
 *
 * @param   string  $path   Source/resource path.
 * @param   string  $bytes  Complete file bytes.
 *
 * @return  list<int>  One-based source lines containing calls.
 *
 * @since   2.0.0
 */
function packageBoundaryAliasLines(string $path, string $bytes): array
{
    $lower = strtolower($path);
    if (!str_ends_with($lower, '.php') && !str_ends_with($lower, '.php.tpl')) {
        $matches = [];
        foreach (preg_split('/\R/', $bytes) ?: [] as $offset => $line) {
            if (preg_match('/\bclass_alias\s*\(/i', $line) === 1) {
                $matches[] = $offset + 1;
            }
        }

        return $matches;
    }

    $tokens = token_get_all($bytes);
    $matches = [];
    $count = count($tokens);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }
        $name = strtolower(ltrim($token[1], '\\'));
        if ($name !== 'class_alias' && !str_ends_with($name, '\\class_alias')) {
            continue;
        }
        for ($cursor = $index + 1; $cursor < $count; $cursor++) {
            $next = $tokens[$cursor];
            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($next === '(') {
                $matches[] = $token[2];
            }
            break;
        }
    }

    return array_values(array_unique($matches));
}
