<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;

final readonly class RedisConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $password,
        public int $database,
        public string $namespace,
    ) {
        if (
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            && filter_var($host, FILTER_VALIDATE_IP) === false
        ) {
            throw new InvalidArgumentException('The Redis host is invalid.');
        }
        if ($port < 1 || $port > 65535 || $database < 0 || $database > 15) {
            throw new InvalidArgumentException('The Redis port or database is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $namespace) !== 1) {
            throw new InvalidArgumentException('The Redis namespace is invalid.');
        }
    }
}
