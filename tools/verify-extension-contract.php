<?php

/**
 * Verify the installed Extension SDK's package-owned contract resources.
 *
 * The App does not copy, translate, or freeze the SDK's classifications and fixtures. Composer installs
 * one authoritative artifact and this gate verifies that artifact against the PIN shipped by the same
 * package. A contract change therefore arrives only through an immutable SDK release and the App's
 * reviewed Composer pin; there is no second App-owned public-API ledger to drift.
 *
 * Usage:
 *   php tools/verify-extension-contract.php [--resources=PATH]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$resources = $root . '/vendor/kumwe/extension-sdk/resources';
$errors = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--resources=')) {
        $resources = rtrim(substr($argument, strlen('--resources=')), '/');
        continue;
    }

    $errors[] = sprintf(
        'Unknown argument %s. Usage: php tools/verify-extension-contract.php [--resources=PATH]',
        $argument,
    );
}

$pinPath = $resources . '/PIN.json';
$pinBytes = is_file($pinPath) ? file_get_contents($pinPath) : false;
if (!is_string($pinBytes)) {
    $errors[] = sprintf('The Extension SDK resource pin is unavailable at %s.', $pinPath);
    extensionContractFailure($errors);
}

try {
    /** @var mixed $decoded */
    $decoded = json_decode($pinBytes, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    $errors[] = sprintf('The Extension SDK resource pin is invalid JSON: %s', $exception->getMessage());
    extensionContractFailure($errors);
}

if (!is_array($decoded) || array_is_list($decoded)) {
    $errors[] = 'The Extension SDK resource pin must be a JSON object.';
    extensionContractFailure($errors);
}
if (($decoded['format'] ?? null) !== 'kumwe-extension-sdk-resource-pin-v2') {
    $errors[] = 'The Extension SDK resource pin declares an unsupported format.';
}

$entries = $decoded['files'] ?? null;
if (!is_array($entries) || !array_is_list($entries)) {
    $errors[] = 'The Extension SDK resource pin has no ordered file inventory.';
    extensionContractFailure($errors);
}

$expected = [];
foreach ($entries as $entry) {
    if (!is_array($entry) || array_is_list($entry)) {
        $errors[] = 'An Extension SDK resource pin entry is not an object.';
        continue;
    }
    $file = $entry['file'] ?? null;
    $sha256 = $entry['sha256'] ?? null;
    if (
        !is_string($file)
        || preg_match('#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[A-Za-z0-9._/-]+$#D', $file) !== 1
        || $file === 'PIN.json'
    ) {
        $errors[] = 'An Extension SDK resource pin entry has an unsafe file path.';
        continue;
    }
    if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
        $errors[] = sprintf('Extension SDK resource %s has an invalid SHA-256 pin.', $file);
        continue;
    }
    if (isset($expected[$file])) {
        $errors[] = sprintf('Extension SDK resource %s is pinned more than once.', $file);
        continue;
    }
    $expected[$file] = $sha256;

    $path = $resources . '/' . $file;
    $actual = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
    if (!is_string($actual) || !hash_equals($sha256, $actual)) {
        $errors[] = sprintf('Extension SDK resource %s does not match its package pin.', $file);
    }
}

$actualFiles = [];
if (is_dir($resources)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resources, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($resources) + 1));
        if ($relative !== 'PIN.json') {
            $actualFiles[] = $relative;
        }
    }
}
sort($actualFiles, SORT_STRING);
$pinnedFiles = array_keys($expected);
sort($pinnedFiles, SORT_STRING);
if ($actualFiles !== $pinnedFiles) {
    $errors[] = 'The Extension SDK resource tree does not exactly match its package-owned pin inventory.';
}

foreach (['contract/classification.json', 'contract/generations.json'] as $contract) {
    $bytes = file_get_contents($resources . '/' . $contract);
    if (is_string($bytes) && str_contains($bytes, 'Kumwe\\\\App\\\\')) {
        $errors[] = sprintf('%s still publishes historical App-owned extension types.', $contract);
    }
}

if ($errors !== []) {
    extensionContractFailure($errors);
}

fwrite(STDOUT, "Extension SDK package-owned contract resources verified.\n");

/**
 * Print deterministic failures and stop the gate.
 *
 * @param  list<string>  $errors  Problems found while verifying the installed SDK artifact.
 *
 * @return never
 *
 * @since  2.0.0
 */
function extensionContractFailure(array $errors): never
{
    foreach ($errors as $error) {
        fwrite(STDERR, 'Extension contract: ' . $error . PHP_EOL);
    }

    exit(1);
}
