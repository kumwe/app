<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Preview;

use InvalidArgumentException;
use stdClass;

/**
 * Exact render identity carried by the pinned Studio preview protocol.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewRenderRequest
{
    /**
     * Capture one schema-shaped render payload without adding host authority to it.
     *
     * @param   string  $artifactId     Stable Blueprint identifier.
     * @param   string  $draftDigest    SHA-256 of the canonical unpublished draft.
     * @param   string  $draftRevision  Exact immutable draft revision.
     * @param   string  $requestId      Session-unique render-attempt identifier.
     * @param   string  $viewport       Declared Studio viewport role.
     *
     * @throws  InvalidArgumentException  When any member falls outside the pinned lexical profile.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $artifactId,
        public string $draftDigest,
        public string $draftRevision,
        public string $requestId,
        public string $viewport,
    ) {
        self::stableId($artifactId, 'artifact');
        self::stableId($requestId, 'request');
        self::bounded($draftRevision, 200, 'draft revision');
        if (preg_match('/^[a-f0-9]{64}$/D', $draftDigest) !== 1) {
            throw new InvalidArgumentException('The Studio preview draft digest is invalid.');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,62}$/D', $viewport) !== 1) {
            throw new InvalidArgumentException('The Studio preview viewport is invalid.');
        }
    }

    /**
     * Decode the exact protocol payload and reject aliases or extra members.
     *
     * @param   mixed  $payload  Candidate `preview.render` payload.
     *
     * @return  self  Validated immutable render identity.
     *
     * @throws  InvalidArgumentException  When the payload is not the closed five-member object.
     *
     * @since   2.0.0
     */
    public static function fromPayload(mixed $payload): self
    {
        $members = $payload instanceof stdClass ? array_keys(get_object_vars($payload)) : [];
        sort($members, SORT_STRING);
        if (
            !$payload instanceof stdClass
            || $members !== ['artifactId', 'draftDigest', 'draftRevision', 'requestId', 'viewport']
            || !is_string($payload->artifactId)
            || !is_string($payload->draftDigest)
            || !is_string($payload->draftRevision)
            || !is_string($payload->requestId)
            || !is_string($payload->viewport)
        ) {
            throw new InvalidArgumentException('The Studio preview render payload is invalid.');
        }

        return new self(
            $payload->artifactId,
            $payload->draftDigest,
            $payload->draftRevision,
            $payload->requestId,
            $payload->viewport,
        );
    }

    /**
     * Validate a canonical stable identifier from the pinned common schema.
     *
     * @param   string  $value  Candidate identifier.
     * @param   string  $name   Safe member name for an exception.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is malformed or forbidden.
     *
     * @since   2.0.0
     */
    private static function stableId(string $value, string $name): void
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,239}$/D', $value) !== 1
            || in_array($value, ['__proto__', 'prototype', 'constructor'], true)
        ) {
            throw new InvalidArgumentException(sprintf('The Studio preview %s identifier is invalid.', $name));
        }
    }

    /**
     * Validate a non-empty bounded protocol revision.
     *
     * @param   string  $value    Candidate revision.
     * @param   int     $maximum  Maximum byte count.
     * @param   string  $name     Safe member name for an exception.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is empty, overlong or contains controls.
     *
     * @since   2.0.0
     */
    private static function bounded(string $value, int $maximum, string $name): void
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The Studio preview %s is invalid.', $name));
        }
    }
}
