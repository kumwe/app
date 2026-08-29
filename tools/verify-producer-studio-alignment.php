<?php

/**
 * Prove that App and its installed Producer implement one coordinated Studio release.
 *
 * Exact package versions are necessary but not sufficient when Producer vendors Studio's protocol and
 * corpus: an exact Producer release can itself be pinned to a different Studio release. This verifier
 * compares the two independently published evidence chains. Producer must name a coordinated release,
 * carry the same release-record bytes and hash, and repeat the protocol version, corpus digest, package
 * family, and claimed profiles that App verifies. No remapping or compatibility interpretation occurs;
 * disagreement is a hard failure that must be corrected and released in Producer.
 *
 * Usage:
 *
 *   php tools/verify-producer-studio-alignment.php [--app-pin=PATH] [--app-release=PATH]
 *       [--producer-pin=PATH] [--producer-release=PATH]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

/**
 * Compare App and Producer Studio evidence.
 *
 * @param   array{app_pin: string, app_release: string, producer_pin: string,
 *          producer_release: string}  $paths  Evidence paths.
 *
 * @return  int  Zero only when every coordinate and byte agrees.
 *
 * @since   2.0.0
 */
function verifyProducerStudioAlignment(array $paths): int
{
    $errors = [];
    $appPinEvidence = producerAlignmentDocument($paths['app_pin'], 'App Studio PIN', $errors);
    $appReleaseEvidence = producerAlignmentDocument($paths['app_release'], 'App Studio release', $errors);
    $producerPinEvidence = producerAlignmentDocument($paths['producer_pin'], 'Producer Studio PIN', $errors);
    $producerReleaseEvidence = producerAlignmentDocument(
        $paths['producer_release'],
        'Producer Studio release',
        $errors,
    );

    if (
        $appPinEvidence === null
        || $appReleaseEvidence === null
        || $producerPinEvidence === null
        || $producerReleaseEvidence === null
    ) {
        return reportProducerAlignmentFailure($errors);
    }

    $appPin = $appPinEvidence['document'];
    $appRelease = $appReleaseEvidence['document'];
    $producerPin = $producerPinEvidence['document'];
    $producerRelease = $producerReleaseEvidence['document'];

    $appRecord = producerAlignmentObject($appPin['release_record'] ?? null, 'App release_record', $errors);
    $producerRecord = producerAlignmentObject(
        $producerPin['release_record'] ?? null,
        'Producer release_record',
        $errors,
    );
    $appPackages = producerAlignmentPackageMap($appRelease['packages'] ?? null, 'App release packages', $errors);
    $producerPackages = producerAlignmentPackageMap(
        $producerPin['packages'] ?? null,
        'Producer PIN packages',
        $errors,
    );
    $appPinnedPackages = producerAlignmentPinnedPackageMap($appPin['pinned'] ?? null, $errors);

    $appReleaseHash = hash('sha256', $appReleaseEvidence['bytes']);
    $producerReleaseHash = hash('sha256', $producerReleaseEvidence['bytes']);
    $appReleaseName = producerAlignmentString($appRelease['release'] ?? null);
    $protocolVersion = producerAlignmentString($appRelease['protocolVersion'] ?? null);
    $corpusDigest = producerAlignmentString($appRelease['corpusManifestDigest'] ?? null);
    $claimedProfiles = producerAlignmentStringList(
        $appRelease['claimedProfiles'] ?? null,
        'App release claimedProfiles',
        $errors,
    );

    if ($appRecord !== null) {
        producerAlignmentSame($appRecord['release'] ?? null, $appReleaseName, 'App PIN release coordinate', $errors);
        producerAlignmentSame($appRecord['file'] ?? null, 'studio-release.json', 'App release filename', $errors);
        producerAlignmentSame($appRecord['sha256'] ?? null, $appReleaseHash, 'App release SHA-256', $errors);
    }
    producerAlignmentSame($appPinnedPackages, $appPackages, 'App PIN package family', $errors);

    $source = producerAlignmentObject($producerPin['source'] ?? null, 'Producer PIN source', $errors);
    if ($source !== null) {
        producerAlignmentSame(
            $source['repository'] ?? null,
            'https://github.com/kumwe/studio',
            'Producer Studio repository',
            $errors,
        );
        producerAlignmentSame($source['kind'] ?? null, 'coordinated-release', 'Producer pin kind', $errors);
        producerAlignmentSame($source['release'] ?? null, $appReleaseName, 'Producer source release', $errors);
        if (array_key_exists('commit', $source)) {
            $errors[] = 'Producer PIN source must not retain a git-prerelease commit beside a coordinated release.';
        }
    }

    if ($appRecord !== null && $producerRecord !== null) {
        producerAlignmentSame($producerRecord, $appRecord, 'Producer release_record', $errors);
    }
    producerAlignmentSame(
        $producerPin['protocol_version'] ?? null,
        $protocolVersion,
        'Producer protocol version',
        $errors,
    );
    producerAlignmentSame(
        $producerPin['corpus_manifest_digest'] ?? null,
        $corpusDigest,
        'Producer corpus manifest digest',
        $errors,
    );
    producerAlignmentSame(
        producerAlignmentStringList(
            $producerPin['claimed_profiles'] ?? null,
            'Producer PIN claimed_profiles',
            $errors,
        ),
        $claimedProfiles,
        'Producer claimed profiles',
        $errors,
    );
    producerAlignmentSame($producerPackages, $appPackages, 'Producer package family', $errors);
    producerAlignmentSame(
        $producerReleaseEvidence['bytes'],
        $appReleaseEvidence['bytes'],
        'Producer Studio release bytes',
        $errors,
    );
    producerAlignmentSame($producerReleaseHash, $appReleaseHash, 'Producer Studio release SHA-256', $errors);
    producerAlignmentSame($producerRelease, $appRelease, 'Producer Studio release document', $errors);
    verifyProducerReleaseInventory($producerPin['files'] ?? null, $producerReleaseHash, $errors);

    if ($errors !== []) {
        return reportProducerAlignmentFailure($errors);
    }

    fwrite(
        STDOUT,
        sprintf(
            "Producer/Studio alignment verified: release %s, protocol %s, %d package(s), %d profile(s).\n",
            $appReleaseName,
            $protocolVersion,
            count($appPackages ?? []),
            count($claimedProfiles ?? []),
        ),
    );

    return 0;
}

