<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use InvalidArgumentException;

/**
 * Versioned proof that a principal currently belongs to an organization and optional workspace.
 *
 * The membership and policy generations are included in authorization fingerprints, cursors and
 * delegations. A store must re-read them inside a mutation transaction; stale selections therefore fail
 * rather than retaining authority after membership or policy changes.
 *
 * @since  2.0.0
 */
final readonly class MembershipContext
{
    /**
     * Validate a server-resolved membership snapshot.
     *
     * @param   string               $membershipId       Stable UUID of the membership row.
     * @param   OrganizationContext  $organization       Organization conferred by the membership.
     * @param   ?WorkspaceContext    $workspace          Workspace selection, when the operation is narrower.
     * @param   int                  $membershipVersion  Positive optimistic version of the membership.
     * @param   int                  $policyGeneration   Positive organization policy generation.
     *
     * @throws  InvalidArgumentException  When the row identity or either generation is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $membershipId,
        private OrganizationContext $organization,
        private ?WorkspaceContext $workspace,
        private int $membershipVersion,
        private int $policyGeneration,
    ) {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
        if (preg_match($uuid, $membershipId) !== 1) {
            throw new InvalidArgumentException('A membership context requires a valid UUID.');
        }
        if ($membershipVersion < 1 || $policyGeneration < 1) {
            throw new InvalidArgumentException('Membership and policy generations must be positive.');
        }
    }

    /** Return the stable membership UUID. @return string Stable membership UUID. @since 2.0.0 */
    public function membershipId(): string
    {
        return $this->membershipId;
    }

    /** Return the selected organization. @return OrganizationContext Selected organization. @since 2.0.0 */
    public function organization(): OrganizationContext
    {
        return $this->organization;
    }

    /** Return the optional workspace. @return ?WorkspaceContext Selected workspace. @since 2.0.0 */
    public function workspace(): ?WorkspaceContext
    {
        return $this->workspace;
    }

    /** Return the membership version. @return int Membership optimistic version. @since 2.0.0 */
    public function membershipVersion(): int
    {
        return $this->membershipVersion;
    }

    /** Return the policy generation. @return int Organization policy generation. @since 2.0.0 */
    public function policyGeneration(): int
    {
        return $this->policyGeneration;
    }

    /**
     * Digest every membership value that may change an authorization decision.
     *
     * @return  string  Hex-encoded SHA-256 fingerprint.
     *
     * @since   2.0.0
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode("\n", [
            $this->membershipId,
            $this->organization->identifier(),
            $this->workspace?->identifier() ?? '-',
            (string) $this->membershipVersion,
            (string) $this->policyGeneration,
        ]));
    }
}
