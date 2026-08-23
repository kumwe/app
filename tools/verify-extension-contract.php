<?php

/**
 * Verify that the frozen extension contract still says what the repository does.
 *
 * Two documents carry the contract: `docs/extension-contract/classification.json` says which types an
 * extension may build against, and `docs/extension-contract/generations.json` says what each supported
 * manifest and contribution-SPI generation promises. This check holds them to the tree — every
 * classified type resolves to a file that declares it, every compatibility package is present and
 * unchanged, every pinned fixture actually pins the type that cites it, and the host-service allowlist
 * matches the one the composition root hands a package.
 *
 * It also freezes each generation. Every generation entry carries a `surface_digest` over its own
 * canonical bytes, so widening what a generation promises fails here until the digest is rewritten,
 * which is the deliberate act a generation change is supposed to be. Adding a generation beside the
 * frozen ones is the ordinary way forward and needs no digest of anyone else's entry.
 *
 * The check is dependency-free so it runs before `composer install` and inside minimal images.
 *
 * Usage:
 *   php tools/verify-extension-contract.php [--generations=PATH] [--classification=PATH]
 *
 * The two overrides exist so a test can prove the check fails in the right direction against a
 * deliberately broken document, without committing that document.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Extension\Domain\Internal\ExtensionManifestGrammar;

require_once dirname(__DIR__) . '/src/Extension/Domain/Internal/ExtensionManifestGrammar.php';

$root = dirname(__DIR__);
$errors = [];
$generationsPath = $root . '/docs/extension-contract/generations.json';
$classificationPath = $root . '/docs/extension-contract/classification.json';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--generations=')) {
        $generationsPath = substr($argument, strlen('--generations='));
        continue;
    }
    if (str_starts_with($argument, '--classification=')) {
        $classificationPath = substr($argument, strlen('--classification='));
        continue;
    }

    $errors[] = sprintf(
        'Unknown argument %s. Usage: php tools/verify-extension-contract.php '
        . '[--generations=PATH] [--classification=PATH]',
        $argument,
    );
}

$generations = readContractDocument($generationsPath, $errors);
$classification = readContractDocument($classificationPath, $errors);

if ($errors !== []) {
    reportContractFailure($errors);
}

if (($generations['format'] ?? null) !== 'kumwe-extension-contract-generations-v1') {
    $errors[] = 'generations.json does not declare the kumwe-extension-contract-generations-v1 format.';
}
if (($classification['format'] ?? null) !== 'kumwe-extension-contract-classification-v1') {
    $errors[] = 'classification.json does not declare the kumwe-extension-contract-classification-v1 format.';
}
if ($errors !== []) {
    reportContractFailure($errors);
}

$surfaces = [];
foreach (contractList($generations['contribution_surfaces'] ?? null, 'contribution_surfaces', $errors) as $surface) {
    if (!is_string($surface) || $surface === '') {
        $errors[] = 'Every contribution surface must be a non-empty dotted key.';
        continue;
    }
    $surfaces[$surface] = true;
}

$manifestGenerations = [];
$fixturePackages = [];
foreach (contractList($generations['manifest_generations'] ?? null, 'manifest_generations', $errors) as $entry) {
    if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
        $errors[] = 'Every manifest generation must be an object with an identifier.';
        continue;
    }
    $id = (string) $entry['id'];
    if (isset($manifestGenerations[$id])) {
        $errors[] = sprintf('Manifest generation %s is declared more than once.', $id);
    }
    $manifestGenerations[$id] = $entry;
    assertFrozenDigest($id, $entry, $errors);

    $schema = $entry['schema'] ?? null;
    if (!is_int($schema) || $schema < 1) {
        $errors[] = sprintf('Manifest generation %s has no positive schema number.', $id);
    }
    if (($entry['status'] ?? null) !== 'frozen') {
        $errors[] = sprintf('Manifest generation %s is listed but not frozen; withdraw it instead.', $id);
    }
    foreach (contractList($entry['manifest_keys'] ?? null, $id . '.manifest_keys', $errors) as $key) {
        if (
            !is_array($key)
            || !is_string($key['key'] ?? null)
            || !is_string($key['status'] ?? null)
            || !isset(($generations['key_statuses'] ?? [])[$key['status']])
        ) {
            $errors[] = sprintf('Manifest generation %s declares a key without a known status.', $id);
        }
    }
    assertManifestGrammarParity($id, $entry, $errors);

    $fixture = $entry['fixture'] ?? null;
    if (!is_array($fixture)) {
        $errors[] = sprintf('Manifest generation %s has no compatibility fixture.', $id);
        continue;
    }
    $package = $fixture['package'] ?? null;
    if (!is_string($package) || !is_dir($root . '/' . $package)) {
        $errors[] = sprintf('Manifest generation %s names a compatibility package that is not a directory.', $id);
        continue;
    }
    $fixturePackages[$id] = $package;
    $manifestFile = $root . '/' . $package . '/kumwe.json';
    $manifestBytes = is_file($manifestFile) ? file_get_contents($manifestFile) : false;
    if (!is_string($manifestBytes)) {
        $errors[] = sprintf('Compatibility package %s has no readable kumwe.json.', $package);
        continue;
    }
    if (($fixture['manifest_sha256'] ?? null) !== hash('sha256', $manifestBytes)) {
        $errors[] = sprintf(
            'Compatibility package %s no longer matches its recorded manifest digest. Its generation promises '
            . 'a fixed manifest shape: change the digest only when the change is a deliberate one.',
            $package,
        );
    }
    /** @var mixed $decoded */
    $decoded = json_decode($manifestBytes, true);
    if (!is_array($decoded) || ($decoded['schema'] ?? null) !== $schema) {
        $errors[] = sprintf('Compatibility package %s does not declare schema %s.', $package, var_export($schema, true));
    }
    if (is_array($decoded) && ($decoded['name'] ?? null) !== ($fixture['identifier'] ?? null)) {
        $errors[] = sprintf('Compatibility package %s does not carry its recorded identifier.', $package);
    }
    $lifecycle = $fixture['lifecycle'] ?? null;
    if ($lifecycle !== ['install', 'activate', 'upgrade', 'disable', 'reactivate', 'uninstall']) {
        $errors[] = sprintf(
            'Compatibility package %s must declare the complete lifecycle: install, activate, upgrade, '
            . 'disable, reactivate, uninstall.',
            $package,
        );
    }
    $contributions = $fixture['contributions'] ?? null;
    if (!is_array($contributions)) {
        $errors[] = sprintf('Compatibility package %s declares no expected contribution inventory.', $package);
        continue;
    }
    foreach ($contributions as $surface => $count) {
        if (!is_string($surface) || !isset($surfaces[$surface])) {
            $errors[] = sprintf('Compatibility package %s expects unknown contribution surface %s.', $package, (string) $surface);
        }
        if (!is_int($count) || $count < 1) {
            $errors[] = sprintf('Compatibility package %s expects a non-positive count on %s.', $package, (string) $surface);
        }
    }
}

