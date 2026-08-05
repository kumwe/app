<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;

final readonly class ExtensionMigrationRunner
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<ExtensionMigration> migrations applied by this invocation */
    public function apply(ExtensionManifest $manifest, string $extensionRoot): array
    {
        $this->registerAutoload($manifest, $extensionRoot);
        $applied = [];

        foreach ($manifest->migrations() as $class) {
            $migration = new $class();

            if (!$migration instanceof ExtensionMigration) {
                throw new InvalidArgumentException(sprintf(
                    'Extension migration %s must implement %s.',
                    $class,
                    ExtensionMigration::class,
                ));
            }

            $id = $migration->id();
            if (preg_match('/^[0-9]{14}_[a-z][a-z0-9_]{0,80}$/D', $id) !== 1) {
                throw new InvalidArgumentException('Extension migration IDs must be timestamped identifiers.');
            }
            $checksum = $this->checksum($migration, $id);
            if ($this->wasApplied($manifest, $class, $extensionRoot, $id, $checksum)) {
                continue;
            }

            $migration->up($this->database, $this->extensionTables($manifest));
            $this->database->insert($this->tables->raw('extension_migrations'), [
                'extension_identifier' => $manifest->identifier()->value(),
                'migration_id' => $id,
                'migration_sha256' => $checksum,
                'extension_version' => (string) $manifest->version(),
                'applied_at' => $this->clock->now(),
            ], ['applied_at' => Types::DATETIME_IMMUTABLE]);
            $applied[] = $migration;
        }

        return $applied;
    }

    /** @param list<ExtensionMigration> $migrations */
    public function compensate(ExtensionManifest $manifest, array $migrations): void
    {
        foreach ($migrations as $migration) {
            $migration->down($this->database, $this->extensionTables($manifest));
            $this->database->delete($this->tables->raw('extension_migrations'), [
                'extension_identifier' => $manifest->identifier()->value(),
                'migration_id' => $migration->id(),
            ]);
        }
    }

    private function wasApplied(
        ExtensionManifest $manifest,
        string $migrationClass,
        string $incomingRoot,
        string $migrationId,
        string $checksum,
    ): bool {
        $stored = $this->database->fetchOne(sprintf(
            'SELECT migration_sha256 FROM %s WHERE extension_identifier = ? AND migration_id = ?',
            $this->tables->quoted('extension_migrations'),
        ), [$manifest->identifier()->value(), $migrationId]);
        if ($stored === false) {
            return false;
        }
        if ($stored === null) {
            $legacyChecksum = $this->legacyChecksum(
                $manifest,
                $migrationClass,
                $incomingRoot,
                $migrationId,
            );
            $affected = $this->database->update($this->tables->raw('extension_migrations'), [
                'migration_sha256' => $legacyChecksum,
            ], [
                'extension_identifier' => $manifest->identifier()->value(),
                'migration_id' => $migrationId,
            ]);
            if ($affected !== 1) {
                throw new RuntimeException('The legacy extension migration checksum could not be persisted.');
            }
            $stored = $legacyChecksum;
        }
        if (!is_string($stored) || !hash_equals($stored, $checksum)) {
            throw new RuntimeException(sprintf(
                'Extension migration checksum drift detected for %s:%s.',
                $manifest->identifier()->value(),
                $migrationId,
            ));
        }

        return true;
    }

    private function legacyChecksum(
        ExtensionManifest $incoming,
        string $migrationClass,
        string $incomingRoot,
        string $migrationId,
    ): string {
        $release = $this->database->fetchAssociative(sprintf(
            'SELECT e.runtime_path, r.manifest, r.deployed_tree_sha256 FROM %s e '
            . 'INNER JOIN %s r ON r.extension_id = e.id AND r.version = e.installed_version '
            . 'WHERE e.identifier = ?',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ), [$incoming->identifier()->value()]);
        if ($release === false) {
            throw new RuntimeException('A legacy extension migration has no installed release to verify.');
        }
        $runtimePath = $release['runtime_path'] ?? null;
        $treeDigest = $release['deployed_tree_sha256'] ?? null;
        if (!is_string($runtimePath)
            || preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+$#D', $runtimePath) !== 1
            || !is_string($treeDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $treeDigest) !== 1) {
            throw new RuntimeException('The installed release cannot anchor a legacy migration checksum.');
        }
        $extensionRoot = dirname($incomingRoot, 3);
        $base = realpath($extensionRoot);
        $root = realpath($extensionRoot . '/' . $runtimePath);
        if (!is_string($base) || !is_string($root) || !str_starts_with($root . '/', $base . '/')) {
            throw new RuntimeException('The installed release root is missing or unsafe.');
        }
        if (!hash_equals($treeDigest, FilesystemExtensionArtifactVerifier::treeDigest($root))) {
            throw new RuntimeException('The installed release tree changed before legacy migration verification.');
        }

        $document = $release['manifest'] ?? null;
        if (is_string($document)) {
            $document = json_decode($document, true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new RuntimeException('The installed release manifest is invalid.');
        }
        $migrations = $document['migrations'] ?? [];
        $autoloadDocument = $document['autoload'] ?? null;
        $autoload = is_array($autoloadDocument) && !array_is_list($autoloadDocument)
            ? ($autoloadDocument['psr-4'] ?? [])
            : [];
        if (!is_array($migrations) || !in_array($migrationClass, $migrations, true)
            || !is_array($autoload) || array_is_list($autoload)) {
            throw new RuntimeException('The installed release does not declare the legacy migration class.');
        }

        $source = null;
        $matchedPrefix = '';
        foreach ($autoload as $prefix => $relativePath) {
            if (!is_string($prefix) || !is_string($relativePath)
                || !str_starts_with($migrationClass, $prefix)
                || strlen($prefix) <= strlen($matchedPrefix)) {
                continue;
            }
            $relativeClass = str_replace('\\', '/', substr($migrationClass, strlen($prefix))) . '.php';
            $source = $root . '/' . trim($relativePath, '/') . '/' . $relativeClass;
            $matchedPrefix = $prefix;
        }
        if (!is_string($source)) {
            throw new RuntimeException('The installed legacy migration source is not autoloadable.');
        }
        $resolved = realpath($source);
        if (!is_string($resolved) || !is_file($resolved) || is_link($source)
            || !str_starts_with($resolved, $root . '/')) {
            throw new RuntimeException('The installed legacy migration source is missing or unsafe.');
        }
        $candidate = $root;
        foreach (explode('/', substr($resolved, strlen($root) + 1)) as $segment) {
            $candidate .= '/' . $segment;
            if (is_link($candidate)) {
                throw new RuntimeException('The installed legacy migration source contains a symbolic link.');
            }
        }
        $digest = hash_file('sha256', $resolved);
        if (!is_string($digest)) {
            throw new RuntimeException('The installed legacy migration source could not be checksummed.');
        }

        return hash('sha256', $migrationClass . ':' . $migrationId . ':' . $digest);
    }

    private function checksum(ExtensionMigration $migration, string $id): string
    {
        $reflection = new \ReflectionClass($migration);
        $file = $reflection->getFileName();
        $digest = is_string($file) && is_file($file) && !is_link($file) ? hash_file('sha256', $file) : false;
        if (!is_string($digest)) {
            throw new RuntimeException('An extension migration source file could not be checksummed.');
        }

        return hash('sha256', $migration::class . ':' . $id . ':' . $digest);
    }

    private function extensionTables(ExtensionManifest $manifest): ExtensionTableNames
    {
        return new ExtensionTableNames($this->database, $this->tables, $manifest->identifier());
    }

    private function registerAutoload(ExtensionManifest $manifest, string $root): void
    {
        foreach ($manifest->autoload() as $prefix => $relativePath) {
            $base = $root . '/' . rtrim($relativePath, '/');
            $candidate = rtrim($root, '/');
            foreach (explode('/', trim($relativePath, '/')) as $segment) {
                $candidate .= '/' . $segment;
                if (is_link($candidate)) {
                    throw new RuntimeException('An extension migration autoload root contains a symbolic link.');
                }
            }
            if (is_link($base) || !is_dir($base)) {
                throw new RuntimeException('An extension migration autoload root is not a regular directory.');
            }
            $resolvedBase = realpath($base);
            if (!is_string($resolvedBase) || !str_starts_with($resolvedBase . '/', rtrim($root, '/') . '/')) {
                throw new RuntimeException('An extension migration autoload root escapes its deployment.');
            }
            spl_autoload_register(static function (string $class) use ($prefix, $resolvedBase): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $relativeFile = str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                $file = $resolvedBase . '/' . $relativeFile;
                if (!file_exists($file) && !is_link($file)) {
                    return;
                }
                $candidate = $resolvedBase;
                foreach (explode('/', $relativeFile) as $segment) {
                    $candidate .= '/' . $segment;
                    if (is_link($candidate)) {
                        throw new RuntimeException('An extension migration autoload path contains a symbolic link.');
                    }
                }
                $resolved = realpath($file);
                if (is_link($file) || !is_file($file) || !is_string($resolved)
                    || !str_starts_with($resolved, $resolvedBase . '/')) {
                    throw new RuntimeException('An extension migration autoload target is not a trusted file.');
                }
                require $resolved;
            });
        }

        foreach ($manifest->migrations() as $class) {
            if (!class_exists($class)) {
                throw new RuntimeException(sprintf('Extension migration %s cannot be loaded.', $class));
            }
        }
    }
}
