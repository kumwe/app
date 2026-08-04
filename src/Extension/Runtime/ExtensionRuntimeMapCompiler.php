<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use RuntimeException;

final readonly class ExtensionRuntimeMapCompiler
{
    public function __construct(
        private DatabaseInterface $database,
        private string $schema,
        private string $mapFile,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function rebuild(): int
    {
        $query = $this->database->getQuery(true)
            ->select([
                $this->quoteName('e.identifier'),
                $this->quoteName('e.service_provider'),
                $this->quoteName('e.extension_type'),
                $this->quoteName('e.runtime_path'),
                $this->quoteName('r.manifest'),
            ])
            ->from($this->quoteName($this->schema . '.extensions', 'e'))
            ->join(
                'INNER',
                $this->quoteName($this->schema . '.extension_releases', 'r')
                    . ' ON ' . $this->quoteName('r.extension_id') . ' = ' . $this->quoteName('e.id')
                    . ' AND ' . $this->quoteName('r.version') . ' = ' . $this->quoteName('e.installed_version'),
            )
            ->where($this->quoteName('e.status') . " = 'active'")
            ->order($this->quoteName('e.identifier'));
        $rows = $this->database->setQuery($query)->loadAssocList();

        if (!is_array($rows)) {
            throw new RuntimeException('The active extension query returned an invalid result set.');
        }

        $extensions = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('The active extension query returned an invalid row.');
            }

            $identifier = $row['identifier'] ?? null;
            $provider = $row['service_provider'] ?? null;
            $runtimePath = $row['runtime_path'] ?? null;
            $type = $row['extension_type'] ?? null;
            $manifestJson = $row['manifest'] ?? null;

            if (
                !is_string($identifier)
                || !is_string($provider)
                || !is_string($runtimePath)
                || !is_string($manifestJson)
                || !is_string($type)
            ) {
                throw new RuntimeException('An active extension has incomplete runtime metadata.');
            }

            $manifest = ExtensionManifest::fromJson($manifestJson);
            $extensions[] = [
                'identifier' => $identifier,
                'provider' => $provider,
                'type' => $type,
                'root' => $runtimePath,
                'autoload' => $manifest->autoload(),
            ];
        }

        $generation = $this->nextGeneration();
        $payload = json_encode([
            'format' => 'kumwe-extension-map-v1',
            'generation' => $generation,
            'extensions' => $extensions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $directory = dirname($this->mapFile);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The extension runtime cache directory could not be created.');
        }

        $temporary = $this->mapFile . '.tmp.' . bin2hex(random_bytes(8));

        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload)) {
                throw new RuntimeException('The extension runtime map could not be written completely.');
            }

            chmod($temporary, 0600);

            if (!rename($temporary, $this->mapFile)) {
                throw new RuntimeException('The extension runtime map could not be activated atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $generation;
    }

    private function nextGeneration(): int
    {
        $table = $this->quoteName($this->schema . '.extension_runtime_generation');
        $this->database->setQuery(sprintf(
            'UPDATE %s SET generation = generation + 1, rebuilt_at = CURRENT_TIMESTAMP WHERE singleton = true',
            $table,
        ))->execute();
        $result = $this->database->setQuery(sprintf(
            'SELECT generation FROM %s WHERE singleton = true',
            $table,
        ))->loadResult();

        if (!is_int($result) && (!is_string($result) || preg_match('/^[0-9]+$/D', $result) !== 1)) {
            throw new RuntimeException('The extension runtime generation is invalid.');
        }

        return (int) $result;
    }

    private function quoteName(string $name, ?string $alias = null): string
    {
        $quoted = $this->database->quoteName($name, $alias);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