/**
 * Read one JSON evidence document without normalizing its bytes.
 *
 * @param   string        $path    Evidence path.
 * @param   string        $label   Diagnostic label.
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  array{document: array<string, mixed>, bytes: string}|null  Evidence, or null after failure.
 *
 * @since   2.0.0
 */
function producerAlignmentDocument(string $path, string $label, array &$errors): ?array
{
    if (!is_file($path)) {
        $errors[] = sprintf('%s is missing: %s', $label, $path);

        return null;
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        $errors[] = sprintf('%s cannot be read: %s', $label, $path);

        return null;
    }
    /** @var mixed $decoded */
    $decoded = json_decode($bytes, true);
    if (!is_array($decoded)) {
        $errors[] = sprintf('%s is not well-formed JSON: %s', $label, json_last_error_msg());

        return null;
    }

    /** @var array<string, mixed> $decoded */
    return ['document' => $decoded, 'bytes' => $bytes];
}

/**
 * Require one decoded value to be an object-shaped array.
 *
 * @param   mixed         $value   Decoded value.
 * @param   string        $label   Diagnostic label.
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  array<string, mixed>|null  Object, or null after failure.
 *
 * @since   2.0.0
 */
function producerAlignmentObject(mixed $value, string $label, array &$errors): ?array
{
    if (!is_array($value) || array_is_list($value)) {
        $errors[] = sprintf('%s must be an object.', $label);

        return null;
    }

    return $value;
}

/**
 * Read a package-name-to-version object in deterministic key order.
 *
 * @param   mixed         $value   Decoded package map.
 * @param   string        $label   Diagnostic label.
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  array<string, string>|null  Sorted package map, or null after failure.
 *
 * @since   2.0.0
 */
function producerAlignmentPackageMap(mixed $value, string $label, array &$errors): ?array
{
    if (!is_array($value) || array_is_list($value) || $value === []) {
        $errors[] = sprintf('%s must be a non-empty object.', $label);

        return null;
    }
    $packages = [];
    foreach ($value as $name => $version) {
        if (!is_string($name) || !is_string($version) || $name === '' || $version === '') {
            $errors[] = sprintf('%s must map non-empty package names to versions.', $label);

            return null;
        }
        $packages[$name] = $version;
    }
    ksort($packages, SORT_STRING);

    return $packages;
}

/**
 * Reduce App PIN's richer package records to the release document's package map.
 *
 * @param   mixed         $value   App PIN `pinned` object.
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  array<string, string>|null  Sorted package map, or null after failure.
 *
 * @since   2.0.0
 */
function producerAlignmentPinnedPackageMap(mixed $value, array &$errors): ?array
{
    if (!is_array($value) || array_is_list($value) || $value === []) {
        $errors[] = 'App PIN pinned must be a non-empty object.';

        return null;
    }
    $packages = [];
    foreach ($value as $name => $record) {
        if (!is_string($name) || !is_array($record) || !is_string($record['version'] ?? null)) {
            $errors[] = 'Every App PIN package must carry a string version.';

            return null;
        }
        $packages[$name] = $record['version'];
    }
    ksort($packages, SORT_STRING);

    return $packages;
}

