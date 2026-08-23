<?php

/**
 * Prove the vendored Studio contract corpus is exactly the pinned release.
 *
 * Decision D16 makes Kumwe App's composition declarations the published Studio schemas, and
 * kumwe/app#104 requires the exact `@kumwe/studio-protocol` schema set and the complete
 * `@kumwe/studio-testkit` corpus to be vendored at one released version and digest-verified before
 * any conformance run. This tool is that verification: it recomputes every SRI digest the two
 * published manifests declare against the vendored bytes under `tests/Fixtures/Studio/`, holds each
 * directory closed in both directions (a listed file must exist, an unlisted file must not), and
 * checks the vendored package versions against the pin record. A Studio contract fix reaches this
 * repository only as a deliberate re-pin: new tarballs, new digests, and a new `PIN.json` in one
 * change — never as a silent edit to vendored bytes.
 *
 * Usage:
 *
 *   php tools/verify-studio-corpus.php
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/tests/Fixtures/Studio';
$errors = [];

/**
 * Compute the SRI sha256 digest of one file.
 *
 * @param   string  $path  File whose bytes are digested.
 *
 * @return  string  Digest in the manifests' `sha256-<base64>` form.
 *
 * @since   2.0.0
 */
function sriDigest(string $path): string
{
    return 'sha256-' . base64_encode(hash_file('sha256', $path, true) ?: '');
}

/**
 * Decode one JSON document or record why it could not be read.
 *
 * @param   string        $path    Document to decode.
 * @param   list<string>  $errors  Accumulated violations.
 *
 * @return  array<string, mixed>|null  Decoded document, or null when unreadable.
 *
 * @since   2.0.0
 */
function decode(string $path, array &$errors): ?array
{
    if (!is_file($path)) {
        $errors[] = sprintf('Missing manifest: %s', $path);

        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        $errors[] = sprintf('Unreadable manifest: %s', $path);

        return null;
    }

    return $decoded;
}

$pin = decode($root . '/PIN.json', $errors);
$protocolManifest = decode($root . '/protocol/schemas/manifest.json', $errors);
$corpusManifest = decode($root . '/testkit/corpus-manifest.json', $errors);

if ($pin !== null) {
    foreach ([
        '@kumwe/studio-protocol' => $root . '/protocol/package.json',
        '@kumwe/studio-testkit' => $root . '/testkit/package.json',
    ] as $package => $packageFile) {
        $declared = $pin['pinned'][$package]['version'] ?? null;
        $vendored = decode($packageFile, $errors)['version'] ?? null;
        if (!is_string($declared) || $declared !== $vendored) {
            $errors[] = sprintf(
                'Pin mismatch for %s: PIN.json declares %s, the vendored package.json says %s.',
                $package,
                var_export($declared, true),
                var_export($vendored, true),
            );
        }
    }
}

$schemaCount = 0;
if ($protocolManifest !== null) {
    $schemaDirectory = $root . '/protocol/schemas';
    $listed = ['manifest.json' => true];
    foreach ((array) ($protocolManifest['schemas'] ?? []) as $schema) {
        $file = is_array($schema) ? ($schema['file'] ?? null) : null;
        $digest = is_array($schema) ? ($schema['digest'] ?? null) : null;
        if (!is_string($file) || !is_string($digest)) {
            $errors[] = 'A protocol manifest entry lacks its file or digest.';
            continue;
        }
        $listed[$file] = true;
        $path = $schemaDirectory . '/' . $file;
        if (!is_file($path)) {
            $errors[] = sprintf('Protocol schema %s is listed but not vendored.', $file);
            continue;
        }
        if (sriDigest($path) !== $digest) {
            $errors[] = sprintf('Protocol schema %s does not match its published digest.', $file);
            continue;
        }
        $schemaCount++;
    }
    foreach (scandir($schemaDirectory) ?: [] as $entry) {
        if ($entry[0] !== '.' && !isset($listed[$entry])) {
            $errors[] = sprintf('Protocol schema directory holds unlisted file %s.', $entry);
        }
    }
}

$corpusCount = 0;
$groupCount = 0;
if ($corpusManifest !== null) {
    foreach ((array) ($corpusManifest['groups'] ?? []) as $group) {
        $name = is_array($group) ? ($group['group'] ?? null) : null;
        $path = is_array($group) ? ($group['path'] ?? null) : null;
        if (!is_string($name) || !is_string($path)) {
            $errors[] = 'A corpus manifest group lacks its name or path.';
            continue;
        }
        $groupCount++;
        $directory = $root . '/testkit/' . $path;
        $listed = [];
        foreach ((array) ($group['files'] ?? []) as $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;
            $digest = is_array($entry) ? ($entry['digest'] ?? null) : null;
            if (!is_string($file) || !is_string($digest)) {
                $errors[] = sprintf('Group %s lists an entry without file or digest.', $name);
                continue;
            }
            $listed[$file] = true;
            $filePath = $directory . '/' . $file;
            if (!is_file($filePath)) {
                $errors[] = sprintf('Corpus file %s/%s is listed but not vendored.', $path, $file);
                continue;
            }
            if (sriDigest($filePath) !== $digest) {
                $errors[] = sprintf('Corpus file %s/%s does not match its published digest.', $path, $file);
                continue;
            }
            $corpusCount++;
        }
        if (is_dir($directory)) {
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry[0] !== '.' && is_file($directory . '/' . $entry) && !isset($listed[$entry])) {
                    $errors[] = sprintf('Corpus directory %s holds unlisted file %s.', $path, $entry);
                }
            }
        } else {
            $errors[] = sprintf('Corpus directory %s is missing.', $path);
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "The vendored Studio corpus does not match its pinned release:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}

printf(
    "Kumwe studio corpus verified: 2 pinned packages, %d protocol schemas, %d corpus files in %d groups.\n",
    $schemaCount,
    $corpusCount,
    $groupCount,
);
