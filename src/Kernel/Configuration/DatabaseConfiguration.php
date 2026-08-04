<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;

final readonly class DatabaseConfiguration
{
    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $user,
        public string $password,
        public string $tablePrefix,
        public string $sslMode,
        public string $serverVersion,
    ) {
        if (!in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException('DB_DRIVER must be pgsql, mysql, or mariadb.');
        }
        if (
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            && filter_var($host, FILTER_VALIDATE_IP) === false
        ) {
            throw new InvalidArgumentException('The database host is invalid.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The database port is invalid.');
        }

        if (preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $tablePrefix) !== 1) {
            throw new InvalidArgumentException('The database table prefix is invalid.');
        }

        if (!in_array($sslMode, ['disable', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new InvalidArgumentException('The database SSL mode is invalid.');
        }

        if (trim($serverVersion) === '') {
            throw new InvalidArgumentException('The database server version is required.');
        }
    }
}
