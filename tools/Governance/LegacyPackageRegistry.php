<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Reads and validates `docs/architecture/governance/legacy-packages.json`, the approved legacy-unmanifested registry.
 *
 * A Kumwe package installed without Version 2 manifests may enter the capability index only through an entry
 * here, approved for one exact locked version. The registry is validated against its schema and every entry
 * is keyed by package name, so the index builder can ask one question: is this package, at this version,
 * an approved transitional entry.
 *
 * @since  2.0.0
 */
final readonly class LegacyPackageRegistry
{
    /**
     * Keep the validated registry.
     *
     * @param  string  $path  Registry path.
     * @param  array<string, array{installed_version: string, reason: string, responsibility: string,
     *         non_responsibilities: list<string>, canonical_namespaces: list<string>,
     *         retired_app_namespaces: list<string>, verified_legacy_release: string|null,
     *         approved_by: string, approved_on: string}>  $entries  Entries by package name, sorted.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $path,
        private array $entries,
    ) {
    }

    /**
     * Read the registry and validate it against `legacy-packages.v1.schema.json`.
     *
     * @param   string  $path             Absolute path of the registry document.
     * @param   string  $schemaDirectory  Absolute path of the schema directory.
     *
     * @return  self  The validated registry.
     *
     * @throws  GovernanceViolation  When the document is missing, malformed or fails its schema.
     *
     * @since   2.0.0
     */
    public static function read(string $path, string $schemaDirectory): self
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw GovernanceViolation::at(
                $path,
                'the legacy package registry is missing',
                'restore docs/architecture/governance/legacy-packages.json, with an empty "packages" object '
                . 'if none apply',
            );
        }
        /** @var mixed $decoded */
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw GovernanceViolation::at($path, 'the registry is not a JSON object', 'repair the document');
        }
        /** @var array<string, mixed> $decoded */
        $violations = (new SchemaValidator())->validate($decoded, $schemaDirectory . '/legacy-packages.v1.schema.json');
        if ($violations !== []) {
            throw GovernanceViolation::at(
                $path,
                'the registry fails legacy-packages.v1.schema.json: ' . implode('; ', $violations),
                'repair the listed properties',
            );
        }

        /** @var array<string, array<string, mixed>> $packages */
        $packages = $decoded['packages'];
        $entries = [];
        foreach ($packages as $name => $entry) {
            if (preg_match('/^kumwe\/[a-z0-9-]+$/', $name) !== 1) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('"%s" is not a Kumwe package name', $name),
                    'key every entry by its Composer name, such as kumwe/conversion',
                );
            }
            /** @var array{installed_version: string, reason: string, responsibility: string,
             *   non_responsibilities: list<string>, canonical_namespaces: list<string>,
             *   retired_app_namespaces: list<string>, verified_legacy_release: string|null,
             *   approved_by: string, approved_on: string} $entry */
            $entries[$name] = $entry;
        }
        ksort($entries, SORT_STRING);

        return new self($path, $entries);
    }

    /**
     * The registry path.
     *
     * @return  string  As given to `read()`.
     *
     * @since   2.0.0
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Every approved entry.
     *
     * @return  array<string, array{installed_version: string, reason: string, responsibility: string,
     *          non_responsibilities: list<string>, canonical_namespaces: list<string>,
     *          retired_app_namespaces: list<string>, verified_legacy_release: string|null,
     *          approved_by: string, approved_on: string}>  By package name, sorted.
     *
     * @since   2.0.0
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * One approved entry.
     *
     * @param   string  $package  Package name.
     *
     * @return  array{installed_version: string, reason: string, responsibility: string,
     *          non_responsibilities: list<string>, canonical_namespaces: list<string>,
     *          retired_app_namespaces: list<string>, verified_legacy_release: string|null,
     *          approved_by: string, approved_on: string}|null  The entry, or null when the package is not listed.
     *
     * @since   2.0.0
     */
    public function entry(string $package): ?array
    {
        return $this->entries[$package] ?? null;
    }
}
