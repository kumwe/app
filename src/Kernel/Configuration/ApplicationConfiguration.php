<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Http\Security\TrustedProxyMatcher;

final readonly class ApplicationConfiguration
{
    /**
     * @param non-empty-list<string> $trustedHosts
     * @param list<string> $trustedProxies
     * @param array<string, string> $runtimePreviousSigningKeys
     */
    public function __construct(
        public RuntimeEnvironment $environment,
        public bool $debug,
        public string $baseUrl,
        public string $publicSite,
        public array $trustedHosts,
        public array $trustedProxies,
        public int $maxBodyBytes,
        public int $administratorSessionSeconds,
        public bool $allowUnsignedLocalExtensions,
        public string $release,
        public string $secret,
        public string $runtimeSigningKeyId,
        public string $runtimeSigningKey,
        public array $runtimePreviousSigningKeys,
        public string $deploymentId,
        public string $replicaId,
        public string $processId,
        public string $instanceId,
        public DatabaseConfiguration $database,
        public RedisConfiguration $redis,
    ) {
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('APP_BASE_URL must contain an absolute URL.');
        }

        if ($environment === RuntimeEnvironment::Production && !str_starts_with($baseUrl, 'https://')) {
            throw new InvalidArgumentException('Production APP_BASE_URL must use HTTPS.');
        }
        SiteContext::fromString($publicSite);

        if ($trustedHosts === []) {
            throw new InvalidArgumentException('At least one trusted host is required.');
        }

        // Fail during bootstrap rather than silently running with a malformed trust boundary.
        new TrustedProxyMatcher($trustedProxies);

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('APP_SECRET must contain at least 32 bytes.');
        }
        if (strlen($runtimeSigningKey) < 32) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_SIGNING_KEY must contain at least 32 bytes.');
        }
        if (hash_equals($secret, $runtimeSigningKey)) {
            throw new InvalidArgumentException('The runtime publication key must be independent from APP_SECRET.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $runtimeSigningKeyId) !== 1) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_SIGNING_KEY_ID is invalid.');
        }
        foreach ($runtimePreviousSigningKeys as $keyId => $key) {
            if (
                !is_string($keyId)
                || preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1
                || !is_string($key)
                || strlen($key) < 32
                || hash_equals($runtimeSigningKeyId, $keyId)
            ) {
                throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS is invalid.');
            }
        }
        foreach ([$deploymentId, $replicaId, $processId, $instanceId] as $identity) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,126}[A-Za-z0-9]$/D', $identity) !== 1) {
                throw new InvalidArgumentException('Runtime deployment identities are invalid.');
            }
        }

        if ($administratorSessionSeconds < 300 || $administratorSessionSeconds > 604_800) {
            throw new InvalidArgumentException(
                'APP_ADMIN_SESSION_SECONDS must be between 300 and 604800 seconds.',
            );
        }
    }

    public function isProduction(): bool
    {
        return $this->environment === RuntimeEnvironment::Production;
    }
}
