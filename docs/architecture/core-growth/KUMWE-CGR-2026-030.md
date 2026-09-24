---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-030
title: "Host asynchronous receipt worker and preclaimed webhook execution"
symbols:
  - Kumwe\App\BusinessIntegration\Application\IntegrationReceiptWorker
  - Kumwe\App\BusinessIntegration\Application\DurableOutboundAdapterDispatcher
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/integration
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Integration\InboxStore
      - Kumwe\Integration\InboxLease
      - Kumwe\Integration\EventConsumerDefinition
    source_inspected:
      - vendor/kumwe/integration/src/InboxStore.php
      - vendor/kumwe/integration/src/InboxLease.php
      - vendor/kumwe/integration/CHARTER.md
    tests_inspected:
      - vendor/kumwe/integration/tests
  - package: kumwe/automation
    version: 0.2.2
    symbols_inspected:
      - Kumwe\Automation\RetryPolicy
      - Kumwe\Automation\QueueRuntimePolicy
      - Kumwe\Automation\JobQueue
    source_inspected:
      - vendor/kumwe/automation/src/QueueRuntimePolicy.php
      - vendor/kumwe/automation/src/RetryPolicy.php
      - vendor/kumwe/automation/CHARTER.md
    tests_inspected:
      - vendor/kumwe/automation/tests
search_terms:
  - independent consumer receipt
  - queue capacity permit
  - fair worker claim
  - trusted generation and scope
  - outbound idempotency retry
required_capability: "Execute independently leased App inbox receipts using the trusted host graph, transaction authority, system identity and enforceable worker deadline."
consumers:
  - src/Delivery/Console/Command/IntegrationWorkCommand.php
  - src/BusinessIntegration/Infrastructure/RuntimeIntegrationReceiptWorker.php
overlap_reviewed: []
decision: approved
decided_by: "Codex runtime implementation agent under standing maintainer mandate"
reviewer: "Codex runtime implementation agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

The existing integration command must drain independent durable receipts. External effects execute after
claim commit; internal effects settle atomically with the original inbox lease. Both use current host
trust, signed handler selection and per-item deadlines. Findings V2-SCL-006 and V2-SCL-007 define the scope.

## Why existing package APIs are insufficient

Integration supplies InboxStore, InboxLease, event reconstruction and declaration semantics. Automation
supplies retry decisions and queue policy bounds. Neither package exports a trusted App worker, system
principal or database adapter. The existing stable package contracts are reused unchanged.

## Why extending the owning package is inappropriate

Both charters explicitly assign transactions, persistence, dispatch workers and trust selection to hosts.
Adding the App runtime compiler, extension executable registries or OS signal lifecycle would violate that
boundary. No portable value or retry algorithm is introduced here.

## Why a new focused package is inappropriate

This is App orchestration over its existing durable schema and signed contribution graph. There is no
new portable responsibility. A second package would need App identity and trust authorities.

## App-specific responsibility

IntegrationReceiptWorker is the inward port through which delivery invokes the host receipt worker.
DurableOutboundAdapterDispatcher gains dispatchClaimed, retaining its existing outbound receipt rules
while accepting a lease acquired by the host pool. Infrastructure owns fair batch SQL and permit storage.
IntegrationEventConsumerDispatcher's corresponding host dispatch expansion is already owned by
KUMWE-CGR-2026-004; this record deliberately does not duplicate its symbol ownership.

## Tests proving the boundary

IndependentReceiptFanoutIntegrationTest exercises real host receipt storage, atomic rollback, fair
consumer/site/organization turns, queued retries, malformed-envelope quarantine and stale fencing.
QueueWorkerPermitsIntegrationTest exercises shared job/inbox capacity and generation contraction.
RuntimeIntegrationEventTransportTest proves publication creates pending receipts without invoking handlers.
These are host persistence/orchestration tests; portable package tests remain in their owner repositories.

## Decision

Approved as App worker orchestration and persistence under the standing maintainer mandate. Exact locked
source inspection and capability-index digest are recorded in the scoped handoff. This ownership review
is by the implementing agent, not an independent review or human GitHub approval. Parent baseline
recording, combined automated evidence and maintainer merge remain required; no finding closure is implied.
