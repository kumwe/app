<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain\StepUp;

use InvalidArgumentException;

/**
 * Server-resolved security context one high-impact operation asks a second factor to prove.
 *
 * Delivery code must build this from the authenticated session and resolved membership, never from
 * hidden form fields. Keeping every coordinate here makes the eventual proof unusable after a
 * session, authorization epoch, site, organization, workspace, or purpose changes.
 *
 * @since  2.0.0
 */
final readonly class StepUpIntent
{
    /**
     * Validate the context a successful challenge will be bound to.
     *
     * @param   string   $subjectId               UUID of the authenticated human actor.
     * @param   string   $sessionId               UUID of the browser session being elevated.
     * @param   string   $siteIdentifier          Server-resolved site identifier.
     * @param   ?string  $organizationIdentifier  Server-resolved organization, or null outside one.
     * @param   ?string  $workspaceIdentifier     Server-resolved workspace, or null outside one.
     * @param   string   $purpose                 Narrow machine token naming the protected operation.
     * @param   int      $securityEpoch           Current authorization epoch of the actor.
     *
     * @throws  InvalidArgumentException  When an identifier, purpose, or epoch is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $subjectId,
        public string $sessionId,
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public ?string $workspaceIdentifier,
        public string $purpose,
        public int $securityEpoch,
    ) {
        self::assertUuid($subjectId, 'subject');
        self::assertUuid($sessionId, 'session');
        self::assertOpaqueIdentifier($siteIdentifier, 'site');
        if ($organizationIdentifier !== null) {
            self::assertOpaqueIdentifier($organizationIdentifier, 'organization');
        }
        if ($workspaceIdentifier !== null) {
            self::assertOpaqueIdentifier($workspaceIdentifier, 'workspace');
        }
        if ($workspaceIdentifier !== null && $organizationIdentifier === null) {
            throw new InvalidArgumentException('A step-up workspace requires an organization binding.');
        }
        if (preg_match('/^[a-z][a-z0-9._:-]{0,126}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException('A step-up purpose must be a bounded machine token.');
        }
        if ($securityEpoch < 1) {
            throw new InvalidArgumentException('A step-up security epoch must be positive.');
        }
    }

    /**
     * Validate a canonical UUID.
     *
     * @param   string  $value  Candidate UUID.
     * @param   string  $field  Field named in a rejection.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a canonical UUID.
     *
     * @since   2.0.0
     */
    private static function assertUuid(string $value, string $field): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The step-up %s identifier must be a canonical UUID.', $field));
        }
    }

    /**
     * Validate a bounded server-owned scope identifier.
     *
     * @param   string  $value  Candidate identifier.
     * @param   string  $field  Field named in a rejection.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is malformed.
     *
     * @since   2.0.0
     */
    private static function assertOpaqueIdentifier(string $value, string $field): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The step-up %s identifier is invalid.', $field));
        }
    }
}
