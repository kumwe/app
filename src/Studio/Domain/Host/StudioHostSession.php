<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Host;

use InvalidArgumentException;

/**
 * Immutable server-side binding behind an opaque Studio resource-context key.
 *
 * Identity, site and membership coordinates are copied only from an authenticated execution context.
 * None are accepted through a Studio host request. The stored generation records the authority and
 * capability snapshot at open time, so the application boundary can reject every later stale call.
 *
 * @since  2.0.0
 */
final readonly class StudioHostSession
{
    /**
     * Capture one verified host session binding.
     *
     * @param   string              $resourceContextKey  Opaque canonical stable identifier.
     * @param   string              $actorId             Trusted authenticated actor identifier.
     * @param   string              $siteId              Trusted execution-site identifier.
     * @param   string|null         $organizationId      Trusted active organization, when selected.
     * @param   string|null         $workspaceId         Trusted active workspace, when selected.
     * @param   string              $surface             Trusted authenticated surface.
     * @param   string              $sessionBinding      SHA-256 binding to the authenticated host session.
     * @param   StudioSessionMode   $mode                Exact authorized authoring mode.
     * @param   StudioResourceKind  $resourceKind        Content or Blueprint resource family.
     * @param   string              $resourceId          Host resource identity, never returned by an error.
     * @param   string              $sessionGeneration   Authority-bound canonical revision.
     *
     * @throws  InvalidArgumentException  When persisted session data is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $resourceContextKey,
        public string $actorId,
        public string $siteId,
        public ?string $organizationId,
        public ?string $workspaceId,
        public string $surface,
        public string $sessionBinding,
        public StudioSessionMode $mode,
        public StudioResourceKind $resourceKind,
        public string $resourceId,
        public string $sessionGeneration,
    ) {
        self::stableId($resourceContextKey, 240, 'resource-context key');
        self::bounded($actorId, 191, 'actor');
        self::bounded($siteId, 191, 'site');
        self::nullableBounded($organizationId, 191, 'organization');
        self::nullableBounded($workspaceId, 191, 'workspace');
        self::bounded($surface, 63, 'surface');
        if (preg_match('/^[a-f0-9]{64}$/D', $sessionBinding) !== 1) {
            throw new InvalidArgumentException('The Studio host-session binding is invalid.');
        }
        self::bounded($resourceId, 191, 'resource');
        self::bounded($sessionGeneration, 200, 'session generation');
        if ($workspaceId !== null && $organizationId === null) {
            throw new InvalidArgumentException('A Studio workspace binding requires an organization binding.');
        }
    }

    /**
     * Validate an optional persisted scope coordinate when it is present.
     *
     * @param   string|null  $value    Candidate identifier, or null when the scope is absent.
     * @param   int          $maximum  Maximum stored byte length.
     * @param   string       $name     Safe coordinate name used in a refusal.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a present value is empty, overlong or contains control bytes.
     *
     * @since   2.0.0
     */
    private static function nullableBounded(?string $value, int $maximum, string $name): void
    {
        if ($value !== null) {
            self::bounded($value, $maximum, $name);
        }
    }

    /**
     * Validate one required persisted session coordinate.
     *
     * @param   string  $value    Candidate identifier.
     * @param   int     $maximum  Maximum stored byte length.
     * @param   string  $name     Safe coordinate name used in a refusal.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is empty, overlong or contains control bytes.
     *
     * @since   2.0.0
     */
    private static function bounded(string $value, int $maximum, string $name): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The Studio %s identifier is invalid.', $name));
        }
    }

    /**
     * Apply the protocol's stable-identifier grammar to an opaque context key.
     *
     * @param   string  $value    Candidate canonical stable identifier.
     * @param   int     $maximum  Maximum protocol byte length.
     * @param   string  $name     Safe value name used in a refusal.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is outside the canonical stable-ID profile.
     *
     * @since   2.0.0
     */
    private static function stableId(string $value, int $maximum, string $name): void
    {
        if (
            strlen($value) > $maximum
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $value) !== 1
            || in_array($value, ['__proto__', 'prototype', 'constructor'], true)
        ) {
            throw new InvalidArgumentException(sprintf('The Studio %s is invalid.', $name));
        }
    }
}
