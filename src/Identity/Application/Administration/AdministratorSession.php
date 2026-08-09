<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Administration;

use DateTimeImmutable;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;

/**
 * The signed-in administrator behind one request, as resolved from the session cookie.
 *
 * `AdministratorSessionMiddleware` exchanges the cookie for this object and attaches it to the
 * request; handlers read it back through `AdministratorRequest::session()` to learn who is acting and
 * to seed the CSRF field of every form they render. It deliberately holds no reusable credential —
 * the opaque cookie token exists only in `CreatedAdministratorSession`, and only its digest is
 * stored — so passing this into a template exposes nothing that would let an attacker resume the
 * session.
 *
 * @since  2.0.0
 */
final readonly class AdministratorSession
{
    /**
     * PSR-7 request attribute the administrator session middleware stores the resolved session under.
     *
     * The class name doubles as the key, so the middleware and the handlers cannot drift apart on the
     * spelling.
     *
     * @var    string
     * @since  2.0.0
     */
    public const REQUEST_ATTRIBUTE = self::class;

    /**
     * Capture who a session signs in as and how long it stays valid.
     *
     * @param  string                  $id         UUID of the stored session row, which is also the
     *         resource identifier administrator access is authorized against.
     * @param  AuthenticatedPrincipal  $principal  Actor the session speaks for, carrying the grants and
     *         security epoch read when the session was resolved.
     * @param  string                  $csrfToken  Value every state-changing administrator form must echo
     *         back for `AdministratorCsrfMiddleware` to accept the submission.
     * @param  DateTimeImmutable       $expiresAt  Instant past which the store stops resolving the cookie.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public AuthenticatedPrincipal $principal,
        public string $csrfToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
