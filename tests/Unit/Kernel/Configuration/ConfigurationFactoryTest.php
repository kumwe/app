<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Kernel\Configuration;

use InvalidArgumentException;
use Kumwe\CMS\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\CMS\Kernel\Configuration\ConfigurationFactory;
use Kumwe\CMS\Kernel\Configuration\DatabaseConfiguration;
use Kumwe\CMS\Kernel\Configuration\RuntimeEnvironment;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurationFactory::class)]
#[CoversClass(ApplicationConfiguration::class)]
#[CoversClass(DatabaseConfiguration::class)]
#[CoversClass(RuntimeEnvironment::class)]
final class ConfigurationFactoryTest extends TestCase
{
    public function testCreatesProductionConfiguration(): void
    {
        $configuration = (new ConfigurationFactory())->create(new Environment($this->values()));

        self::assertSame(RuntimeEnvironment::Production, $configuration->environment);
        self::assertTrue($configuration->isProduction());
        self::assertSame(['kumwe.test'], $configuration->trustedHosts);
        self::assertSame('kumwe', $configuration->database->schema);
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
            'APP_SECRET' => '0123456789abcdef0123456789abcdef',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_NAME' => 'kumwe',
            'DB_USER' => 'kumwe',
            'DB_PASSWORD' => 'secret',
            'DB_SCHEMA' => 'kumwe',
            'DB_SSLMODE' => 'require',
        ];
    }
}
