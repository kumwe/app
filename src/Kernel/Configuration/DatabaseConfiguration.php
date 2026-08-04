<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;

final readonly class DatabaseConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $user,
        public string $password,
        public string $schema,
        public string $sslMode,
    ) {
        if (
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            && filter_var($host, FILTER_VALIDATE_IP) === false
        ) {
            throw new InvalidArgumentException('The database host is invalid.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The database port is invalid.');
        }

        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $schema)) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }

        if (!in_array($sslMode, ['disable', 'prefer', 'require', 'verify-full'], true)) {
            throw new InvalidArgumentException('The PostgreSQL SSL mode is invalid.');
        }
    }
}
