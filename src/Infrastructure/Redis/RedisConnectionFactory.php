<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Redis;

use Kumwe\CMS\Kernel\Configuration\RedisConfiguration;
use Redis;
use RuntimeException;

/**
 * Opens the single connected `Redis` client the container shares for leases, limits and caches.
 *
 * Connecting, authenticating, selecting the logical database and installing the key prefix are settled
 * here once, so no caller has to repeat the handshake or remember the deployment namespace — the client
 * this hands back already scopes every key it writes. Each step is checked and turned into a
 * `RuntimeException` whose message names what failed and repeats no credentials, so a misconfigured or
 * unreachable server is reported in a form an operator can act on rather than as a raw driver failure
 * at whichever call site happened to need Redis first.
 *
 * @since  2.0.0
 */
final readonly class RedisConnectionFactory
{
    /**
     * Bind the factory to the settings it opens the connection from.
     *
     * @param  RedisConfiguration  $configuration  Validated host, port, credentials, logical database
     *         and key namespace for this deployment.
     *
     * @since  2.0.0
     */
    public function __construct(private RedisConfiguration $configuration)
    {
    }

    /**
     * Connect, authenticate and prepare a client for this deployment.
     *
     * The connect attempt is given two seconds, so an unreachable server fails fast instead of holding
     * open the request that first needed Redis. Authentication is skipped when the configuration
     * carries no password, which is the usual shape for a server reachable only on a private network.
     *
     * @return  Redis  A connected client, authenticated where credentials were configured, pointed at
     *          the configured logical database and prefixing every key with the namespace.
     *
     * @throws  RuntimeException  When ext-redis is not installed, the server cannot be reached, the
     *          credentials are rejected, or the logical database cannot be selected.
     *
     * @since   2.0.0
     */
    public function create(): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('The ext-redis PHP extension is required.');
        }

        $redis = new Redis();
        if (!$redis->connect($this->configuration->host, $this->configuration->port, 2.0)) {
            throw new RuntimeException('Kumwe could not connect to Redis.');
        }
        if ($this->configuration->password !== null && !$redis->auth($this->configuration->password)) {
            throw new RuntimeException('Redis rejected the configured credentials.');
        }
        if (!$redis->select($this->configuration->database)) {
            throw new RuntimeException('Redis rejected the configured logical database.');
        }
        $redis->setOption(Redis::OPT_PREFIX, $this->configuration->namespace . ':');

        return $redis;
    }
}
