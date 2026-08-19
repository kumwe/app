<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalRequestView;
use Kumwe\App\BusinessSecurity\Application\Approval\ApprovalVoteView;

/**
 * Wraps shared generated-business documents in the stable CLI JSON envelope.
 *
 * Record, history, relation, metadata and mutation projection belongs to `BusinessRecordProjector` and
 * `BusinessSurfaceCatalog`, which every delivery adapter shares. This CLI-owned presenter adds only the
 * process-facing success or failure shape, so it cannot independently expose an internal record key or
 * reinterpret an exact business value.
 *
 * @since  2.0.0
 */
final readonly class BusinessRecordConsolePresenter
{
    /**
     * Wrap one successful action in the stable top-level command envelope.
     *
     * @param   string                $action  Canonical command action that completed.
     * @param   array<string, mixed>  $data    Shared policy-filtered operation document.
     *
     * @return  array<string, mixed>  Success marker, result data and bounded action metadata.
     *
     * @since   2.0.0
     */
    public function success(string $action, array $data): array
    {
        return [
            'ok' => true,
            'data' => $data,
            'meta' => ['action' => $action, 'surface' => 'cli'],
        ];
    }

    /**
     * Wrap one mapped failure in the stable top-level command envelope.
     *
     * @param   BusinessConsoleFailure  $failure  Safe error classification chosen by the failure mapper.
     *
     * @return  array<string, mixed>  Failure marker and stable error object.
     *
     * @since   2.0.0
     */
    public function failure(BusinessConsoleFailure $failure): array
    {
        return ['ok' => false, 'error' => $failure->toArray()];
    }

    /**
     * Present a maker-checker request outcome.
     *
     * @param   ?string  $requestId  Approval request UUID, or null when no active rule requires approval.
     *
     * @return  array{approval_required: bool, approval_request_id: ?string}  Exact request outcome.
     *
     * @since   2.0.0
     */
    public function approvalRequest(?string $requestId): array
    {
        return ['approval_required' => $requestId !== null, 'approval_request_id' => $requestId];
    }

    /**
     * Present a bounded scoped approval inbox without expanding its decision evidence.
     *
     * @param   list<ApprovalRequestView>  $approvals  Non-enumerating application projections.
     *
     * @return  array{items: list<array<string, mixed>>}  Bounded approval summaries.
     *
     * @since   2.0.0
     */
    public function approvalInbox(array $approvals): array
    {
        return ['items' => array_map(
            fn (ApprovalRequestView $approval): array => $this->approval($approval, false),
            $approvals,
        )];
    }

    /**
     * Present one exact scoped approval with its immutable redacted decisions.
     *
     * @param   ApprovalRequestView  $approval  Non-enumerating application projection.
     *
     * @return  array<string, mixed>  Stable snake-case approval detail.
     *
     * @since   2.0.0
     */
    public function approvalDetail(ApprovalRequestView $approval): array
    {
        return $this->approval($approval, true);
    }

    /**
     * Project one presentation-safe approval view for list or detail use.
     *
     * @param   ApprovalRequestView  $approval  Application-owned safe projection.
     * @param   bool                 $detail    Whether redacted decision history is included.
     *
     * @return  array<string, mixed>  Stable approval document with no raw protected payload.
     *
     * @since   2.0.0
     */
    private function approval(ApprovalRequestView $approval, bool $detail): array
    {
        $summary = [
            'id' => $approval->id,
            'action' => $approval->action,
            'resource_type' => $approval->resourceType,
            'resource_version' => $approval->resourceVersion,
            'site' => $approval->siteIdentifier,
            'organization' => $approval->organizationIdentifier,
            'workspace' => $approval->workspaceIdentifier,
            'required_quorum' => $approval->requiredQuorum,
            'approval_count' => $approval->approvalCount,
            'status' => $approval->status->value,
            'created_at' => $approval->createdAt->format(DATE_ATOM),
            'expires_at' => $approval->expiresAt->format(DATE_ATOM),
            'version' => $approval->version,
            'can_approve' => $approval->canApprove,
            'can_cancel' => $approval->canCancel,
            'can_revoke' => $approval->canRevoke,
        ];
        if (!$detail) {
            return $summary;
        }

        return [
            ...$summary,
            'votes' => array_map(self::vote(...), $approval->votes),
        ];
    }

    /**
     * Project one already-redacted immutable approval decision.
     *
     * @param   ApprovalVoteView  $vote  Application-owned redacted decision view.
     *
     * @return  array<string, mixed>  Stable decision outcome, reason and instant without actor identity.
     *
     * @since   2.0.0
     */
    private static function vote(ApprovalVoteView $vote): array
    {
        return [
            'decision' => $vote->decision,
            'reason' => $vote->reason,
            'decided_at' => $vote->decidedAt->format(DATE_ATOM),
        ];
    }
}
