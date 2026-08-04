<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Redis;

use Kumwe\CMS\Kernel\Configuration\RedisConfiguration;
use Redis;
use RuntimeException;

final readonly class RedisConnectionFactory
{
    public function __construct(private RedisConfiguration $configuration)
    {
    }

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