$spiGenerations = [];
foreach (contractList($generations['spi_generations'] ?? null, 'spi_generations', $errors) as $entry) {
    if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
        $errors[] = 'Every SPI generation must be an object with an identifier.';
        continue;
    }
    $id = (string) $entry['id'];
    if (isset($spiGenerations[$id])) {
        $errors[] = sprintf('SPI generation %s is declared more than once.', $id);
    }
    $spiGenerations[$id] = $entry;
    assertFrozenDigest($id, $entry, $errors);

    $version = $entry['version'] ?? null;
    if (!is_int($version) || $version < 1) {
        $errors[] = sprintf('SPI generation %s has no positive version.', $id);
    }
    if (($entry['status'] ?? null) !== 'frozen') {
        $errors[] = sprintf('SPI generation %s is listed but not frozen; withdraw it instead.', $id);
    }
    foreach (contractList($entry['surfaces'] ?? null, $id . '.surfaces', $errors) as $surface) {
        if (!is_string($surface) || !isset($surfaces[$surface])) {
            $errors[] = sprintf('SPI generation %s claims unknown contribution surface %s.', $id, (string) $surface);
        }
    }
    foreach (contractList($entry['manifest_schemas'] ?? null, $id . '.manifest_schemas', $errors) as $schema) {
        $matched = false;
        foreach ($manifestGenerations as $manifest) {
            if (($manifest['schema'] ?? null) === $schema && ($manifest['spi'] ?? null) === $version) {
                $matched = true;
            }
        }
        if (!$matched) {
            $errors[] = sprintf(
                'SPI generation %s claims manifest schema %s, which no manifest generation binds to it.',
                $id,
                var_export($schema, true),
            );
        }
    }
    foreach (contractList($entry['pinned_by'] ?? null, $id . '.pinned_by', $errors) as $fixture) {
        if (!is_string($fixture) || !is_file($root . '/' . $fixture)) {
            $errors[] = sprintf('SPI generation %s cites a compatibility fixture that is missing.', $id);
        }
    }
    foreach (contractList($entry['fixtures'] ?? null, $id . '.fixtures', $errors) as $package) {
        if (!is_string($package) || !in_array($package, $fixturePackages, true)) {
            $errors[] = sprintf('SPI generation %s cites a package no manifest generation ships.', $id);
        }
    }
}

