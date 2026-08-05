<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Kernel\Configuration;

use InvalidArgumentException;
use Kumwe\CMS\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\CMS\Kernel\Configuration\ConfigurationFactory;
use Kumwe\CMS\Kernel\Configuration\DatabaseConfiguration;
use Kumwe\CMS\Kernel\Configuration\RuntimeEnvironment;
use Kumwe\CMS\Kernel\Configuration\RedisConfiguration;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationFactory::class)]
#[CoversClass(ApplicationConfiguration::class)]
#[CoversClass(DatabaseConfiguration::class)]
#[CoversClass(RedisConfiguration::class)]
#[CoversClass(RuntimeEnvironment::class)]
final class ConfigurationFactoryTest extends TestCase
{
    public function testCreatesProductionConfiguration(): void
    {
        $configuration = (new ConfigurationFactory())->create(new Environment($this->values()));

        self::assertSame(RuntimeEnvironment::Production, $configuration->environment);
        self::assertTrue($configuration->isProduction());
        self::assertSame(['kumwe.test'], $configuration->trustedHosts);
        self::assertSame('default', $configuration->publicSite);
        self::assertSame('kumwe_', $configuration->database->tablePrefix);
        self::assertSame('pgsql', $configuration->database->driver);
        self::assertSame('redis', $configuration->redis->host);
        self::assertSame('kumwe.cms', $configuration->redis->namespace);
    }

    public function testProductionRequiresHttps(): void
    {
        $values = $this->values();
        $values['APP_BASE_URL'] = 'http://kumwe.test';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testSecretMustContainAtLeastThirtyTwoBytes(): void
    {
        $values = $this->values();
        $values['APP_SECRET'] = 'too-short';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testTrustedProxyRangesAreValidatedDuringConfiguration(): void
    {
        $values = $this->values();
        $values['APP_TRUSTED_PROXIES'] = '10.0.0.0/8,2001:db8::/129';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testProductionRequiresIndependentRuntimeSigningKey(): void
    {
        $values = $this->values();
        unset($values['EXTENSION_RUNTIME_SIGNING_KEY']);
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testRuntimeSigningKeyCannotReuseApplicationSecret(): void
    {
        $values = $this->values();
        $values['EXTENSION_RUNTIME_SIGNING_KEY'] = $values['APP_SECRET'];
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testRuntimeProcessIdentityMustBeStableIdentifier(): void
    {
        $values = $this->values();
        $values['KUMWE_PROCESS_ID'] = 'random process request';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testPublicSiteMustBeCanonicalIdentifier(): void
    {
        $values = $this->values();
        $values['APP_PUBLIC_SITE'] = 'Invalid Site';
        $this->expectException(InvalidArgumentException::class);

        (new ConfigurationFactory())->create(new Environment($values));
    }

    public function testPreviousRuntimeKeyRingCanBeReadFromProtectedFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kumwe-runtime-keys-');
        self::assertIsString($file);
        file_put_contents($file, json_encode(['runtime-v0' => str_repeat('p', 32)], JSON_THROW_ON_ERROR));
        try {
            $values = $this->values();
            $values['EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE'] = $file;
            $configuration = (new ConfigurationFactory())->create(new Environment($values));

            self::assertSame(['runtime-v0' => str_repeat('p', 32)], $configuration->runtimePreviousSigningKeys);
        } finally {
            unlink($file);
        }
    }
    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_BASE_URL' => 'https://kumwe.test',
            'APP_TRUSTED_HOSTS' => 'kumwe.test',
            'APP_SECRET' => str_repeat('a', 32),
            'EXTENSION_RUNTIME_SIGNING_KEY' => str_repeat('r', 32),
            'KUMWE_DEPLOYMENT_ID' => 'deployment-2026-08-05',
            'KUMWE_REPLICA_ID' => 'replica-one',
            'KUMWE_PROCESS_ID' => 'app-runtime',
            'KUMWE_INSTANCE_ID' => 'instance-one',
            'DB_HOST' => 'postgres',
            'DB_DRIVER' => 'pgsql',
            'DB_PORT' => '5432',
            'DB_NAME' => 'kumwe',
            'DB_USER' => 'kumwe',
            'DB_PASSWORD' => 'secret',
            'DB_TABLE_PREFIX' => 'kumwe_',
            'DB_SERVER_VERSION' => '17',
            'DB_SSLMODE' => 'require',
        ];
    }
}
