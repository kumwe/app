<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Security;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use RuntimeException;

final readonly class NativePasswordHasher implements PasswordHasher
{
    /** @var string|int */
    private string|int $algorithm;

    /** @var array<string, int> */
    private array $options;

    /**
     * @param string|int|null $algorithm One of PHP's PASSWORD_* algorithm constants.
     * @param array<string, int>|null $options
     */
    public function __construct(
        string|int|null $algorithm = null,
        ?array $options = null,
    ) {
        $argon2idAvailable = in_array('argon2id', password_algos(), true);
        $this->algorithm = $algorithm ?? ($argon2idAvailable ? 'argon2id' : PASSWORD_DEFAULT);
        $this->options = $options ?? ($argon2idAvailable && $algorithm === null ? [
            'memory_cost' => 65_536,
            'time_cost' => 4,
            'threads' => 2,
        ] : []);
    }

    public function hash(#[\SensitiveParameter] string $plainTextPassword): string
    {
        if ($plainTextPassword === '') {
            throw new InvalidArgumentException('A password cannot be empty.');
        }

        if (strlen($plainTextPassword) > 4_096) {
            throw new InvalidArgumentException('A password cannot exceed 4096 bytes.');
        }

        if ($this->usesBcrypt() && strlen($plainTextPassword) > 72) {
            throw new InvalidArgumentException('A bcrypt password cannot exceed 72 bytes.');
        }

        $hash = password_hash($plainTextPassword, $this->algorithm, $this->options);

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('PHP was unable to hash the password.');
        }

        return $hash;
    }

    public function verify(#[\SensitiveParameter] string $plainTextPassword, string $passwordHash): bool
    {
        if ($plainTextPassword === '' || strlen($plainTextPassword) > 4_096 || $passwordHash === '') {
            return false;
        }

        if ($this->isBcryptHash($passwordHash) && strlen($plainTextPassword) > 72) {
            return false;
        }

        return password_verify($plainTextPassword, $passwordHash);
    }

    public function needsRehash(string $passwordHash): bool
    {
        return $passwordHash === '' || password_needs_rehash($passwordHash, $this->algorithm, $this->options);
    }

    private function usesBcrypt(): bool
    {
        return $this->algorithm === PASSWORD_BCRYPT || $this->algorithm === '2y';
    }

    private function isBcryptHash(string $passwordHash): bool
    {
        $information = password_get_info($passwordHash);

        return ($information['algoName'] ?? null) === 'bcrypt';
    }
}