$withdrawn = [];
foreach (contractList($generations['withdrawn'] ?? null, 'withdrawn', $errors) as $entry) {
    if (
        !is_array($entry)
        || !is_string($entry['type'] ?? null)
        || !is_string($entry['withdrawn_in'] ?? null)
        || !is_string($entry['reason'] ?? null)
        || ($entry['reason'] === '')
    ) {
        $errors[] = 'Every withdrawn entry needs the type it withdrew, where it went, and why.';
        continue;
    }
    $withdrawn[(string) $entry['type']] = true;
    $path = $root . '/src/' . str_replace('\\', '/', substr((string) $entry['type'], strlen('Kumwe\\App\\'))) . '.php';
    if (is_file($path)) {
        $errors[] = sprintf(
            'Type %s is recorded as withdrawn but still exists. Withdraw it or stop saying it went.',
            (string) $entry['type'],
        );
    }
}

$internalNamespaces = [];
foreach (contractList($classification['namespaces'] ?? null, 'namespaces', $errors) as $entry) {
    if (!is_array($entry) || !is_string($entry['prefix'] ?? null) || !is_string($entry['reason'] ?? null)) {
        $errors[] = 'Every classified namespace needs a prefix and a reason.';
        continue;
    }
    if (($entry['visibility'] ?? null) === 'internal') {
        $internalNamespaces[] = (string) $entry['prefix'];
    }
}

