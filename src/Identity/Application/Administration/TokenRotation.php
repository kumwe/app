<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use DateTimeImmutable;

/**
 * Everything a replacement API token must inherit from the token it supersedes, once rotation is cleared.
 *
 * `TokenRotationPreauthorizer::authorize()` is the only producer, and it reads these fields from the
 * stored token rather than from the request. `DoctrineAdministratorIdentityGateway::rotateAccessToken()`
 * then issues from this object, so a rotation can only ever change a token's name and expiry: the
 * subject, site, audience, purpose and capability set travel across unchanged, and a caller cannot widen
 * a token's authority by rotating it.
 *
 * @since  2.0.0
 */
final readonly class TokenRotation
{
    /**
     * Capture the identity and scope the replacement token must be minted with.
     *
     * @param  string                  $subjectId          UUID the replacement authenticates as, carried over
     *         from the superseded token.
     * @param  string                  $email              Subject's email, re-used to re-run the delegation
     *         check while the replacement is written.
     * @param  non-empty-list<string>  $capabilities       Capability codes re-authorized for the subject, not
     *         merely copied from the stored row.
     * @param  string                  $siteIdentifier     Site the replacement stays confined to; already
     *         matched against the calling context.
     * @param  string                  $audience           Audience the replacement is accepted for.
     * @param  string                  $purpose            Purpose the replacement is issued under.
     * @param  DateTimeImmutable       $expiresAt          Maximum expiry inherited from the old token.
     * @param  ?string                 $organization       Exact organization inherited by the replacement.
     * @param  ?string                 $workspace          Exact workspace inherited by the replacement.
     * @param  ?string                 $membershipId       Membership row bound to organization authority.
     * @param  ?int                    $membershipVersion  Membership version captured at issuance.
     * @param  ?int                    $policyGeneration   Policy generation captured at issuance.
     * @param  string                  $familyId           Root credential family for bulk revocation.
     * @param  int                     $delegationDepth    Bounded parent-chain depth.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $subjectId,
        public string $email,
        public array $capabilities,
        public string $siteIdentifier,
        public string $audience,
        public string $purpose,
        public DateTimeImmutable $expiresAt,
        public ?string $organization = null,
        public ?string $workspace = null,
        public ?string $membershipId = null,
        public ?int $membershipVersion = null,
        public ?int $policyGeneration = null,
        public string $familyId = '',
        public int $delegationDepth = 0,
    ) {
    }
}
