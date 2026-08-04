<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use RuntimeException;

final readonly class ExtensionRuntimeMapCompiler
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private string $mapFile,
    ) {
    }

    public function rebuild(): int
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT e.identifier, e.service_provider, e.extension_type, e.runtime_path, r.manifest '
            . 'FROM %s e INNER JOIN %s r ON r.extension_id = e.id AND r.version = e.installed_version '
            . "WHERE e.status = 'active' ORDER BY e.identifier",
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
        ));

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
                || !is_string($type)
            ) {
                throw new RuntimeException('An active extension has incomplete runtime metadata.');
            }

            $manifest = ExtensionManifest::fromJson(is_string($manifestJson)
                ? $manifestJson
                : json_encode($manifestJson, JSON_THROW_ON_ERROR));
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
        $table = $this->tables->quoted('extension_runtime_generation');
        $this->database->executeStatement(sprintf(
            'UPDATE %s SET generation = generation + 1, rebuilt_at = CURRENT_TIMESTAMP WHERE singleton_key = 1',
            $table,
        ));
        $result = $this->database->fetchOne(sprintf(
            'SELECT generation FROM %s WHERE singleton_key = 1',
            $table,
        ));

        if (!is_int($result) && (!is_string($result) || preg_match('/^[0-9]+$/D', $result) !== 1)) {
            throw new RuntimeException('The extension runtime generation is invalid.');
        }

        return (int) $result;
    }
}