$pinnedFixtures = [];
$classified = [];
foreach (contractList($classification['types'] ?? null, 'types', $errors) as $entry) {
    if (!is_array($entry) || !is_string($entry['type'] ?? null)) {
        $errors[] = 'Every classified type must be an object naming the type.';
        continue;
    }
    $type = (string) $entry['type'];
    if (isset($classified[$type])) {
        $errors[] = sprintf('Type %s is classified more than once.', $type);
    }
    $classified[$type] = true;

    if (isset($withdrawn[$type])) {
        $errors[] = sprintf('Type %s is both classified public and recorded as withdrawn.', $type);
    }
    if (($entry['visibility'] ?? null) !== 'public') {
        $errors[] = sprintf('Type %s is listed but not public; the list holds the public surface only.', $type);
    }
    if (!in_array($entry['kind'] ?? null, ['interface', 'class', 'enum'], true)) {
        $errors[] = sprintf('Type %s has no known kind.', $type);
    }
    if (!isset(($classification['roles'] ?? [])[$entry['role'] ?? ''])) {
        $errors[] = sprintf('Type %s carries a role classification.json does not declare.', $type);
    }
    if (!isset($manifestGenerations[$entry['reachable_from'] ?? ''])) {
        $errors[] = sprintf('Type %s is reachable from a generation that is not declared.', $type);
    }
    $spi = $entry['spi'] ?? null;
    if ($spi !== null) {
        $known = false;
        foreach ($spiGenerations as $generation) {
            if (($generation['version'] ?? null) === $spi) {
                $known = true;
            }
        }
        if (!$known) {
            $errors[] = sprintf('Type %s names contribution SPI %s, which is not a declared generation.', $type, var_export($spi, true));
        }
    }
    foreach ($internalNamespaces as $prefix) {
        if (str_starts_with($type, $prefix)) {
            $errors[] = sprintf('Type %s is classified public inside internal namespace %s.', $type, $prefix);
        }
    }

    $path = $entry['path'] ?? null;
    if (!is_string($path) || !is_file($root . '/' . $path)) {
        $errors[] = sprintf('Type %s names a source path that does not exist.', $type);
        continue;
    }
    $short = substr($type, (int) strrpos($type, '\\') + 1);
    if (!declaresType($root . '/' . $path, $short)) {
        $errors[] = sprintf('Source path %s does not declare %s.', $path, $short);
    }

    $pinnedBy = $entry['pinned_by'] ?? null;
    if ($pinnedBy === null) {
        continue;
    }
    if (!is_string($pinnedBy) || !is_file($root . '/' . $pinnedBy)) {
        $errors[] = sprintf('Type %s cites a compatibility fixture that is missing.', $type);
        continue;
    }
    if (!isset($pinnedFixtures[$pinnedBy])) {
        $pinnedFixtures[$pinnedBy] = pinnedTypes($root . '/' . $pinnedBy);
    }
    if (!isset($pinnedFixtures[$pinnedBy][$type])) {
        $errors[] = sprintf('Type %s claims fixture %s pins it, and that fixture does not name it.', $type, $pinnedBy);
    }
}

