<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\Capability;
use Kumwe\Access\DecisionState;
use Kumwe\Access\MembershipDirectory;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalExposureCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\Approval\ApprovalBinding;
use Kumwe\Approval\ApprovalQueryRepository;
use Kumwe\Approval\ApprovalQueryService;
use Kumwe\Approval\ApprovalRepository;
use Kumwe\Approval\ApprovalRequest;
use Kumwe\Approval\ApprovalRequestView;
use Kumwe\Approval\ApprovalService;
use Kumwe\Approval\ApprovalStatus;
use Kumwe\Approval\StepUpProofConsumer;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidFactory;

/**
 * Builds a real `BusinessApprovalSurfaceService` over deterministic approval, exposure and workflow doubles.
 *
 * Surface adapters that cancel or read generated-business approvals — the console command and the MCP
 * handlers — are tested through the real surface gate, the real generic query service and the real
 * `ApprovalService`, so their refusals are the workflow's own. Only the stores are doubled: the query
 * repository answers the given views, the exposure catalogue exposes every `approve` action binding, and
 * the workflow store is whatever the test supplies.
 *
 * @since  2.0.0
 */
trait BuildsBusinessApprovalSurface
{
    /**
     * Active definition identity shared by canonical business-record bindings.
     *
     * @var    string
     * @since  2.0.0
     */
    private static string $approvalDefinition = '018f22e2-7c8b-7ab0-8f3a-88e8026bb702';

    /**
     * Build the surface service over the given visible approvals and workflow store.
     *
     * @param   list<ApprovalRequestView>  $approvals  Repository-visible generic approvals.
     * @param   ?ApprovalRepository        $workflow   Workflow store cancellation reaches, or an inert stub.
     * @param   ?RecordingAuditRecorder    $audit      Recorder the workflow audits to, or a fresh one.
     *
     * @return  BusinessApprovalSurfaceService  Fully executable shared surface gate.
     *
     * @since   2.0.0
     */
    private function approvalSurface(
        array $approvals,
        ?ApprovalRepository $workflow = null,
        ?RecordingAuditRecorder $audit = null,
    ): BusinessApprovalSurfaceService {
        $repository = $this->createStub(ApprovalQueryRepository::class);
        $repository->method('visible')->willReturn($approvals);
        $repository->method('findVisible')->willReturnCallback(
            static function (ExecutionContext $_context, string $requestId) use ($approvals): ?ApprovalRequestView {
                foreach ($approvals as $approval) {
                    if ($approval->id === $requestId) {
                        return $approval;
                    }
                }

                return null;
            },
        );
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturnCallback(
            static fn (
                ExecutionContext $_context,
                Capability $capability,
                AuthorizationResource $_resource,
            ): AuthorizationDecision => new AuthorizationDecision(
                $capability->value() === 'business.approval.approve' ? DecisionState::Allow : DecisionState::Deny,
                'test',
                $capability->value() === 'business.approval.approve' ? 'allowed' : 'denied',
            ),
        );
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-10T10:00:00+00:00'));
        $exposure = $this->createStub(BusinessApprovalExposureCatalog::class);
        $exposure->method('approvalActions')->willReturnCallback(
            static function (ExecutionContext $_context, BusinessSurface $_surface, array $bindings): array {
                /** @var list<array{request_id: string, definition_id: string, action: string}> $bindings */
                $available = [];
                foreach ($bindings as $binding) {
                    if ($binding['action'] === 'approve') {
                        $available[$binding['request_id']] = true;
                    }
                }

                return $available;
            },
        );

        return new BusinessApprovalSurfaceService(
            new ApprovalQueryService(
                $repository,
                $authorization,
                $this->createStub(MembershipDirectory::class),
                $clock,
            ),
            $exposure,
            new ApprovalService(
                $workflow ?? $this->createStub(ApprovalRepository::class),
                $this->createStub(StepUpProofConsumer::class),
                $this->createStub(MembershipDirectory::class),
                new ImmediateTransactionManager(),
                AuthorizationContext::gateway(),
                AuthorizationContext::ownershipWriter(),
                $audit ?? new RecordingAuditRecorder(),
                $clock,
                new UuidFactory(),
            ),
        );
    }

    /**
     * Build one valid pending business-record approval projection.
     *
     * @param   string  $id      Approval request UUID.
     * @param   string  $action  Definition action handle; only `approve` is exposed by the double.
     *
     * @return  ApprovalRequestView  Valid approval projection.
     *
     * @since   2.0.0
     */
    private function approvalView(string $id, string $action = 'approve'): ApprovalRequestView
    {
        return new ApprovalRequestView(
            $id,
            'approval.surface.test',
            1,
            'business.approval.approve',
            null,
            true,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'business.record.action:' . $action,
            'business_record',
            self::$approvalDefinition . ':018f22e2-7c8b-7ab0-8f3a-88e8026bb801',
            3,
            'default',
            null,
            null,
            str_repeat('a', 64),
            str_repeat('b', 64),
            1,
            0,
            ApprovalStatus::Pending,
            new DateTimeImmutable('2026-08-10T09:00:00+00:00'),
            new DateTimeImmutable('2026-08-11T09:00:00+00:00'),
            1,
            false,
            true,
            false,
            [],
        );
    }

    /**
     * Build the locked workflow row `ApprovalService::cancel()` accepts for one requester context.
     *
     * @param   string            $id       Approval request UUID.
     * @param   ExecutionContext  $context  Requester whose actor and approval fingerprint the row binds.
     *
     * @return  ApprovalRequest  Pending, unexpired request bound to that requester.
     *
     * @since   2.0.0
     */
    private function pendingApprovalRow(string $id, ExecutionContext $context): ApprovalRequest
    {
        return new ApprovalRequest(
            $id,
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb901',
            1,
            'business.approval.approve',
            null,
            true,
            new ApprovalBinding(
                $context->actorId(),
                'business.record.action:approve',
                'business_record',
                self::$approvalDefinition . ':018f22e2-7c8b-7ab0-8f3a-88e8026bb801',
                3,
                'default',
                null,
                null,
                $context->approvalFingerprint(),
                str_repeat('c', 64),
            ),
            1,
            ApprovalStatus::Pending,
            new DateTimeImmutable('2026-08-11T09:00:00+00:00'),
            1,
        );
    }
}
