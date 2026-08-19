<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Infrastructure\Security;

use InvalidArgumentException;
use Kumwe\App\Identity\Application\Security\PasswordHasher;

/**
 * The `PasswordHasher` an installation runs on, wrapping PHP's native `password_*` functions.
 *
 * `ContainerFactory` shares one instance across everything that stores or checks a password, so the
 * algorithm and its cost are decided in a single place. Left to itself this hasher picks Argon2id
 * wherever the runtime offers it, with memory, time and thread costs fixed up front, and falls back to
 * PHP's own default — bcrypt on current runtimes — on a build without Argon2id.
 *
 * On top of the native functions it adds the length rules those functions lack. An empty password is
 * refused, and so is anything past 4096 bytes, which bounds the work a single call can buy. A password
 * longer than bcrypt's 72-byte input limit is refused outright rather than silently truncated, and
 * `verify()` applies that same limit to any stored bcrypt hash — which is what stops a wrong password
 * sharing only its first 72 bytes with the real one from being accepted.
 *
 * @since  2.0.0
 */
final readonly class NativePasswordHasher implements PasswordHasher
{
    /**
     * Algorithm every hash produced here is computed under, spelled as `password_hash()` names it.
     *
     * @var    string|int
     * @since  2.0.0
     */
    private string|int $algorithm;

    /**
     * Cost parameters passed alongside that algorithm; empty leaves PHP's own defaults in force.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    private array $options;

    /**
     * Settle the algorithm and cost this instance hashes under, preferring Argon2id.
     *
     * Passing nothing is the intended use and selects Argon2id with deliberate costs wherever the
     * runtime has it. Naming an algorithm suppresses those cost defaults entirely, so a caller that
     * wants Argon2id at a different cost must pass the options with it. Nothing here checks that the
     * runtime actually offers a named algorithm, so a name it does not know surfaces only on the first
     * call to `hash()`.
     *
     * @param  string|int|null          $algorithm  One of PHP's PASSWORD_* algorithm constants.
     * @param  array<string, int>|null  $options    Explicit costs, replacing the defaults chosen here.
     *
     * @since  2.0.0
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

    /**
     * Hash a plaintext password under this instance's algorithm and cost.
     *
     * Refuses rather than truncates: a password past 4096 bytes is rejected whatever the algorithm, and
     * a bcrypt configuration rejects anything past bcrypt's 72-byte input limit instead of hashing only
     * the first 72 bytes of it. The result carries its own salt, so two calls with the same password
     * return different strings.
     *
     * @param   string  $plainTextPassword  Password as the user typed it; only its hash leaves here.
     *
     * @return  string  Self-describing hash to persist, carrying its algorithm, cost and salt.
     *
     * @throws  InvalidArgumentException  When the password is empty, exceeds 4096 bytes, or exceeds 72
     *          bytes while this instance is configured for bcrypt.
     *
     * @since   2.0.0
     */
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

        return $hash;
    }

    /**
     * Check a plaintext password against a stored hash, without raising on unusable input.
     *
     * Input this hasher would never have produced is answered false before the hash is consulted: an
     * empty password, a password past 4096 bytes, an empty stored hash, and — where the stored hash is
     * itself bcrypt — a password past 72 bytes, which bcrypt would otherwise truncate into a match.
     * Everything else is decided by `password_verify()`, which compares in constant time and reads the
     * algorithm off the stored hash, so a credential written under an earlier algorithm still verifies.
     *
     * @param   string  $plainTextPassword  Password presented for this attempt.
     * @param   string  $passwordHash       Stored hash to check it against, in any format PHP can read.
     *
     * @return  bool  True only when the presented password produced this hash.
     *
     * @since   2.0.0
     */
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

    /**
     * Report whether a stored hash was produced under something other than the parameters in force now.
     *
     * Meant to be asked straight after a successful `verify()`, while the plaintext is still in hand to
     * recompute with. An empty stored hash answers true rather than raising, so a missing or damaged
     * credential is routed into the same re-hash path as a merely outdated one.
     *
     * @param   string  $passwordHash  Stored hash to judge against this instance's algorithm and cost.
     *
     * @return  bool  True when the hash should be recomputed.
     *
     * @since   2.0.0
     */
    public function needsRehash(string $passwordHash): bool
    {
        return $passwordHash === '' || password_needs_rehash($passwordHash, $this->algorithm, $this->options);
    }

    /**
     * Whether this instance hashes with bcrypt, and so is bound by its 72-byte input limit.
     *
     * True both when a caller named bcrypt and when the Argon2id preference fell through to PHP's
     * default, which resolves to bcrypt on current runtimes, so the limit is enforced in either case.
     *
     * @return  bool  True when the configured algorithm is bcrypt.
     *
     * @since   2.0.0
     */
    private function usesBcrypt(): bool
    {
        return $this->algorithm === PASSWORD_BCRYPT || $this->algorithm === '2y';
    }

    /**
     * Whether an already stored hash was produced by bcrypt, whatever this instance is configured for.
     *
     * Read off the hash itself rather than off the configuration, so `verify()` keeps applying the
     * 72-byte rule to credentials written before the installation moved to Argon2id. A string PHP
     * cannot identify reports false and is left for `password_verify()` to reject.
     *
     * @param   string  $passwordHash  Stored hash to identify.
     *
     * @return  bool  True when PHP names the hash's algorithm `bcrypt`.
     *
     * @since   2.0.0
     */
    private function isBcryptHash(string $passwordHash): bool
    {
        $information = password_get_info($passwordHash);

        return $information['algoName'] === 'bcrypt';
    }
}
