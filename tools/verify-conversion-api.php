<?php

/**
 * Verify App's exact consumer profile against the installed Conversion package's public API manifest.
 *
 * The package owns the complete type-shape manifest. App records only the package coordinate, profile ID,
 * digest, and member count it consumes. This gate rebuilds the profile digest from the installed manifest's
 * full reflected shapes, checks canonical ordering and namespaces, and proves every member autoloads under
 * its canonical name. It never copies a class signature or accepts a historical App alias.
 *
 * Usage:
 *   php tools/verify-conversion-api.php [--manifest=PATH] [--consumer=PATH] [--autoload=PATH]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/vendor/kumwe/conversion/resources/public-api/v1.json';
$consumerPath = $root . '/docs/architecture/conversion-api-profile.json';
$autoloadPath = $root . '/vendor/autoload.php';
$errors = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifestPath = substr($argument, strlen('--manifest='));
        continue;
    }
    if (str_starts_with($argument, '--consumer=')) {
        $consumerPath = substr($argument, strlen('--consumer='));
        continue;
    }
    if (str_starts_with($argument, '--autoload=')) {
        $autoloadPath = substr($argument, strlen('--autoload='));
        continue;
    }

    $errors[] = sprintf(
        'Unknown argument %s. Usage: php tools/verify-conversion-api.php '
        . '[--manifest=PATH] [--consumer=PATH] [--autoload=PATH]',
        $argument,
    );
}

$consumer = conversionApiDocument($consumerPath, 'consumer record', true, $errors);
$manifest = conversionApiDocument($manifestPath, 'installed public API manifest', true, $errors);
if ($consumer === null || $manifest === null) {
    conversionApiFailure($errors);
}

$consumerKeys = array_keys($consumer);
$expectedConsumerKeys = ['package', 'profile', 'digest', 'type_count'];
if ($consumerKeys !== $expectedConsumerKeys) {
    $errors[] = 'The consumer record must contain only package, profile, digest, and type_count in that order.';
}

$package = $consumer['package'] ?? null;
$profileId = $consumer['profile'] ?? null;
$expectedDigest = $consumer['digest'] ?? null;
$expectedCount = $consumer['type_count'] ?? null;
if ($package !== 'kumwe/conversion') {
    $errors[] = 'The consumer record package must be the canonical kumwe/conversion coordinate.';
}
if ($profileId !== 'extension-provider-v1') {
    $errors[] = 'The consumer record must select extension-provider-v1.';
}
if (!is_string($expectedDigest) || preg_match('/^sha256:[a-f0-9]{64}$/D', $expectedDigest) !== 1) {
    $errors[] = 'The consumer record digest must be a canonical SHA-256 profile digest.';
}
if ($expectedCount !== 15) {
    $errors[] = 'The extension-provider-v1 consumer record must pin exactly 15 types.';
}

if (($manifest['schema'] ?? null) !== 1) {
    $errors[] = 'The installed Conversion public API manifest must use schema 1.';
}
if (($manifest['package'] ?? null) !== 'kumwe/conversion') {
    $errors[] = 'The installed Conversion public API manifest has a foreign package coordinate.';
}
if (($manifest['namespace'] ?? null) !== 'Kumwe\\Conversion\\') {
    $errors[] = 'The installed Conversion public API manifest has a non-canonical namespace.';
}

$profiles = $manifest['profiles'] ?? null;
$profile = is_array($profiles) && is_string($profileId) ? ($profiles[$profileId] ?? null) : null;
if (!is_array($profile) || array_is_list($profile)) {
    $errors[] = 'The installed Conversion manifest does not publish extension-provider-v1.';
    conversionApiFailure($errors);
}

$profileKeys = array_keys($profile);
if ($profileKeys !== ['roots', 'types', 'digest']) {
    $errors[] = 'The installed extension-provider-v1 profile must contain roots, types, and digest in order.';
}

$roots = $profile['roots'] ?? null;
$profileTypes = $profile['types'] ?? null;
$publishedDigest = $profile['digest'] ?? null;
$types = $manifest['types'] ?? null;
if (!is_array($roots) || !array_is_list($roots)) {
    $errors[] = 'The installed extension-provider-v1 roots must be an ordered list.';
    $roots = [];
}
if (!is_array($profileTypes) || !array_is_list($profileTypes)) {
    $errors[] = 'The installed extension-provider-v1 types must be an ordered list.';
    $profileTypes = [];
}
if (!is_array($types) || array_is_list($types)) {
    $errors[] = 'The installed Conversion public API manifest must contain a type-shape object.';
    $types = [];
}

conversionApiOrderedNames($roots, 'profile roots', 2, $errors);
conversionApiOrderedNames($profileTypes, 'profile types', 15, $errors);
foreach ($roots as $rootType) {
    if (is_string($rootType) && !in_array($rootType, $profileTypes, true)) {
        $errors[] = sprintf('Profile root %s is absent from the exact profile type closure.', $rootType);
    }
}

$canonicalTypes = [];
foreach ($profileTypes as $type) {
    if (!is_string($type)) {
        continue;
    }
    if (!str_starts_with($type, 'Kumwe\\Conversion\\')) {
        $errors[] = sprintf('Profile type %s is outside the canonical Conversion namespace.', $type);
        continue;
    }
    if (!array_key_exists($type, $types)) {
        $errors[] = sprintf('Profile type %s has no full shape in the installed manifest.', $type);
        continue;
    }
    $canonicalTypes[$type] = $types[$type];
}

foreach (array_keys($types) as $type) {
    if (!is_string($type) || !str_starts_with($type, 'Kumwe\\Conversion\\')) {
        $errors[] = sprintf('The installed manifest publishes a non-canonical type key: %s.', (string) $type);
    }
}

$projection = [
    'schema' => 1,
    'profile' => $profileId,
    'roots' => $roots,
    'types' => $canonicalTypes,
];
$projectionBytes = json_encode(
    $projection,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
);
$actualDigest = 'sha256:' . hash('sha256', $projectionBytes);
if (!is_string($publishedDigest) || !hash_equals($actualDigest, $publishedDigest)) {
    $errors[] = sprintf(
        'The installed extension-provider-v1 digest is not reproducible from its full canonical type shapes '
        . '(computed %s).',
        $actualDigest,
    );
}
if (is_string($expectedDigest) && !hash_equals($expectedDigest, $actualDigest)) {
    $errors[] = sprintf(
        'The installed extension-provider-v1 digest %s does not match App consumer evidence %s.',
        $actualDigest,
        $expectedDigest,
    );
}
if (is_int($expectedCount) && count($profileTypes) !== $expectedCount) {
    $errors[] = sprintf(
        'The installed extension-provider-v1 contains %d types; App consumer evidence requires %d.',
        count($profileTypes),
        $expectedCount,
    );
}

if (!is_file($autoloadPath)) {
    $errors[] = sprintf('The installed Composer autoloader is unavailable at %s.', $autoloadPath);
} else {
    require_once $autoloadPath;
    foreach ($profileTypes as $type) {
        if (!is_string($type) || !str_starts_with($type, 'Kumwe\\Conversion\\')) {
            continue;
        }
        if (!class_exists($type) && !interface_exists($type) && !enum_exists($type)) {
            $errors[] = sprintf('Profile type %s is not available through the installed autoloader.', $type);
            continue;
        }
        $reflected = new ReflectionClass($type);
        if ($reflected->getName() !== $type) {
            $errors[] = sprintf('Profile type %s resolves through a non-canonical alias.', $type);
        }
    }
}

if ($errors !== []) {
    conversionApiFailure($errors);
}

fwrite(
    STDOUT,
    sprintf(
        "Conversion consumer profile verified (%s, %d canonical types, %s).\n",
        $profileId,
        count($profileTypes),
        $actualDigest,
    ),
);

/**
 * Read and optionally canonical-format-check one JSON object.
 *
 * @param   string        $path       Document path.
 * @param   string        $label      Diagnostic label.
 * @param   bool          $canonical  Whether canonical pretty JSON bytes are required.
 * @param   list<string>  $errors     Accumulated failures.
 *
 * @return  array<string, mixed>|null  Decoded object, or null when unavailable or invalid.
 *
 * @since   2.0.0
 */
