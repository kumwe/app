<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Infrastructure\Execution;

use InvalidArgumentException;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaEnvironment;

final readonly class ConfiguredBusinessSchemaEnvironment implements BusinessSchemaEnvironment
{
    public function __construct(
        private string $driver,
        private string $serverVersion,
        private string $release,
    ) {
        if (!in_array($driver, ['mariadb', 'mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException('The business-schema database driver is unsupported.');
        }
        foreach ([$serverVersion, $release] as $value) {
            if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('A business-schema environment identity is invalid.');
            }
        }
    }

    public function databaseDriver(): string
    {
        return $this->driver;
    }

    public function databaseServerVersion(): string
    {
        return $this->serverVersion;
    }

    public function applicationRelease(): string
    {
        return $this->release;
    }
}
