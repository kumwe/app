<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\Extension\Manifest\ExtensionManifest;
use Kumwe\Extension\Spi\Migration\ExtensionMigration;
use Kumwe\Extension\Spi\Migration\ExtensionTableNames;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * Applies an extension's declared migrations exactly once per site, and ledgers what it ran.
 *
 * Extension code is third-party code that ships schema changes, so this runner is the choke point that
 * decides what of it may execute. It loads the migration classes itself through a PSR-4 autoloader
 * pinned to the deployed directory — refusing symlinked or escaping roots — then records every applied
 * migration in the `extension_migrations` table alongside a SHA-256 over the migration's class name, its
 * ID and the bytes of its source file. On the next run that digest is re-derived and compared, so a
 * later release cannot quietly reuse an applied ID with different executable bytes; drift aborts the
 * install rather than skipping the step. Every row must already carry that canonical digest; there is no
 * compatibility backfill or alternate checksum authority.
 *
 * @since  2.0.0
 */
final readonly class ExtensionMigrationRunner
{
    /**
     * Wire the runner to the site it migrates.
     *
     * @param  Connection      $database  Connection the migrations and the ledger writes execute on.
     * @param  TableNames      $tables    Core name compiler, used for the ledger and registry tables and as
     *         the base the per-extension namespace is prefixed onto.
     * @param  ClockInterface  $clock     Supplies the `applied_at` timestamp stamped on each ledger row.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Run every migration the manifest declares that this site has not already applied.
     *
     * Migrations run in manifest order, and the runner opens no transaction of its own: each ledger row
     * is written immediately after the migration it records, because DDL commits implicitly on MySQL and
     * MariaDB and cannot be enclosed. A migration already recorded is skipped, but not silently — its
     * digest is re-derived and compared first, and a mismatch aborts the run instead.
     *
     * @param   ExtensionManifest  $manifest       Manifest whose `migrations` list is applied; its identifier
     *          keys the ledger rows and its version is recorded on each of them.
     * @param   string             $extensionRoot  Absolute path of the deployed package directory the
     *          manifest's PSR-4 autoload paths are resolved against.
     *
     * @return  list<ExtensionMigration>  Migrations applied by this invocation, in manifest order; empty when
     *          the ledger already recorded every declared migration.
     *
     * @throws  InvalidArgumentException  When a declared class does not implement ExtensionMigration, or its
     *          ID is not a timestamped identifier.
     * @throws  RuntimeException  When an autoload root is unsafe or a declared class cannot be loaded, when a
     *          migration's source cannot be checksummed, or when the ledger disagrees with it.
     *
     * @since   2.0.0
     */
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
            if ($this->wasApplied($manifest, $id, $checksum)) {
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

    /**
     * Undo migrations applied by an installation attempt that will not complete.
     *
     * Each migration's `down()` runs and its ledger row is deleted, in the order the list is given — so a
     * caller unwinding an install passes the applied list reversed. Only migrations this attempt applied
     * belong here; a migration left out of the list keeps its ledger row and its schema.
     *
     * @param   ExtensionManifest         $manifest    Extension whose ledger rows are removed; only its
     *          identifier is read.
     * @param   list<ExtensionMigration>  $migrations  Migrations to compensate, in the order `down()` should
     *          run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether this migration has already run here, and prove the ledger still agrees with it.
     *
     * @param   ExtensionManifest  $manifest     Extension whose ledger is consulted.
     * @param   string             $migrationId  Identifier the ledger row is keyed by.
     * @param   string             $checksum     Digest derived from the incoming migration's source, which
     *          the stored digest must equal.
     *
     * @return  bool  True when the migration is already recorded and its digest matches, so `up()` must be
     *          skipped; false when this site has never applied it.
     *
     * @throws  RuntimeException  When the stored digest is absent, malformed or does not match the incoming one.
     *
     * @since   2.0.0
     */
    private function wasApplied(
        ExtensionManifest $manifest,
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
        if (!is_string($stored) || !hash_equals($stored, $checksum)) {
            throw new RuntimeException(sprintf(
                'Extension migration checksum drift detected for %s:%s.',
                $manifest->identifier()->value(),
                $migrationId,
            ));
        }

        return true;
    }

    /**
     * Digest the executable identity of a migration: which class, under which ID, from which bytes.
     *
     * Binding all three means a package cannot swap the body of an applied migration, nor move it to a
     * new class, without the ledger comparison noticing. The source file is located by reflection and is
     * rejected if it is a symbolic link, so the digest describes a file inside the deployment.
     *
     * @param   ExtensionMigration  $migration  Instance whose declaring file is hashed.
     * @param   string              $id         Identifier the migration declares, mixed into the digest.
     *
     * @return  string  Hex SHA-256 over the class name, the ID and the source file's contents.
     *
     * @throws  RuntimeException  When the declaring file is unknown, is not a regular file, is a symbolic
     *          link, or cannot be read.
     *
     * @since   2.0.0
     */
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

    /**
     * Build the table-name compiler a migration is handed, scoped to the manifest's extension.
     *
     * @param   ExtensionManifest  $manifest  Manifest whose identifier becomes the table namespace.
     *
     * @return  ExtensionTableNames  Compiler that prefixes every name with the site prefix and this
     *          extension's namespace, so a migration cannot reach a core or foreign table.
     *
     * @since   2.0.0
     */
    private function extensionTables(ExtensionManifest $manifest): ExtensionTableNames
    {
        return new ScopedExtensionTableNames($this->database, $this->tables, $manifest->identifier());
    }

    /**
     * Make the extension's migration classes loadable, without widening what the process will load.
     *
     * Each declared PSR-4 prefix gets its own autoloader confined to one resolved directory beneath the
     * deployment: the root itself, and later every path segment of a requested class file, is refused if
     * it is a symbolic link or resolves outside that directory, so a package cannot use its own autoload
     * map to pull in code from elsewhere on the host. Every declared migration class is then loaded
     * eagerly, so a missing class fails here rather than half way through the schema changes.
     *
     * @param   ExtensionManifest  $manifest  Supplies the PSR-4 prefixes to register and the migration
     *          classes that must resolve through them.
     * @param   string             $root      Deployed package directory the relative autoload paths are
     *          resolved against and confined to.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an autoload root is a symbolic link, is not a directory, or escapes the
     *          deployment; when a resolved class file is a link, is outside the root, or is not a regular
     *          file; or when a declared migration class cannot be loaded at all.
     *
     * @since   2.0.0
     */
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
                if (
                    is_link($file) || !is_file($file) || !is_string($resolved)
                    || !str_starts_with($resolved, $resolvedBase . '/')
                ) {
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
