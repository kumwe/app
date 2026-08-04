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
                host: $environment->string('DB_HOST'),
                port: $environment->positiveInteger('DB_PORT', 5432),
                database: $environment->string('DB_NAME'),
                user: $environment->string('DB_USER'),
                password: $environment->string('DB_PASSWORD'),
                schema: $environment->string('DB_SCHEMA', 'kumwe'),
                sslMode: $environment->string('DB_SSLMODE', 'require'),
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
