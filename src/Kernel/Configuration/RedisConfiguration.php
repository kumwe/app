<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;

/**
 * Validated connection settings for the Redis server Kumwe uses for leases, rate limits, and caches.
 *
 * `ConfigurationFactory` builds one instance from the `REDIS_*` variables and
 * `RedisConnectionFactory` turns it into a connected client. The namespace is applied as a key
 * prefix on that client, so several deployments can share one server and logical database without
 * colliding. Validating here means a malformed host or an out-of-range database index fails at boot
 * rather than when the first lock is taken.
 *
 * @since  2.0.0
 */
final readonly class RedisConfiguration
{
    /**
     * Capture and validate the settings needed to reach the Redis server.
     *
     * @param   string   $host       Host name or IP address of the Redis server.
     * @param   int      $port       TCP port the server listens on, between 1 and 65535.
     * @param   ?string  $password   Secret for `AUTH`, or null when the server needs no credentials.
     * @param   int      $database   Logical database index to select, between 0 and 15.
     * @param   string   $namespace  Prefix applied to every key, separating this deployment's data.
     *
     * @throws  InvalidArgumentException  When the host is unusable, the port or database index falls
     *          outside its range, or the namespace is not a lowercase dotted identifier.
     *
     * @since   2.0.0
     */
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