function conversionApiDocument(string $path, string $label, bool $canonical, array &$errors): ?array
{
    $bytes = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
    if (!is_string($bytes)) {
        $errors[] = sprintf('The %s is unavailable at %s.', $label, $path);
        return null;
    }

    try {
        /** @var mixed $decoded */
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = sprintf('The %s is invalid JSON: %s', $label, $exception->getMessage());
        return null;
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        $errors[] = sprintf('The %s must be a JSON object.', $label);
        return null;
    }

    if ($canonical) {
        $encoded = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
        if (!hash_equals($encoded, $bytes)) {
            $errors[] = sprintf('The %s is not canonical pretty JSON.', $label);
        }
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Verify a canonical, sorted, unique profile name list and its fixed cardinality.
 *
 * @param   mixed         $names     Candidate list.
 * @param   string        $label     Diagnostic label.
 * @param   int           $count     Required cardinality.
 * @param   list<string>  $errors    Accumulated failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function conversionApiOrderedNames(mixed $names, string $label, int $count, array &$errors): void
{
    if (!is_array($names) || !array_is_list($names)) {
        return;
    }
    foreach ($names as $name) {
        if (!is_string($name) || !str_starts_with($name, 'Kumwe\\Conversion\\')) {
            $errors[] = sprintf('Every %s entry must be a canonical Conversion type.', $label);
        }
    }
    if (count($names) !== $count) {
        $errors[] = sprintf('The installed extension-provider-v1 must contain exactly %d %s.', $count, $label);
    }
    if (count(array_unique($names, SORT_STRING)) !== count($names)) {
        $errors[] = sprintf('The installed extension-provider-v1 %s contain duplicates.', $label);
    }
    $sorted = $names;
    sort($sorted, SORT_STRING);
    if ($sorted !== $names) {
        $errors[] = sprintf('The installed extension-provider-v1 %s are not canonically ordered.', $label);
    }
}

/**
 * Print deterministic Conversion consumer failures and stop.
 *
 * @param   list<string>  $errors  Problems found in package evidence or App consumer evidence.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function conversionApiFailure(array $errors): never
{
    foreach ($errors as $error) {
        fwrite(STDERR, 'Conversion API consumer: ' . $error . PHP_EOL);
    }

    exit(1);
}
