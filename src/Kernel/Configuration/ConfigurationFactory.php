<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use JsonException;
use InvalidArgumentException;
use ValueError;

/**
 * Turns the raw environment into the validated configuration graph the rest of the process reads.
 *
 * This is the only translation step between `Environment` and `ApplicationConfiguration`, so it owns
 * every default a deployment may omit: the engine-specific database port and server version, the
 * body and session limits, the Redis namespace, and the placeholder secrets and identities that only
 * a `Testing` runtime is allowed to fall back on. `ContainerFactory` calls it once at boot and shares
 * the result, which is why a bad variable surfaces here rather than deep inside a request.
 *
 * @since  2.0.0
 */
final class ConfigurationFactory
{
    /**
     * Assemble the application, database, and Redis configuration from the supplied environment.
     *
     * Defaults are resolved before validation, so an unset variable yields the documented fallback
     * while a variable that is set but malformed is an error. Outside a `Testing` runtime the
     * extension runtime signing key and the four deployment identities are mandatory; under it they
     * take fixed placeholder values so a test run needs no secret material.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  ApplicationConfiguration  Fully validated configuration, safe to share for the process lifetime.
     *
     * @throws  InvalidArgumentException  When a variable is missing, malformed, or fails a configuration rule.
     * @throws  ValueError  When `APP_ENV` names no known runtime, or no trusted host is configured.
     *
     * @since   2.0.0
     */
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
            siteContentProfile: $environment->string('KUMWE_SITE_CONTENT_PROFILE', 'documentation'),
            businessProfile: $this->businessProfile($environment),
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

    /**
     * Resolve the named business demonstration profile from the current and the legacy selector.
     *
     * `KUMWE_BUSINESS_PROFILE` names any discovered business dataset directly and wins when present.
     * The historical boolean `KUMWE_BUSINESS_DEMO` keeps working as an alias: enabled selects the
     * released `vdm` example and disabled selects the explicit `none` profile, so existing
     * deployments and Compose files keep their meaning without an edit.
     *
     * @param   Environment  $environment  Allow-listed variables resolved from the process and dotenv file.
     *
     * @return  string  Named business profile, or `none` for a deliberately empty business runtime.
     *
     * @since   2.0.0
     */
    private function businessProfile(Environment $environment): string
    {
        $named = $environment->optionalString('KUMWE_BUSINESS_PROFILE');
        if ($named !== null && $named !== '') {
            return $named;
        }

        return $environment->boolean('KUMWE_BUSINESS_DEMO', true) ? 'vdm' : 'none';
    }

    /**
     * Decode the retired runtime signing keys a rotation still needs to verify old artifacts with.
     *
     * The payload is a flat JSON object of key identifier to secret. Both a decode failure and a
     * structurally wrong payload are reported as a configuration error rather than being tolerated,
     * because silently dropping a retired key would make previously published runtime maps
     * unverifiable.
     *
     * @param   ?string  $encoded  JSON object of retired keys, or null when none are configured.
     *
     * @return  array<string, string>  Retired secrets keyed by identifier; empty when none are configured.
     *
     * @throws  InvalidArgumentException  When the payload is not valid JSON, is not an object, or maps
     *          something other than string identifiers to string secrets.
     *
     * @since   2.0.0
     */
    private function previousRuntimeKeys(?string $encoded): array
    {
        if ($encoded === null) {
            return [];
        }
        try {
            $decoded = json_decode($encoded, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS must be valid JSON.', 0, $exception);
        }
        if (!$decoded instanceof \stdClass) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS must be a JSON object.');
        }
        /** @var array<string, string> $keys */
        $keys = [];
        foreach (get_object_vars($decoded) as $keyId => $key) {
            if (!is_string($keyId) || !is_string($key)) {
                throw new InvalidArgumentException('Previous runtime signing keys must map IDs to secrets.');
            }
            $keys[$keyId] = $key;
        }

        return $keys;
    }

    /**
     * Resolve where the retired runtime signing keys are read from, by value or by file.
     *
     * Operators may inline the JSON in `EXTENSION_RUNTIME_PREVIOUS_KEYS` or point
     * `EXTENSION_RUNTIME_PREVIOUS_KEYS_FILE` at a mounted secret, never both, so there is no question
     * which one wins. The file must be an absolute path to a readable regular file and must not be a
     * symbolic link, which stops a writable link in the deployment tree from redirecting a secret read.
     *
     * @param   Environment  $environment  Allow-listed variables to read the two settings from.
     *
     * @return  ?string  Raw JSON payload, or null when no retired keys are configured.
     *
     * @throws  InvalidArgumentException  When both settings are supplied, the file is not a readable
     *          regular file, or the file is blank.
     *
     * @since   2.0.0
     */
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
     * Read the host names the application is permitted to answer to.
     *
     * An empty list is refused rather than defaulted, because a deployment with no host allow-list
     * would accept any `Host` header and open the door to host-header poisoning.
     *
     * @param   Environment  $environment  Allow-listed variables to read `APP_TRUSTED_HOSTS` from.
     *
     * @return  non-empty-list<string>  Configured host names in declaration order, never empty.
     *
     * @throws  ValueError  When `APP_TRUSTED_HOSTS` is unset or contains no usable entry.
     *
     * @since   2.0.0
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
