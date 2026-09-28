---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-032
title: "Machine-surface approval cancellation and atomic bulk through the generated-business facade"
symbols:
  - Kumwe\App\BusinessSurface\Application\BusinessApprovalSurfaceService
  - Kumwe\App\BusinessSurface\Application\BusinessSurfaceUseCases
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/approval
    version: v0.1.2
    symbols_inspected:
      - Kumwe\Approval\ApprovalService
      - Kumwe\Approval\ApprovalQueryService
      - Kumwe\Approval\ApprovalRequestView
      - Kumwe\Approval\ApprovalDenied
    source_inspected:
      - vendor/kumwe/approval/src/ApprovalService.php
      - vendor/kumwe/approval/src/ApprovalQueryService.php
      - vendor/kumwe/approval/CHARTER.md
    tests_inspected:
      - vendor/kumwe/approval/tests
  - package: kumwe/business-surface-contract
    version: v0.1.4
    symbols_inspected:
      - Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessActionHandler
    source_inspected:
      - vendor/kumwe/business-surface-contract/src/Application
      - vendor/kumwe/business-surface-contract/CHARTER.md
    tests_inspected:
      - vendor/kumwe/business-surface-contract/tests
search_terms:
  - approval cancel
  - requester withdraw approval
  - approval surface exposure
  - bulk archive restore action
  - atomic bulk mutation
  - child idempotency identity
required_capability: "Let REST, CLI and MCP callers withdraw their own pending generated-business approval request and apply the administrator's atomic bulk archive, restore or action, through the same application services the browser uses."
consumers:
  - src/Delivery/Http/Api/Business/BusinessApprovalApiHandler.php
  - src/Delivery/Http/Api/Business/BusinessRecordApiHandler.php
overlap_reviewed: []
decision: approved
decided_by: "Browser-to-machine parity implementation agent under standing maintainer mandate"
reviewer: "Browser-to-machine parity implementation agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

A machine actor must be able to withdraw a generated-business approval request it made, with exactly the
refusals the administrator's cancel control has: only the original requester, only on the surface and scope
the request was made in, only while it is pending and unexpired, audited as `approval.cancel`. It must also be
able to apply the generated administrator and portal bulk form — at most fifty archive, restore or declared
bulk-action members, each at its reviewed version, all or nothing, each member under a deterministic child
idempotency identity.

## Why existing package APIs are insufficient

`kumwe/approval` supplies `ApprovalService::cancel()`, which enforces the requester, context fingerprint,
pending state, membership and audit rules, and `ApprovalQueryService`, which answers scoped visibility. Both
are reused unchanged. Neither knows which generated-business approvals a given delivery surface exposes; that
exposure is App generated-surface metadata (`BusinessApprovalExposureCatalog`). `kumwe/business-surface-contract`
publishes the custom action and view contracts extensions implement but holds no record use cases. The bulk use case already exists in
`BusinessSurfaceService::bulk()`; the machine adapter needs it through the `BusinessSurfaceUseCases` port it
already depends on.

## Why extending the owning package is inappropriate

Surface exposure of generated-business approvals and the generated record facade are App generated-surface
orchestration over App definitions, policies and records. Moving surface exposure into `kumwe/approval` would
make a portable maker-checker library depend on App generated-surface metadata.

## Why a new focused package is inappropriate

There is no portable bounded context: the growth composes an existing package service with App exposure
metadata, and declares an existing App use case on the App port its delivery adapters consume.

## App-specific responsibility

This is delivery orchestration and security enforcement: `BusinessApprovalSurfaceService::businessCancel()`
resolves visibility on the caller's surface before delegating to the canonical `ApprovalService::cancel()`, so
a hidden or foreign request is refused with the same non-enumerating denial the read uses. Cancelling withdraws
a request and is never a decision; approve, reject and revoke remain on the stepped-up human surfaces.
`BusinessSurfaceUseCases::bulk()` declares the existing atomic bulk use case on the port the REST adapter
depends on, so the adapter never reaches the concrete facade.

## Tests proving the boundary

- `BusinessApprovalSurfaceServiceTest::testCancellationReachesTheWorkflowOnlyForARequestVisibleOnTheSurface`
  proves a hidden request never reaches the workflow and a visible one is decided by `ApprovalService`.
- `BusinessApprovalApiHandlerTest` and `BusinessRecordApiHandlerTest` pin the REST input grammar and the
  delegation to the port.
- The generated-business machine equivalence integration tests drive cancellation and bulk through REST, the
  console and MCP on MariaDB and PostgreSQL.

## Decision

Approved as App generated-surface orchestration and security enforcement under the standing maintainer
mandate. The ownership review is by the implementing agent, not an independent review or human GitHub
approval. Revisit if `kumwe/approval` gains a surface-exposure port.