/**
 * Read a closed string list, rejecting duplicates that could make a set comparison ambiguous.
 *
 * @param   mixed         $value   Decoded list.
 * @param   string        $label   Diagnostic label.
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  list<string>|null  String list, or null after failure.
 *
 * @since   2.0.0
 */
function producerAlignmentStringList(mixed $value, string $label, array &$errors): ?array
{
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = sprintf('%s must be an array.', $label);

        return null;
    }
    $strings = [];
    foreach ($value as $entry) {
        if (!is_string($entry) || $entry === '') {
            $errors[] = sprintf('%s must contain only non-empty strings.', $label);

            return null;
        }
        if (in_array($entry, $strings, true)) {
            $errors[] = sprintf('%s repeats %s.', $label, $entry);

            return null;
        }
        $strings[] = $entry;
    }

    return $strings;
}

/**
 * Return a string value or a stable diagnostic sentinel for later equality reporting.
 *
 * @param   mixed  $value  Decoded value.
 *
 * @return  string  String value, or an empty string for a malformed coordinate.
 *
 * @since   2.0.0
 */
function producerAlignmentString(mixed $value): string
{
    return is_string($value) ? $value : '';
}

/**
 * Require Producer's digest inventory to close over its coordinated release-record bytes.
 *
 * @param   mixed         $value          Producer PIN files list.
 * @param   string        $expectedHash   SHA-256 of Producer's release record.
 * @param   list<string>  $errors         Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function verifyProducerReleaseInventory(mixed $value, string $expectedHash, array &$errors): void
{
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = 'Producer PIN files must be an array.';

        return;
    }
    $matches = [];
    foreach ($value as $entry) {
        if (is_array($entry) && ($entry['file'] ?? null) === 'studio-release.json') {
            $matches[] = $entry['sha256'] ?? null;
        }
    }
    producerAlignmentSame($matches, [$expectedHash], 'Producer release digest inventory', $errors);
}

/**
 * Record a strict evidence mismatch without coercing either side.
 *
 * @param   mixed         $actual    Actual evidence.
 * @param   mixed         $expected  Required evidence.
 * @param   string        $label     Diagnostic label.
 * @param   list<string>  $errors    Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function producerAlignmentSame(mixed $actual, mixed $expected, string $label, array &$errors): void
{
    if ($actual === $expected) {
        return;
    }
    $errors[] = sprintf(
        '%s differs: expected %s, got %s.',
        $label,
        producerAlignmentPrintable($expected),
        producerAlignmentPrintable($actual),
    );
}

/**
 * Render evidence compactly without leaking binary or multi-line content into diagnostics.
 *
 * @param   mixed  $value  Evidence value.
 *
 * @return  string  Printable value or digest for long bytes.
 *
 * @since   2.0.0
 */
function producerAlignmentPrintable(mixed $value): string
{
    if (is_string($value) && strlen($value) > 160) {
        return sprintf('string(%d bytes, sha256:%s)', strlen($value), hash('sha256', $value));
    }
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : get_debug_type($value);
}

/**
 * Print all alignment failures and return the gate's non-zero status.
 *
 * @param   list<string>  $errors  Accumulated failures.
 *
 * @return  int  Always one.
 *
 * @since   2.0.0
 */
function reportProducerAlignmentFailure(array $errors): int
{
    fwrite(STDERR, "Producer/Studio alignment verification failed:\n");
    foreach (array_values(array_unique($errors)) as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }

    return 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $paths = [
        'app_pin' => $root . '/resources/studio-contract/PIN.json',
        'app_release' => $root . '/resources/studio-contract/studio-release.json',
        'producer_pin' => $root . '/vendor/kumwe/producer/resources/studio-contract/PIN.json',
        'producer_release' => $root . '/vendor/kumwe/producer/resources/studio-contract/studio-release.json',
    ];
    $arguments = [
        'app-pin' => 'app_pin',
        'app-release' => 'app_release',
        'producer-pin' => 'producer_pin',
        'producer-release' => 'producer_release',
    ];
    foreach (array_slice($argv, 1) as $argument) {
        $matched = false;
        foreach ($arguments as $name => $key) {
            $prefix = '--' . $name . '=';
            if (!str_starts_with($argument, $prefix)) {
                continue;
            }
            $paths[$key] = substr($argument, strlen($prefix));
            $matched = true;
            break;
        }
        if (!$matched) {
            fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
            exit(2);
        }
    }

    exit(verifyProducerStudioAlignment($paths));
}
