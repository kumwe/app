<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use RuntimeException;

final readonly class RuntimePublicationKeyRing
{
    /** @var array<string, string> */
    private array $keys;

    /** @param array<string, string> $previousKeys */
    public function __construct(
        public string $activeKeyId,
        #[\SensitiveParameter] string $activeKey,
        #[\SensitiveParameter] array $previousKeys = [],
    ) {
        $keys = $previousKeys;
        $keys[$activeKeyId] = $activeKey;
        foreach ($keys as $keyId => $key) {
            if (
                preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1
                || !is_string($key)
                || strlen($key) < 32
            ) {
                throw new InvalidArgumentException('Runtime publication signing keys are invalid.');
            }
        }
        if (count($keys) !== count($previousKeys) + 1) {
            throw new InvalidArgumentException('The active runtime signing key cannot also be a previous key.');
        }

        $this->keys = $keys;
    }

    public function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->keys[$this->activeKeyId]);
    }

    public function cacheIdentity(): string
    {
        $identity = [];
        foreach ($this->keys as $keyId => $key) {
            $identity[$keyId] = hash('sha256', $key);
        }
        ksort($identity, SORT_STRING);

        return hash('sha256', RuntimeCanonicalJson::encode($identity));
    }

    public function assertSignature(string $keyId, string $payload, string $signature): void
    {
        $key = $this->keys[$keyId] ?? null;
        if (!is_string($key) || !hash_equals(hash_hmac('sha256', $payload, $key), $signature)) {
            throw new RuntimeException('The runtime publication signature is invalid or uses an unavailable key.');
        }
    }
}
