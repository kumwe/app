<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

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

        try {
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
                if ($this->wasApplied($manifest, $id)) {
                    continue;
                }

                $migration->up($this->database, $this->extensionTables($manifest));
                $this->database->insert($this->tables->raw('extension_migrations'), [
                    'extension_identifier' => $manifest->identifier()->value(),
                    'migration_id' => $id,
                    'extension_version' => (string) $manifest->version(),
                    'applied_at' => $this->clock->now(),
                ], ['applied_at' => Types::DATETIME_IMMUTABLE]);
                $applied[] = $migration;
            }
        } catch (Throwable $exception) {
            $this->compensate($manifest, array_reverse($applied));

            throw $exception;
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

    private function wasApplied(ExtensionManifest $manifest, string $migrationId): bool
    {
        $count = $this->database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE extension_identifier = ? AND migration_id = ?',
            $this->tables->quoted('extension_migrations'),
        ), [$manifest->identifier()->value(), $migrationId]);

        return is_int($count) ? $count > 0 : is_string($count) && ctype_digit($count) && (int) $count > 0;
    }

    private function extensionTables(ExtensionManifest $manifest): ExtensionTableNames
    {
        return new ExtensionTableNames($this->database, $this->tables, $manifest->identifier());
    }

    private function registerAutoload(ExtensionManifest $manifest, string $root): void
    {
        foreach ($manifest->autoload() as $prefix => $relativePath) {
            $base = $root . '/' . rtrim($relativePath, '/');
            spl_autoload_register(static function (string $class) use ($prefix, $base): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $file = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file) && !is_link($file)) {
                    require $file;
                }
            });
        }

        foreach ($manifest->migrations() as $class) {
            if (!class_exists($class)) {
                throw new RuntimeException(sprintf('Extension migration %s cannot be loaded.', $class));
            }
        }
    }
}
