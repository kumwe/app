<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Business;

use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\CMS\BusinessSecurity\Application\Approval\ApprovalVoteView;

/**
 * Projects scoped approval views without actor or policy-integrity identifiers.
 *
 * @since  2.0.0
 */
final readonly class BusinessApprovalApiPresenter
{
    /**
     * Project a bounded approval inbox as summaries.
     *
     * @param   list<ApprovalRequestView>  $requests  Scope- and eligibility-filtered approval requests.
     *
     * @return  array{items: list<array<string, mixed>>}  Closed approval collection.
     *
     * @since   2.0.0
     */
    public function collection(array $requests): array
    {
        return ['items' => array_map($this->summary(...), $requests)];
    }

    /**
     * Project one visible approval and its redacted decision history.
     *
     * @param   ApprovalRequestView  $request  Scoped approval detail.
     *
     * @return  array<string, mixed>  Public binding, state, permissions, and votes.
     *
     * @since   2.0.0
     */
    public function detail(ApprovalRequestView $request): array
    {
        return [
            ...$this->summary($request),
            'votes' => array_map($this->vote(...), $request->votes),
        ];
    }

    /**
     * Project one approval without requester, checker-role, or binding digest evidence.
     *
     * @param   ApprovalRequestView  $request  Scoped approval projection.
     *
     * @return  array<string, mixed>  Safe request summary.
     *
     * @since   2.0.0
     */
    private function summary(ApprovalRequestView $request): array
    {
        return [
            'approval_request_id' => $request->id,
            'action' => $request->action,
            'resource_type' => $request->resourceType,
            'resource_version' => $request->resourceVersion,
            'required_quorum' => $request->requiredQuorum,
            'approval_count' => $request->approvalCount,
            'status' => $request->status->value,
            'version' => $request->version,
            'created_at' => $request->createdAt->format(DATE_ATOM),
            'expires_at' => $request->expiresAt->format(DATE_ATOM),
            'can_approve' => $request->canApprove,
            'can_cancel' => $request->canCancel,
            'can_revoke' => $request->canRevoke,
        ];
    }

    /**
     * Project one decision without the vote or approver identity.
     *
     * @param   ApprovalVoteView  $vote  Redacted application vote projection.
     *
     * @return  array{decision: string, reason: ?string, decided_at: string}  Safe decision projection.
     *
     * @since   2.0.0
     */
    private function vote(ApprovalVoteView $vote): array
    {
        return [
            'decision' => $vote->decision,
            'reason' => $vote->reason,
            'decided_at' => $vote->decidedAt->format(DATE_ATOM),
        ];
    }
}
