<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;

final readonly class ApplicationConfiguration
{
    /**
     * @param non-empty-list<string> $trustedHosts
     * @param list<string> $trustedProxies
     */
    public function __construct(
        public RuntimeEnvironment $environment,
        public bool $debug,
        public string $baseUrl,
        public array $trustedHosts,
        public array $trustedProxies,
        public int $maxBodyBytes,
        public int $administratorSessionSeconds,
        public bool $allowUnsignedLocalExtensions,
        public string $release,
        public string $secret,
        public DatabaseConfiguration $database,
        public RedisConfiguration $redis,
    ) {
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('APP_BASE_URL must contain an absolute URL.');
        }

        if ($environment === RuntimeEnvironment::Production && !str_starts_with($baseUrl, 'https://')) {
            throw new InvalidArgumentException('Production APP_BASE_URL must use HTTPS.');
        }

        if ($trustedHosts === []) {
            throw new InvalidArgumentException('At least one trusted host is required.');
        }

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('APP_SECRET must contain at least 32 bytes.');
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
