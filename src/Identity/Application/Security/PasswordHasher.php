<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Security;

/**
 * Contract for turning a plaintext password into a stored hash and checking one against it.
 *
 * Everything in Kumwe that handles a password depends on this port rather than on `password_hash()`
 * directly — `AccessControlService` when it creates a user, `DoctrineAdministratorIdentityGateway`
 * when it signs one in, `DoctrineThemeActivationGuard` and `DoctrineHighImpactCredentialGuard` when
 * they demand a step-up — so the algorithm and its cost parameters are chosen once, where the
 * implementation is wired, and changed without touching any of them. An implementation owes three
 * things: a self-describing hash that differs on every call, a comparison that does not leak the
 * answer through its running time, and an honest report of when a stored hash predates the parameters
 * currently in force. Plaintext arguments are marked `#[\SensitiveParameter]` so a stack trace or a
 * serialized argument list never carries one.
 *
 * @since  2.0.0
 */
interface PasswordHasher
{
    /**
     * Hash a plaintext password into the opaque string that is safe to store.
     *
     * The result embeds its own salt and parameters, so two calls with the same password return
     * different strings and a stored hash must never be compared with `===`. An implementation may
     * refuse a password it cannot hash faithfully rather than silently truncating it.
     *
     * @param   string  $plainTextPassword  Password as the user typed it; only its hash is stored.
     *
     * @return  string  Self-describing hash to persist, carrying its salt and cost parameters.
     *
     * @since   2.0.0
     */
    public function hash(#[\SensitiveParameter] string $plainTextPassword): string;

    /**
     * Check a plaintext password against a stored hash without leaking the answer through timing.
     *
     * Input that cannot possibly match — an empty password, an empty or unparsable stored hash — is
     * answered false rather than raised, so a sign-in path treats a damaged stored credential as one
     * more failed attempt instead of an error the caller has to distinguish.
     *
     * @param   string  $plainTextPassword  Password presented by the caller for this attempt.
     * @param   string  $passwordHash       Stored hash to check it against, as produced by `hash()`.
     *
     * @return  bool  True only when the presented password produced the stored hash.
     *
     * @since   2.0.0
     */
    public function verify(#[\SensitiveParameter] string $plainTextPassword, string $passwordHash): bool;

    /**
     * Report whether a stored hash should be replaced with a freshly computed one.
     *
     * Meant to be asked straight after a successful `verify()`, while the plaintext is still in hand:
     * a true answer means the stored hash was produced under an algorithm or cost the installation has
     * since moved off, and the account can be re-hashed transparently before the plaintext is dropped.
     *
     * @param   string  $passwordHash  Stored hash to judge against the parameters in force now.
     *
     * @return  bool  True when the hash is stale and worth recomputing.
     *
     * @since   2.0.0
     */
    public function needsRehash(string $passwordHash): bool;
}
