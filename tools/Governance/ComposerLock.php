<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Governance;

/**
 * Reads `composer.lock` and exposes the locked `kumwe/*` packages with their immutable coordinates.
 *
 * The lock is the only statement of which package releases are installed, so every capability-index entry is
 * derived from it rather than from whatever happens to sit under `vendor/`. Runtime and development sections
 * are both read: an installed Kumwe package without Version 2 manifests must be an approved legacy entry
 * whichever section requires it.
 *
 * @since  2.0.0
 */
final readonly class ComposerLock
{
    /**
     * Keep the parsed lock.
     *
     * @param  string  $path    Lock file path.
     * @param  string  $sha256  Digest of the lock bytes.
     * @param  array<string, array{name: string, version: string, source: array{type: string, url: string,
     *         reference: string}, dist: array{type: string, url: string, reference: string},
     *         license: list<string>, psr4: array<string, list<string>>, extra: array<string, mixed>}>  $packages
     *         Locked Kumwe packages by name, sorted.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $path,
        private string $sha256,
        private array $packages,
    ) {
    }

    /**
     * Read a lock file.
     *
     * @param   string  $path  Absolute path of `composer.lock`.
     *
     * @return  self  The parsed lock.
     *
     * @throws  GovernanceViolation  When the file is missing, malformed or a Kumwe package lacks coordinates.
     *
     * @since   2.0.0
     */
    public static function read(string $path): self
    {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            throw GovernanceViolation::at($path, 'composer.lock is missing', 'run composer install to create the lock');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($bytes, true);
        if (!is_array($decoded)) {
            throw GovernanceViolation::at(
                $path,
                'composer.lock is not well-formed JSON',
                'regenerate it with Composer',
            );
        }

        $packages = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $entries = $decoded[$section] ?? [];
            if (!is_array($entries)) {
                throw GovernanceViolation::at($path, sprintf('"%s" is not a list', $section), 'regenerate the lock');
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
                    throw GovernanceViolation::at(
                        $path,
                        sprintf('"%s" contains a malformed entry', $section),
                        'regenerate it',
                    );
                }
                if (!str_starts_with($entry['name'], 'kumwe/')) {
                    continue;
                }
                /** @var array<string, mixed> $entry */
                $packages[$entry['name']] = self::normalise($entry, $path);
            }
        }
        ksort($packages, SORT_STRING);

        return new self($path, hash('sha256', $bytes), $packages);
    }

    /**
     * Digest of the lock bytes.
     *
     * @return  string  Lowercase hexadecimal SHA-256.
     *
     * @since   2.0.0
     */
    public function sha256(): string
    {
        return $this->sha256;
    }

    /**
     * The lock file path.
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
     * Every locked Kumwe package.
     *
     * @return  array<string, array{name: string, version: string, source: array{type: string, url: string,
     *          reference: string}, dist: array{type: string, url: string, reference: string},
     *          license: list<string>, psr4: array<string, list<string>>, extra: array<string, mixed>}>
     *          By package name, sorted.
     *
     * @since   2.0.0
     */
    public function packages(): array
    {
        return $this->packages;
    }

    /**
     * One locked Kumwe package.
     *
     * @param   string  $name  Package name such as `kumwe/conversion`.
     *
     * @return  array{name: string, version: string, source: array{type: string, url: string, reference: string},
     *          dist: array{type: string, url: string, reference: string}, license: list<string>,
     *          psr4: array<string, list<string>>, extra: array<string, mixed>}|null  The entry, or null when
     *          the package is not locked.
     *
     * @since   2.0.0
     */
    public function package(string $name): ?array
    {
        return $this->packages[$name] ?? null;
    }

    /**
     * Normalise one lock entry, refusing anything that is not an immutable coordinate.
     *
     * @param   array<string, mixed>  $entry  Raw lock entry.
     * @param   string                $path   Lock path, for diagnostics.
     *
     * @return  array{name: string, version: string, source: array{type: string, url: string, reference: string},
     *          dist: array{type: string, url: string, reference: string}, license: list<string>,
     *          psr4: array<string, list<string>>, extra: array<string, mixed>}  The normalised entry.
     *
     * @throws  GovernanceViolation  When the version, source or dist is missing or not a full commit reference.
     *
     * @since   2.0.0
     */
    private static function normalise(array $entry, string $path): array
    {
        /** @var string $name */
        $name = $entry['name'];
        $version = $entry['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw GovernanceViolation::at($path, sprintf('%s has no locked version', $name), 'lock an exact release');
        }

        $coordinates = [];
        foreach (['source', 'dist'] as $kind) {
            $declared = $entry[$kind] ?? null;
            $type = is_array($declared) ? ($declared['type'] ?? null) : null;
            $url = is_array($declared) ? ($declared['url'] ?? null) : null;
            $reference = is_array($declared) ? ($declared['reference'] ?? null) : null;
            if (!is_string($type) || !is_string($url) || !is_string($reference)) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('%s %s has no %s type, url and reference', $name, $version, $kind),
                    'lock the package from an immutable Git or archive coordinate',
                );
            }
            if (preg_match('/^[a-f0-9]{40}$/', $reference) !== 1) {
                throw GovernanceViolation::at(
                    $path,
                    sprintf('%s %s %s reference "%s" is not a full commit SHA', $name, $version, $kind, $reference),
                    'lock the package to a release whose reference is the full 40-character commit',
                );
            }
            $coordinates[$kind] = ['type' => $type, 'url' => $url, 'reference' => $reference];
        }

        $license = [];
        foreach (is_array($entry['license'] ?? null) ? $entry['license'] : [] as $identifier) {
            if (is_string($identifier)) {
                $license[] = $identifier;
            }
        }
        sort($license, SORT_STRING);

        $psr4 = [];
        $autoload = is_array($entry['autoload'] ?? null) ? ($entry['autoload']['psr-4'] ?? []) : [];
        foreach (is_array($autoload) ? $autoload : [] as $namespace => $directories) {
            if (!is_string($namespace)) {
                continue;
            }
            $list = is_array($directories) ? array_values($directories) : [$directories];
            $psr4[$namespace] = array_values(array_filter($list, 'is_string'));
        }
        ksort($psr4, SORT_STRING);
        if ($psr4 === []) {
            throw GovernanceViolation::at(
                $path,
                sprintf('%s %s declares no PSR-4 autoload root', $name, $version),
                'a Kumwe package must declare its canonical namespace under autoload.psr-4',
            );
        }

        /** @var array<string, mixed> $extra */
        $extra = is_array($entry['extra'] ?? null) ? $entry['extra'] : [];

        return [
            'name' => $name,
            'version' => $version,
            'source' => $coordinates['source'],
            'dist' => $coordinates['dist'],
            'license' => $license,
            'psr4' => $psr4,
            'extra' => $extra,
        ];
    }
}