$allowlist = $generations['host_services']['services'] ?? null;
if (!is_array($allowlist) || !array_is_list($allowlist)) {
    $errors[] = 'generations.json declares no host-service allowlist.';
} else {
    foreach ($allowlist as $service) {
        if (!is_string($service) || !isset($classified[$service])) {
            $errors[] = sprintf('Host service %s is allowlisted but not classified public.', (string) $service);
        }
    }
    $composition = $generations['host_services']['source'] ?? null;
    $source = is_string($composition) && is_file($root . '/' . $composition)
        ? file_get_contents($root . '/' . $composition)
        : false;
    if (!is_string($source)) {
        $errors[] = 'The composition root named by the host-service allowlist could not be read.';
    } else {
        $declared = allowlistedServices($source);
        $expected = array_map(
            static fn (string $service): string => substr($service, (int) strrpos($service, '\\') + 1),
            array_values(array_filter($allowlist, 'is_string')),
        );
        sort($declared, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($declared !== $expected) {
            $errors[] = sprintf(
                'The composition root hands extensions [%s] and the contract promises [%s]. The allowlist is '
                . 'the whole of what a package may resolve, so the two must be the same list.',
                implode(', ', $declared),
                implode(', ', $expected),
            );
        }
    }
}

if ($errors !== []) {
    reportContractFailure($errors);
}

fwrite(STDOUT, sprintf(
    "Kumwe extension contract verified: %d manifest generations, %d SPI generations, %d public types, "
    . "%d withdrawn.\n",
    count($manifestGenerations),
    count($spiGenerations),
    count($classified),
    count($withdrawn),
));
exit(0);

/**
 * Recompute a generation's freeze digest from its own canonical bytes and compare it to the recorded one.
 *
 * @param   string                $id      Generation identifier used in the failure message.
 * @param   array<string, mixed>  $entry   The generation entry, including its recorded digest.
 * @param   list<string>          $errors  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function assertFrozenDigest(string $id, array $entry, array &$errors): void
{
    $recorded = $entry['surface_digest'] ?? null;
    unset($entry['surface_digest']);
    $canonical = json_encode(canonicalContract($entry), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($canonical)) {
        $errors[] = sprintf('Generation %s could not be canonicalized.', $id);

        return;
    }
    $digest = hash('sha256', $canonical);
    if (!is_string($recorded) || !hash_equals($digest, $recorded)) {
        $errors[] = sprintf(
            'Generation %s promises something other than its recorded frozen surface. Its digest is now %s. '
            . 'A frozen generation does not change: add a generation beside it, or, if the change really is '
            . 'deliberate, record the new digest in the same change that makes it.',
            $id,
            $digest,
        );
    }
}

/**
 * Compare one retained manifest record with the exact grammar arrays consumed by the live parser.
 *
 * Schema 1 intentionally accepts unknown root keys, so only its documented interpreted/advisory inventory
 * is retained. Strict schemas must match every closed root and nested contribution set byte for byte.
 *
 * @param   string                $id      Generation identifier used in diagnostics.
 * @param   array<string, mixed>  $entry   Retained generation entry.
 * @param   list<string>          $errors  Accumulated validation failures.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function assertManifestGrammarParity(string $id, array $entry, array &$errors): void
{
    $schema = $entry['schema'] ?? null;
    if (!is_int($schema) || $schema < 2 || $schema > 6) {
        return;
    }

    $recordedManifestKeys = [];
    foreach ($entry['manifest_keys'] ?? [] as $key) {
        if (is_array($key) && is_string($key['key'] ?? null)) {
            $recordedManifestKeys[] = $key['key'];
        }
    }
    $expected = [
        'manifest_keys' => ExtensionManifestGrammar::manifestKeys($schema),
        'contribution_keys' => ExtensionManifestGrammar::contributionKeys($schema),
        'business_keys' => ExtensionManifestGrammar::businessKeys($schema),
    ];
    if ($schema >= 4) {
        $expected['integration_keys'] = ExtensionManifestGrammar::integrationKeys($schema);
        $expected['content_keys'] = ExtensionManifestGrammar::contentKeys($schema);
    }
    if ($schema >= 5) {
        $expected['composition_keys'] = ExtensionManifestGrammar::compositionKeys($schema);
    }

    foreach ($expected as $member => $runtimeKeys) {
        $recordedKeys = $member === 'manifest_keys'
            ? $recordedManifestKeys
            : ($entry[$member] ?? null);
        if (
            !is_array($recordedKeys)
            || !array_is_list($recordedKeys)
            || count(array_filter($recordedKeys, 'is_string')) !== count($recordedKeys)
        ) {
            $errors[] = sprintf('Manifest generation %s must record %s as a string list.', $id, $member);
            continue;
        }
        /** @var list<string> $recordedKeys */
        $recordedSet = array_values(array_unique($recordedKeys));
        if (count($recordedSet) !== count($recordedKeys)) {
            $errors[] = sprintf('Manifest generation %s records a duplicate key in %s.', $id, $member);
        }
        $runtimeSet = array_values(array_unique($runtimeKeys));
        sort($recordedSet, SORT_STRING);
        sort($runtimeSet, SORT_STRING);
        if ($recordedSet !== $runtimeSet) {
            $errors[] = sprintf(
                'Manifest generation %s records %s as [%s], but the live parser accepts [%s].',
                $id,
                $member,
                implode(', ', $recordedKeys),
                implode(', ', $runtimeKeys),
            );
        }
    }
}

/**
 * Order a decoded value deterministically so its encoding does not depend on key order.
 *
 * @param   mixed  $value  Decoded JSON value.
 *
 * @return  mixed  The same value with every object's keys sorted.
 *
 * @since   2.0.0
 */
function canonicalContract(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    $mapped = array_map(static fn (mixed $item): mixed => canonicalContract($item), $value);
    if (!array_is_list($mapped)) {
        ksort($mapped, SORT_STRING);
    }

    return $mapped;
}

