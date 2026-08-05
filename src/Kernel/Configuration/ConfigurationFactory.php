<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use JsonException;
use InvalidArgumentException;
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
        $testing = $runtime === RuntimeEnvironment::Testing;
        $runtimeKey = $environment->optionalString('EXTENSION_RUNTIME_SIGNING_KEY');
        if ($runtimeKey === null && !$testing) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_SIGNING_KEY is required outside tests.');
        }

        return new ApplicationConfiguration(
            environment: $runtime,
            debug: $environment->boolean('APP_DEBUG'),
            baseUrl: $environment->string('APP_BASE_URL'),
            publicSite: $environment->string('APP_PUBLIC_SITE', 'default'),
            trustedHosts: $this->trustedHosts($environment),
            trustedProxies: $environment->commaSeparatedList('APP_TRUSTED_PROXIES'),
            maxBodyBytes: $environment->positiveInteger('APP_MAX_BODY_BYTES', 2_097_152),
            administratorSessionSeconds: $environment->positiveInteger('APP_ADMIN_SESSION_SECONDS', 28_800),
            allowUnsignedLocalExtensions: $environment->boolean('EXTENSIONS_ALLOW_UNSIGNED_LOCAL'),
            release: $environment->string('KUMWE_RELEASE', '2.0.0-dev'),
            secret: $environment->string('APP_SECRET'),
            runtimeSigningKeyId: $environment->string('EXTENSION_RUNTIME_SIGNING_KEY_ID', 'runtime-v1'),
            runtimeSigningKey: $runtimeKey ?? str_repeat('testing-runtime-key-', 2),
            runtimePreviousSigningKeys: $this->previousRuntimeKeys($this->previousKeysPayload($environment)),
            deploymentId: $environment->string('KUMWE_DEPLOYMENT_ID', $testing ? 'testing-deployment' : null),
            replicaId: $environment->string('KUMWE_REPLICA_ID', $testing ? 'testing-replica' : null),
            processId: $environment->string('KUMWE_PROCESS_ID', $testing ? 'testing-process' : null),
            instanceId: $environment->string('KUMWE_INSTANCE_ID', $testing ? 'testing-instance' : null),
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

    /** @return array<string, string> */
    private function previousRuntimeKeys(?string $encoded): array
    {
        if ($encoded === null) {
            return [];
        }
        try {
            $keys = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS must be valid JSON.', 0, $exception);
        }
        if (!is_array($keys) || array_is_list($keys)) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS must be a JSON object.');
        }
        foreach ($keys as $keyId => $key) {
            if (!is_string($keyId) || !is_string($key)) {
                throw new InvalidArgumentException('Previous runtime signing keys must map IDs to secrets.');
            }
        }

        return $keys;
    }

    private function previousKeysPayload(Environment $environment): ?string
    {
        $encoded = $environment->optionalString('EXTENSION_RUNTIME_PREVIOUS_KEYS');
        $file = $environment->optionalString('EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE');
        if ($encoded !== null && $file !== null) {
            throw new InvalidArgumentException('Configure previous runtime keys by value or file, never both.');
        }
        if ($file === null) {
            return $encoded;
        }
        if (!str_starts_with($file, '/') || !is_file($file) || is_link($file) || !is_readable($file)) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE must be a readable regular file.');
        }
        $payload = file_get_contents($file);
        if (!is_string($payload) || trim($payload) === '') {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE is empty.');
        }

        return $payload;
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
