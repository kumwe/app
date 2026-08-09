<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSecurity\Application\Approval;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * Active maker-checker rule selected for a high-impact action.
 *
 * @since  2.0.0
 */
final readonly class ApprovalRule
{
    /**
     * Validate and freeze one active approval rule.
     *
     * @param   string  $id              Rule UUID.
     * @param   string  $code            Stable operator-facing rule code.
     * @param   string  $approvalAction  Registered capability every checker must hold.
     * @param   int     $quorum          Required distinct approvals.
     * @param   bool    $distinctActors  Whether requester and approvers must differ.
     * @param   int     $version         Exact active rule version frozen into each request.
     * @param   ?string $approverRoleId  Exact approver role frozen into each request.
     *
     * @throws  InvalidArgumentException  When identity, code or quorum is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $approvalAction,
        public int $quorum,
        public bool $distinctActors,
        public int $version,
        public ?string $approverRoleId,
    ) {
        if (!\Ramsey\Uuid\Uuid::isValid($id) || preg_match('/^[a-z][a-z0-9._:-]{0,190}$/D', $code) !== 1) {
            throw new InvalidArgumentException('The approval rule identity is invalid.');
        }
        if (Capability::fromString($approvalAction)->value() !== $approvalAction) {
            throw new InvalidArgumentException('The approval action must be a canonical capability.');
        }
        if ($quorum < 1 || $quorum > 32) {
            throw new InvalidArgumentException('Approval quorum must be between one and thirty-two.');
        }
        if ($version < 1 || ($approverRoleId !== null && !\Ramsey\Uuid\Uuid::isValid($approverRoleId))) {
            throw new InvalidArgumentException('The approval rule version or approver role is invalid.');
        }
    }
}
