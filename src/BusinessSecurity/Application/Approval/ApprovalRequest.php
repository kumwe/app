<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSecurity\Application\Approval;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\Identity\Domain\Capability;

/**
 * Locked persisted approval request used for transition decisions.
 *
 * @since  2.0.0
 */
final readonly class ApprovalRequest
{
    /**
     * Validate and expose one locked approval request snapshot.
     *
     * @param  string             $id              Request UUID.
     * @param  string             $ruleId          Applied rule UUID.
     * @param  int                $ruleVersion     Frozen applied rule version.
     * @param  string             $approvalAction  Frozen checker capability.
     * @param  ?string            $approverRoleId  Frozen required approver role.
     * @param  bool               $distinctActors  Frozen maker-checker separation flag.
     * @param  ApprovalBinding    $binding         Non-transferable action binding.
     * @param  int                $quorum          Required approvals fixed at request time.
     * @param  ApprovalStatus     $status          Current lifecycle state.
     * @param  DateTimeImmutable  $expiresAt       Exclusive expiry.
     * @param  int                $version         Positive optimistic version.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $id,
        public string $ruleId,
        public int $ruleVersion,
        public string $approvalAction,
        public ?string $approverRoleId,
        public bool $distinctActors,
        public ApprovalBinding $binding,
        public int $quorum,
        public ApprovalStatus $status,
        public DateTimeImmutable $expiresAt,
        public int $version,
    ) {
        if (!\Ramsey\Uuid\Uuid::isValid($id) || !\Ramsey\Uuid\Uuid::isValid($ruleId)) {
            throw new InvalidArgumentException('An approval request identity is invalid.');
        }
        if (Capability::fromString($approvalAction)->value() !== $approvalAction) {
            throw new InvalidArgumentException('An approval request checker capability is invalid.');
        }
        if (
            $ruleVersion < 1
            || $quorum < 1
            || $version < 1
            || ($approverRoleId !== null && !\Ramsey\Uuid\Uuid::isValid($approverRoleId))
        ) {
            throw new InvalidArgumentException('An approval request snapshot is invalid.');
        }
    }
}