/**
 * Report whether a source file declares a class, interface, enum or trait of the given short name.
 *
 * Tokenizing rather than autoloading is what keeps this check dependency-free, and it is enough: the
 * question is whether the classified path is the file the type actually lives in.
 *
 * @param   string  $path   Absolute path to the source file.
 * @param   string  $short  Short type name to look for.
 *
 * @return  bool  True when the file declares that name.
 *
 * @since   2.0.0
 */
function declaresType(string $path, string $short): bool
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        return false;
    }
    $tokens = token_get_all($source);
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || !in_array($token[0], [T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT], true)) {
            continue;
        }
        for ($next = $index + 1; $next < $count; $next++) {
            $candidate = $tokens[$next];
            if (is_array($candidate) && $candidate[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($candidate) && $candidate[0] === T_STRING && $candidate[1] === $short) {
                return true;
            }
            break;
        }
    }

    return false;
}

/**
 * Read the fully qualified type names one compatibility fixture pins.
 *
 * @param   string  $path  Absolute path to the fixture document.
 *
 * @return  array<string, true>  Pinned type names held as a set.
 *
 * @since   2.0.0
 */
function pinnedTypes(string $path): array
{
    $raw = file_get_contents($path);
    /** @var mixed $document */
    $document = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($document)) {
        return [];
    }
    $names = [];
    foreach (array_keys($document['interfaces'] ?? []) as $name) {
        $names[(string) $name] = true;
    }
    foreach (array_keys($document['enums'] ?? []) as $name) {
        $names[(string) $name] = true;
    }
    if (is_string($document['interface'] ?? null)) {
        $names[$document['interface']] = true;
    }

    return $names;
}

/**
 * Extract the service short names the composition root allowlists for extension containers.
 *
 * The allowlist is the array literal handed to `ExtensionRuntimeLoader::load()`, so this reads the
 * `X::class =>` keys inside that one call rather than every `::class` in the file.
 *
 * @param   string  $source  Contents of the composition root.
 *
 * @return  list<string>  Allowlisted service short names, in source order.
 *
 * @since   2.0.0
 */
function allowlistedServices(string $source): array
{
    $start = strpos($source, '))->load([');
    if ($start === false) {
        return [];
    }
    $end = strpos($source, '],', $start);
    if ($end === false) {
        return [];
    }
    preg_match_all(
        '/([A-Za-z_][A-Za-z0-9_]*)::class\s*=>/',
        substr($source, $start, $end - $start),
        $matches,
    );

    return array_values(array_unique($matches[1]));
}

/**
 * Read one JSON document and record a failure when it is absent or malformed.
 *
 * @param   string        $path    Absolute path to the document.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  array<string, mixed>  Decoded document, or an empty array when it could not be read.
 *
 * @since   2.0.0
 */
function readContractDocument(string $path, array &$errors): array
{
    $name = basename($path);
    if (!is_file($path)) {
        $errors[] = sprintf('%s is missing.', $name);

        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        $errors[] = sprintf('%s could not be read.', $name);

        return [];
    }
    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errors[] = sprintf('%s is not well-formed JSON: %s.', $name, json_last_error_msg());

        return [];
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * Require a value to be a JSON array and return it as a list.
 *
 * @param   mixed         $value   Candidate value.
 * @param   string        $label   Diagnostic label naming the member.
 * @param   list<string>  $errors  Accumulated validation failures.
 *
 * @return  list<mixed>  The list, or an empty list when the value was the wrong shape.
 *
 * @since   2.0.0
 */
function contractList(mixed $value, string $label, array &$errors): array
{
    if (!is_array($value) || !array_is_list($value)) {
        $errors[] = sprintf('The extension contract member "%s" must be a JSON array.', $label);

        return [];
    }

    return $value;
}

/**
 * Print every failure and terminate with a non-zero status.
 *
 * @param   list<string>  $errors  Validation failures.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function reportContractFailure(array $errors): never
{
    $errors = array_values(array_unique($errors));
    fwrite(STDERR, "Kumwe extension contract verification failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}
