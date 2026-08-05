<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\Infrastructure;

use Kumwe\CMS\Infrastructure\Redis\RedisConnectionFactory;
use Kumwe\CMS\Infrastructure\Redis\RedisLease;
use Kumwe\CMS\Infrastructure\Redis\RedisRuntime;
use Kumwe\CMS\Kernel\Configuration\RedisConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Redis;

#[CoversClass(RedisRuntime::class)]
#[CoversClass(RedisLease::class)]
final class RedisLeaseIntegrationTest extends TestCase
{
    private Redis $redis;
    private RedisRuntime $runtime;
    private string $leaseName;

    protected function setUp(): void
    {
        $host = getenv('REDIS_HOST');
        $port = getenv('REDIS_PORT');
        $database = getenv('REDIS_DATABASE');
        $namespace = getenv('REDIS_NAMESPACE');
        $password = getenv('REDIS_PASSWORD');
        $configuration = new RedisConfiguration(
            is_string($host) && $host !== '' ? $host : '127.0.0.1',
            is_string($port) && ctype_digit($port) ? (int) $port : 6379,
            is_string($password) && $password !== '' ? $password : null,
            is_string($database) && ctype_digit($database) ? (int) $database : 0,
            is_string($namespace) && $namespace !== '' ? $namespace : 'kumwe.test',
        );
        $this->redis = (new RedisConnectionFactory($configuration))->create();
        $this->runtime = new RedisRuntime($this->redis);
        $this->leaseName = 'extension-registry-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->redis->del('lock:' . $this->leaseName);
        $this->redis->close();
    }

    public function testLeaseRenewsAndExcludesConcurrentHolder(): void
    {
        $first = $this->runtime->acquireLease($this->leaseName, 30);
        self::assertInstanceOf(RedisLease::class, $first);
        self::assertNull($this->runtime->acquireLease($this->leaseName, 30));

        $first->renew();
        self::assertGreaterThan(0, $this->redis->ttl('lock:' . $this->leaseName));
    }

    public function testExpiredHolderCannotReleaseOrRenewNewerLease(): void
    {
        $first = $this->runtime->acquireLease($this->leaseName, 30);
        self::assertInstanceOf(RedisLease::class, $first);
        $this->redis->del('lock:' . $this->leaseName);
        $second = $this->runtime->acquireLease($this->leaseName, 30);
        self::assertInstanceOf(RedisLease::class, $second);

        $first->release();
        self::assertNull($this->runtime->acquireLease($this->leaseName, 30));
        $this->expectException(\RuntimeException::class);

        $first->renew();
    }
}
