<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use ValueError;

final class ConfigurationFactory
{
    public function create(Environment $environment): ApplicationConfiguration
    {
        $runtime = RuntimeEnvironment::from($environment->string('APP_ENV', 'production'));
        $databaseDriver = strtolower($environment->string('DB_DRIVER', 'mariadb'));
        $defaultPort = $databaseDriver === 'pgsql' ? 5432 : 3306;
        $defaultServerVersion = match ($databaseDriver) {
            'pgsql' => '17',
            'mysql' => '8.4',
            'mariadb' => 'mariadb-12.3.2',
            default => '',
        };

        return new ApplicationConfiguration(
            environment: $runtime,
            debug: $environment->boolean('APP_DEBUG'),
            baseUrl: $environment->string('APP_BASE_URL'),
            trustedHosts: $this->trustedHosts($environment),
            trustedProxies: $environment->commaSeparatedList('APP_TRUSTED_PROXIES'),
            maxBodyBytes: $environment->positiveInteger('APP_MAX_BODY_BYTES', 2_097_152),
            administratorSessionSeconds: $environment->positiveInteger('APP_ADMIN_SESSION_SECONDS', 28_800),
            allowUnsignedLocalExtensions: $environment->boolean('EXTENSIONS_ALLOW_UNSIGNED_LOCAL'),
            release: $environment->string('KUMWE_RELEASE', '2.0.0-dev'),
            secret: $environment->string('APP_SECRET'),
            database: new DatabaseConfiguration(
                driver: $databaseDriver,
                host: $environment->string('DB_HOST'),
                port: $environment->positiveInteger('DB_PORT', $defaultPort),
                database: $environment->string('DB_NAME'),
                user: $environment->string('DB_USER'),
                password: $environment->string('DB_PASSWORD'),
                tablePrefix: $environment->string('DB_TABLE_PREFIX', 'kumwe_'),
                sslMode: $environment->string('DB_SSLMODE', 'require'),
                serverVersion: $environment->string('DB_SERVER_VERSION', $defaultServerVersion),
            ),
            redis: new RedisConfiguration(
                host: $environment->string('REDIS_HOST', 'redis'),
                port: $environment->positiveInteger('REDIS_PORT', 6379),
                password: $environment->optionalString('REDIS_PASSWORD'),
                database: $environment->nonNegativeInteger('REDIS_DATABASE', 0, 15),
                namespace: $environment->string('REDIS_NAMESPACE', 'kumwe.cms'),
            ),
        );
    }

    /**
     * @return non-empty-list<string>
     */
    private function trustedHosts(Environment $environment): array
    {
        $hosts = $environment->commaSeparatedList('APP_TRUSTED_HOSTS');

        if ($hosts === []) {
            throw new ValueError('APP_TRUSTED_HOSTS must contain at least one host.');
        }

        return $hosts;
    }
}
