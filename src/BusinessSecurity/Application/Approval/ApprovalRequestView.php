<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * Scoped, redacted approval projection safe for administrator and portal presentation.
 *
 * @since  2.0.0
 */
final readonly class ApprovalRequestView
{
    /**
     * Create a validated presentation-safe request projection with frozen rule evidence.
     *
     * @param  string                  $id                      Request UUID.
     * @param  string                  $ruleCode                Stable SoD rule code.
     * @param  int                     $ruleVersion             Frozen SoD rule version.
     * @param  string                  $approvalAction          Frozen checker capability.
     * @param  ?string                 $approverRoleId          Frozen approver role.
     * @param  bool                    $distinctActors          Frozen maker-checker flag.
     * @param  string                  $requesterId             Maker identity.
     * @param  string                  $action                  Exact protected action.
     * @param  string                  $resourceType            Exact resource family.
     * @param  string                  $resourceId              Exact resource identity.
     * @param  int                     $resourceVersion         Bound optimistic version.
     * @param  string                  $siteIdentifier          Exact site.
     * @param  ?string                 $organizationIdentifier  Exact organization.
     * @param  ?string                 $workspaceIdentifier     Exact workspace.
     * @param  string                  $payloadDigest           Canonical mutation SHA-256.
     * @param  string                  $bindingDigest           Complete immutable binding SHA-256.
     * @param  int                     $requiredQuorum          Fixed quorum.
     * @param  int                     $approvalCount           Current distinct approval count.
     * @param  ApprovalStatus          $status                  Current lifecycle state.
     * @param  DateTimeImmutable       $createdAt               Creation instant.
     * @param  DateTimeImmutable       $expiresAt               Exclusive expiry.
     * @param  int                     $version                 Optimistic request version.
     * @param  bool                    $canApprove              Current actor may decide while still current.
     * @param  bool                    $canCancel               Current requester may cancel.
     * @param  bool                    $canRevoke               Current manager may revoke.
     * @param  list<ApprovalVoteView>  $votes                   Immutable redacted decisions.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $ruleCode,
        public int $ruleVersion,
        public string $approvalAction,
        public ?string $approverRoleId,
        public bool $distinctActors,
        public string $requesterId,
        public string $action,
        public string $resourceType,
        public string $resourceId,
        public int $resourceVersion,
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public ?string $workspaceIdentifier,
        public string $payloadDigest,
        public string $bindingDigest,
        public int $requiredQuorum,
        public int $approvalCount,
        public ApprovalStatus $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public int $version,
        public bool $canApprove,
        public bool $canCancel,
        public bool $canRevoke,
        public array $votes,
    ) {
        if (
            $ruleVersion < 1
            || $resourceVersion < 1
            || $requiredQuorum < 1
            || $approvalCount < 0
            || $version < 1
        ) {
            throw new InvalidArgumentException('An approval request projection has invalid counters.');
        }
        if (Capability::fromString($approvalAction)->value() !== $approvalAction) {
            throw new InvalidArgumentException('An approval request projection has an invalid checker capability.');
        }
        if ($approverRoleId !== null && !\Ramsey\Uuid\Uuid::isValid($approverRoleId)) {
            throw new InvalidArgumentException('An approval request projection has an invalid approver role.');
        }
        if (
            preg_match('/^[a-f0-9]{64}$/D', $payloadDigest) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $bindingDigest) !== 1
            || !array_is_list($votes)
        ) {
            throw new InvalidArgumentException('An approval request projection has invalid evidence.');
        }
        foreach ($votes as $vote) {
            if (!$vote instanceof ApprovalVoteView) {
                throw new InvalidArgumentException('An approval request projection has an invalid vote.');
            }
        }
    }
}
