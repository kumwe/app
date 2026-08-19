<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Infrastructure;

use Kumwe\App\Infrastructure\Redis\RedisConnectionFactory;
use Kumwe\App\Infrastructure\Redis\RedisLease;
use Kumwe\App\Infrastructure\Redis\RedisRuntime;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Kernel\Configuration\RedisConfiguration;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
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
        // The deployment's own Redis settings, read through the one boundary allowed to read them.
        // Raw getenv() reads nothing when configuration arrives through `.env`, so every local
        // installation fell back to one shared namespace and could meet another's keys; this is the
        // same ApplicationConfiguration the booted container shares. The drill suffix keeps this
        // class's keys out of the namespace the rest of the deployment is using.
        $upstream = (new ConfigurationFactory())->create(Environment::fromGlobals())->redis;
        $configuration = new RedisConfiguration(
            $upstream->host,
            $upstream->port,
            $upstream->password,
            $upstream->database,
            $upstream->namespace . '.lease-drill',
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
