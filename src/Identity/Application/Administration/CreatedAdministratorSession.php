<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

/**
 * A freshly opened administrator session paired with the one cookie token that will ever name it.
 *
 * `AdministratorSessionStore::create()` returns this and nothing else carries the token: the store
 * persists only its SHA-256 digest, so the value here cannot be recovered afterwards and this object is
 * the sole opportunity to hand it to the browser. The login handler reads `$token` straight into the
 * `Set-Cookie` header; `$session` is the same object every later request resolves that cookie back to,
 * and holds no resumable credential of its own.
 *
 * @since  2.0.0
 */
final readonly class CreatedAdministratorSession
{
    /**
     * Pair a stored session with the token issued for it.
     *
     * @param  string                $token    Opaque cookie token, seen once here and never again.
     * @param  AdministratorSession  $session  The stored session that token resolves to on later requests.
     *
     * @since  2.0.0
     */
    public function __construct(public string $token, public AdministratorSession $session)
    {
    }
}
