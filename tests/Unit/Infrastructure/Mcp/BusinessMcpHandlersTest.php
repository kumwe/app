<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Mcp;

use InvalidArgumentException;
use Kumwe\App\BusinessSurface\Application\BusinessHistoryUseCase;
use Kumwe\App\BusinessSurface\Application\BusinessMutationPlanService;
use Kumwe\App\BusinessSurface\Application\BusinessOperationStatusService;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\Infrastructure\Mcp\BusinessMcpHandlers;
use Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService;
use Kumwe\App\Infrastructure\Mcp\McpMutationGuard;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\BuildsBusinessApprovalSurface;
use Kumwe\App\Tests\Support\RecordingAuditRecorder;
use Kumwe\Approval\ApprovalDenied;
use Kumwe\Approval\ApprovalRepository;
use Kumwe\Approval\ApprovalStatus;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessMcpHandlers::class)]
/**
 * Verifies the generated-business MCP delegate's closed mutation and bounded read contracts.
 *
 * @since  2.0.0
 */
final class BusinessMcpHandlersTest extends TestCase
{
    use BuildsBusinessApprovalSurface;

    /**
     * Prove the mutation vocabulary resolves only its exact shared capabilities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMutationCapabilitiesAreClosedAndExact(): void
    {
        $expected = [
            'create' => 'business.record.create',
            'update' => 'business.record.update',
            'archive' => 'business.record.archive',
            'restore' => 'business.record.restore',
            'delete' => 'business.record.delete',
            'relate' => 'business.record.relate',
            'unrelate' => 'business.record.relate',
            'reorder' => 'business.record.relate',
            'request_action' => 'business.record.action',
            'execute_action' => 'business.record.action',
        ];

        foreach ($expected as $operation => $capability) {
            self::assertSame($capability, BusinessMcpHandlers::capabilityFor($operation));
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The generated-business MCP operation is unsupported.');
        BusinessMcpHandlers::capabilityFor('approve_action');
    }

    #[DataProvider('invalidOperationIds')]
    /**
     * Prove operation-status identifiers use the same bounded MCP idempotency grammar.
     *
     * @param   string  $operationId  Invalid candidate operation identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOperationStatusRejectsIdentifiersOutsideTheMcpGuardGrammar(string $operationId): void
    {
        $handler = (new ReflectionClass(BusinessMcpHandlers::class))->newInstanceWithoutConstructor();
        $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(BusinessMcpHandlers::class, $handler);
        self::assertInstanceOf(ExecutionContext::class, $context);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MCP operationId must be a stable 16 to 128 character identifier.');
        $handler->operationStatus($context, $operationId);
    }

    /**
     * Prove mutation planning and execution share one canonical relation input document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlanAndExecutionShareOneExactCanonicalRelationInput(): void
    {
        $method = (new ReflectionClass(BusinessMcpHandlers::class))->getMethod('planInput');

        self::assertSame([
            'operation_id' => 'relation-operation-0001',
            'definition' => 'crm.contact',
            'record' => 'contact-1',
            'expected_version' => 7,
            'relationship' => 'invoices',
            'target' => 'invoice-1',
            'position' => 3,
            'target_values' => ['amount' => '12.50'],
        ], $method->invoke(
            null,
            'relation-operation-0001',
            'relate',
            'crm.contact',
            'contact-1',
            7,
            [],
            'invoices',
            'invoice-1',
            3,
            ['amount' => '12.50'],
            [],
            null,
            [],
            null,
        ));
    }

    /**
     * Prove an existing-record mutation plan cannot omit optimistic concurrency evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPlanInputRefusesMissingExistingRecordVersion(): void
    {
        $method = (new ReflectionClass(BusinessMcpHandlers::class))->getMethod('planInput');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The mutation plan requires a positive expected version.');
        $method->invoke(
            null,
            'update-operation-0001',
            'update',
            'crm.contact',
            'contact-1',
            null,
            ['name' => 'Ada'],
            null,
            null,
            null,
            [],
            [],
            null,
            [],
            null,
        );
    }

    /**
     * Prove MCP history forwards the exact surface, definition, record, bound and cursor.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testHistoryDelegatesToTheSharedBoundedHistoryPort(): void
    {
        $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();
        $history = $this->createMock(BusinessHistoryUseCase::class);
        $expected = [
            'items' => [],
            'has_more' => false,
            'next_before_version' => null,
        ];
        $history->expects(self::once())
            ->method('history')
            ->with(
                $context,
                BusinessSurface::Mcp,
                'acme.invoice',
                'INV-0001',
                25,
                8,
            )
            ->willReturn($expected);
        $handler = new BusinessMcpHandlers(
            (new ReflectionClass(BusinessSurfaceCatalog::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessSurfaceService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessMutationPlanService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(McpMutationGuard::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessOperationStatusService::class))->newInstanceWithoutConstructor(),
            $history,
        );

        self::assertSame($expected, $handler->history($context, 'acme.invoice', 'INV-0001', 25, 8));
    }

    /**
     * Prove the MCP inbox, detail and cancel reach the shared surface gate on the MCP surface only.
     *
     * The inbox and detail project exactly the REST keys, never the requester or binding evidence; a request
     * whose action is not exposed is refused like an absent one; cancelling the actor's own pending request is
     * the audited workflow transition; and an out-of-range inbox bound is refused before any read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalsReadAndWithdrawThroughTheSharedSurfaceGate(): void
    {
        $visible = '0191574f-f0b8-7bf3-a9aa-91c6b8244e61';
        $hidden = '0191574f-f0b8-7bf3-a9aa-91c6b8244e62';
        $context = AuthorizationContext::principal(['business.approval.request', 'business.approval.approve'])
            ->context(
                SiteContext::default(),
                AuthenticationStrength::BearerToken,
                'business-mcp-approval-test',
                surface: AuthenticatedSurface::Mcp,
            );
        $workflow = $this->createMock(ApprovalRepository::class);
        $workflow->expects(self::once())->method('lock')->with($visible)
            ->willReturn($this->pendingApprovalRow($visible, $context));
        $workflow->expects(self::once())->method('transition')
            ->with($visible, ApprovalStatus::Pending, ApprovalStatus::Cancelled, 1);
        $audit = new RecordingAuditRecorder();
        $handler = $this->approvalHandler($this->approvalSurface(
            [$this->approvalView($visible), $this->approvalView($hidden, 'withdraw')],
            $workflow,
            $audit,
        ));

        $inbox = $handler->approvals($context, 10);
        $detail = $handler->approval($context, $visible);
        $cancelled = $handler->cancelApproval($context, $visible);

        self::assertSame([$visible], array_column($inbox['items'], 'approval_request_id'));
        self::assertSame([
            'approval_request_id', 'action', 'resource_type', 'resource_version', 'required_quorum',
            'approval_count', 'status', 'version', 'created_at', 'expires_at', 'can_approve', 'can_cancel',
            'can_revoke', 'votes',
        ], array_keys($detail));
        self::assertSame('pending', $detail['status']);
        self::assertTrue($detail['can_cancel']);
        self::assertSame(['approval_request_id' => $visible, 'status' => 'cancelled'], $cancelled);
        self::assertSame(['approval.cancel'], $audit->actions());

        $refusals = 0;
        foreach (
            [
                static fn () => $handler->approval($context, $hidden),
                static fn () => $handler->cancelApproval($context, $hidden),
            ] as $call
        ) {
            try {
                $call();
            } catch (ApprovalDenied) {
                ++$refusals;
            }
        }
        self::assertSame(2, $refusals);
        $this->expectException(InvalidArgumentException::class);
        $handler->approvals($context, 101);
    }

    /**
     * Prove a server composed without the approval surface refuses the approval tools as invalid requests.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testApprovalToolsAreRefusedWhenTheSurfaceIsNotComposed(): void
    {
        $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();

        $this->expectException(InvalidArgumentException::class);
        $this->approvalHandler(null)->approvals($context);
    }

    /**
     * Prove bulk arguments are refused before any plan or guard is reached when they are not a closed selection.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBulkArgumentsAreClosedBeforeAnyPlanOrGuard(): void
    {
        $context = (new ReflectionClass(ExecutionContext::class))->newInstanceWithoutConstructor();
        $handler = $this->approvalHandler(null);
        $item = ['record' => 'INV-0001', 'expectedVersion' => 3];
        $cases = [
            static fn () => $handler->planBulk($context, 'bulk-operation-000001', 'purge', 'acme.invoice', [$item]),
            static fn () => $handler->planBulk($context, 'short', 'archive', 'acme.invoice', [$item]),
            static fn () => $handler->planBulk($context, 'bulk-operation-000002', 'archive', 'acme.invoice', [
                ['record' => 'INV-0001'],
            ]),
            static fn () => $handler->planBulk($context, 'bulk-operation-000003', 'archive', 'acme.invoice', [
                [...$item, 'extra' => true],
            ]),
            static fn () => $handler->planBulk(
                $context,
                'bulk-operation-000004',
                'restore',
                'acme.invoice',
                [$item],
                'send',
            ),
            static fn () => $handler->bulk($context, 'bulk-operation-000005', 'plan', 'archive', 'acme.invoice', [
                'not-an-item',
            ]),
        ];
        $refused = 0;
        foreach ($cases as $case) {
            try {
                $case();
            } catch (InvalidArgumentException) {
                ++$refused;
            }
        }

        self::assertSame(6, $refused);
        self::assertSame('bulk_action', BusinessMcpHandlers::bulkOperation('action'));
        self::assertSame('business.record.restore', BusinessMcpHandlers::capabilityFor('bulk_restore'));
    }

    /**
     * Build a delegate whose only live collaborator is the approval surface.
     *
     * @param   ?BusinessApprovalSurfaceService  $approvals  Surface gate, or null when not composed.
     *
     * @return  BusinessMcpHandlers  Delegate under test.
     *
     * @since   2.0.0
     */
    private function approvalHandler(?BusinessApprovalSurfaceService $approvals): BusinessMcpHandlers
    {
        return new BusinessMcpHandlers(
            (new ReflectionClass(BusinessSurfaceCatalog::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessSurfaceService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessMutationPlanService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(McpMutationGuard::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(BusinessOperationStatusService::class))->newInstanceWithoutConstructor(),
            null,
            $approvals,
        );
    }

    /**
     * Supply operation identities outside the shared MCP grammar.
     *
     * @return  iterable<string, array{string}>  Invalid operation identities keyed by failure case.
     *
     * @since   2.0.0
     */
    public static function invalidOperationIds(): iterable
    {
        yield 'too short' => ['123456789012345'];
        yield 'invalid first character' => ['-123456789012345'];
        yield 'invalid punctuation' => ['123456789012345/'];
        yield 'too long' => [str_repeat('a', 129)];
    }
}
