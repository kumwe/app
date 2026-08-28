<?php

/**
 * Refuse any non-exact dependency specifier for the Studio contract family.
 *
 * ADR 0007 pins the Studio integration to exact versions while the contract is pre-release, and
 * `docs/roadmap/producer-adoption.md` step 1 extends the same rule to the PHP realization layer: a
 * non-exact specifier for `kumwe/producer` or any `@kumwe/studio` artifact fails the build, because
 * a range would take a contract change silently (finding V2-STU-002). This tool is that gate. It
 * scans every dependency section of `composer.json` and `package.json` for the guarded names and
 * accepts exactly two specifier shapes: a bare exact version (`0.1.0`, `0.1.0-rc.1`), or — for the
 * npm packages only — a `file:` reference into `resources/studio-contract/packages/`, the vendored
 * tarballs whose bytes `composer studio:corpus` already digest-verifies against `PIN.json`. Every
 * range, wildcard, dist-tag, branch, alias or foreign URL is a violation. The gate is dependency
 * free so it runs before `composer install` on any sandbox.
 *
 * Usage:
 *
 *   php tools/verify-studio-dependencies.php [--composer=PATH] [--package=PATH]
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$composerPath = dirname(__DIR__) . '/composer.json';
$packagePath = dirname(__DIR__) . '/package.json';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--composer=')) {
        $composerPath = substr($argument, strlen('--composer='));
        continue;
    }
    if (str_starts_with($argument, '--package=')) {
        $packagePath = substr($argument, strlen('--package='));
        continue;
    }
    fwrite(STDERR, sprintf("Unknown argument: %s\n", $argument));
    exit(2);
}

/**
 * Decode one manifest or record why it could not be read.
 *
 * @param   string        $path    Manifest to decode.
 * @param   list<string>  $errors  Accumulated violations.
 *
 * @return  array<string, mixed>|null  Decoded manifest, or null when unreadable.
 *
 * @since   2.0.0
 */
function decodeManifest(string $path, array &$errors): ?array
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

/**
 * Decide whether one dependency name belongs to the guarded Studio contract family.
 *
 * @param   string  $name      Dependency name as the manifest declares it.
 * @param   bool    $composer  Whether the name comes from `composer.json` rather than `package.json`.
 *
 * @return  bool  True when the exact-pin rule applies to the name.
 *
 * @since   2.0.0
 */
function isGuardedName(string $name, bool $composer): bool
{
    if ($composer) {
        return $name === 'kumwe/producer' || str_starts_with($name, 'kumwe/studio');
    }

    return str_starts_with($name, '@kumwe/studio');
}

/**
 * Decide whether one specifier is a bare exact version with no range semantics.
 *
 * @param   string  $specifier  Declared version specifier.
 *
 * @return  bool  True for `MAJOR.MINOR.PATCH` with optional pre-release and build metadata.
 *
 * @since   2.0.0
 */
function isExactVersion(string $specifier): bool
{
    return preg_match(
        '/^v?\d+\.\d+\.\d+(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?(\+[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$/D',
        $specifier,
    ) === 1;
}

/**
 * Decide whether one npm specifier is a `file:` reference into the vendored pinned tarballs.
 *
 * @param   string  $specifier  Declared version specifier.
 *
 * @return  bool  True when the reference stays inside `resources/studio-contract/packages/`.
 *
 * @since   2.0.0
 */
function isVendoredTarball(string $specifier): bool
{
    return str_starts_with($specifier, 'file:resources/studio-contract/packages/')
        && str_ends_with($specifier, '.tgz')
        && !str_contains($specifier, '..');
}

/**
 * Collect every guarded specifier violation in one manifest's dependency sections.
 *
 * @param   array<string, mixed>  $manifest  Decoded manifest.
 * @param   string                $label     Manifest name used in violation messages.
 * @param   list<string>          $sections  Dependency section keys to scan.
 * @param   bool                  $composer  Whether the manifest is `composer.json`.
 * @param   list<string>          $errors    Accumulated violations.
 *
 * @return  int  Number of guarded specifiers that satisfied the exact-pin rule.
 *
 * @since   2.0.0
 */
function verifySections(array $manifest, string $label, array $sections, bool $composer, array &$errors): int
{
    $pinned = 0;
    foreach ($sections as $section) {
        $dependencies = $manifest[$section] ?? [];
        if (!is_array($dependencies)) {
            $errors[] = sprintf('%s declares %s but it is not an object.', $label, $section);
            continue;
        }
        foreach ($dependencies as $name => $specifier) {
            if (!is_string($name) || !isGuardedName($name, $composer)) {
                continue;
            }
            if (!is_string($specifier)) {
                $errors[] = sprintf('%s %s pins %s to a non-string specifier.', $label, $section, $name);
                continue;
            }
            $exact = isExactVersion($specifier) || (!$composer && isVendoredTarball($specifier));
            if (!$exact) {
                $errors[] = sprintf(
                    '%s %s declares %s as "%s"; ADR 0007 requires an exact version'
                        . '%s while the Studio contract is pre-release.',
                    $label,
                    $section,
                    $name,
                    $specifier,
                    $composer ? '' : ' or a vendored resources/studio-contract/packages tarball',
                );
                continue;
            }
            $pinned++;
        }
    }

    return $pinned;
}

$errors = [];
$pinned = 0;
$composerManifest = decodeManifest($composerPath, $errors);
if ($composerManifest !== null) {
    $pinned += verifySections($composerManifest, 'composer.json', ['require', 'require-dev'], true, $errors);
}
$packageManifest = decodeManifest($packagePath, $errors);
if ($packageManifest !== null) {
    $pinned += verifySections(
        $packageManifest,
        'package.json',
        ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'],
        false,
        $errors,
    );
}

if ($errors !== []) {
    fwrite(STDERR, "The Studio dependency pins do not satisfy ADR 0007:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, ' - ' . $error . "\n");
    }
    exit(1);
}

printf(
    "Kumwe studio dependencies verified: %d exact pin(s), no range for kumwe/producer or @kumwe/studio*.\n",
    $pinned,
);
