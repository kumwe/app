<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Delivery-neutral generated-business operations that need definition-specific execution dispatch.
 *
 * Ordinary generated reads and mutations may use their narrower record-service ports directly. Custom
 * views and actions must cross this seam because it combines surface exposure, the installed definition,
 * and owner-aware handler dispatch without giving a delivery adapter access to executable registries.
 *
 * @since  2.0.0
 */
interface BusinessSurfaceUseCases
{
    /**
     * Execute one policy-visible definition-declared custom view.
     *
     * @param   ExecutionContext      $context     Authenticated actor.
     * @param   BusinessSurface       $surface     Exact delivery boundary.
     * @param   string                $definition  Definition UUID or handle.
     * @param   string                $view        Custom view handle.
     * @param   array<string, mixed>  $query       Shared bounded record-query document.
     * @param   array<string, mixed>  $parameters  Contract-specific parameters.
     * @param   ?string               $record      Optional public record identity.
     *
     * @return  array<string, mixed>  Safe metadata and contract-validated view data.
     *
     * @since   2.0.0
     */
    public function customView(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $view,
        array $query = [],
        array $parameters = [],
        ?string $record = null,
    ): array;

    /**
     * Execute an ordinary or custom definition-declared action through the shared parity seam.
     *
     * @param   ExecutionContext      $context            Authenticated actor.
     * @param   BusinessSurface       $surface            Exact delivery boundary.
     * @param   string                $definition         Definition UUID or handle.
     * @param   string                $record             Public record identity.
     * @param   int                   $expectedVersion    Required current record version.
     * @param   string                $action             Declared action handle.
     * @param   string                $operationId        Idempotency identity.
     * @param   array<string, mixed>  $input              Validated action input.
     * @param   ?string               $approvalRequestId  Optional approved request identity.
     *
     * @return  array<string, mixed>  Safe canonical or contract-extended mutation result.
     *
     * @since   2.0.0
     */
    public function action(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        string $operationId,
        array $input = [],
        ?string $approvalRequestId = null,
    ): array;

    /**
     * Request maker-checker approval for one ordinary or typed custom action attempt.
     *
     * @param   ExecutionContext      $context          Authenticated actor.
     * @param   BusinessSurface       $surface          Exact delivery boundary.
     * @param   string                $definition       Definition UUID or handle.
     * @param   string                $record           Public record identity.
     * @param   int                   $expectedVersion  Required current record version.
     * @param   string                $action           Declared high-impact action handle.
     * @param   string                $operationId      Idempotency identity.
     * @param   array<string, mixed>  $input            Contract-validated custom input, or an empty object.
     *
     * @return  array{approval_request_id: ?string}  Approval identity, or null when no rule requires one.
     *
     * @since   2.0.0
     */
    public function requestActionApproval(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        string $record,
        int $expectedVersion,
        string $action,
        string $operationId,
        array $input = [],
    ): array;

    /**
     * Apply at most fifty archive, restore, or declared bulk-action mutations atomically.
     *
     * Every member runs through the same per-record archive, restore or action use case, under a deterministic
     * child idempotency identity derived from the caller's operation identity, inside one outer transaction:
     * a policy denial, stale reviewed version, approval requirement or storage fault rolls every member back.
     *
     * @param   ExecutionContext            $context      Authenticated actor.
     * @param   BusinessSurface             $surface      Exact delivery boundary.
     * @param   string                      $definition   Definition UUID or handle.
     * @param   BusinessSurfaceOperation    $operation    Archive, restore, or action operation.
     * @param   list<array<string, mixed>>  $items        Selected records and reviewed expected versions.
     * @param   string                      $operationId  Caller-owned bulk idempotency identity.
     * @param   ?string                     $action       Bulk-enabled declared action handle.
     * @param   array<string, mixed>        $input        Shared bounded action input.
     *
     * @return  array{operation: string, count: int, items: list<array<string, mixed>>}  Projected outcomes
     *          in selection order.
     *
     * @since   2.0.0
     */
    public function bulk(
        ExecutionContext $context,
        BusinessSurface $surface,
        string $definition,
        BusinessSurfaceOperation $operation,
        array $items,
        string $operationId,
        ?string $action = null,
        array $input = [],
    ): array;
}
