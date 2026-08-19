<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

/**
 * Proof that an actor may mint a token for a named subject, carrying the capability set that survived.
 *
 * `TokenDelegationPreauthorizer::authorize()` is the only producer, so holding one of these means the
 * subject was resolved from an email, the actor's own `users.manage` authority was asserted, and every
 * capability was checked both against the subject's grants and against the actor's delegation ceiling.
 * `DoctrineAdministratorIdentityGateway` issues from these two fields instead of from the request body,
 * which is what keeps direct issuance and rotation at parity whichever delivery surface asked for them.
 *
 * @since  2.0.0
 */
final readonly class TokenDelegation
{
    /**
     * Capture the subject and capability set an issuance was cleared for.
     *
     * @param  string                  $subjectId          UUID the resolved email belongs to; the identity the
     *         token will authenticate as.
     * @param  non-empty-list<string>  $capabilities       Canonical capability codes cleared for delegation,
     *         deduplicated and ordered by first request.
     * @param  ?string                 $organization       Exact target-subject organization, when scoped.
     * @param  ?string                 $workspace          Exact target-subject workspace, when scoped.
     * @param  ?string                 $membershipId       Live target-subject membership UUID.
     * @param  ?int                    $membershipVersion  Locked target membership version.
     * @param  ?int                    $policyGeneration   Locked organization policy generation.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $subjectId,
        public array $capabilities,
        public ?string $organization = null,
        public ?string $workspace = null,
        public ?string $membershipId = null,
        public ?int $membershipVersion = null,
        public ?int $policyGeneration = null,
    ) {
    }
}
