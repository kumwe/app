---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-041
title: "Host retention catalogue, budgeted drain port and retention readiness"
symbols:
  - Kumwe\App\Application\Automation\Job\DrainRetentionStoreHandler
  - Kumwe\App\Application\Automation\Job\PurgeBusinessRecordIdempotencyHandler
  - Kumwe\App\Application\Automation\Job\PurgeIdempotencyRecordsHandler
  - Kumwe\App\Application\Retention\RetentionBudget
  - Kumwe\App\Application\Retention\RetentionCatalogue
  - Kumwe\App\Application\Retention\RetentionDrain
  - Kumwe\App\Application\Retention\RetentionDrainResult
  - Kumwe\App\Application\Retention\RetentionObservation
  - Kumwe\App\Application\Retention\RetentionObserver
  - Kumwe\App\Application\Retention\RetentionPolicy
  - Kumwe\App\Application\Retention\RetentionReadiness
  - Kumwe\App\Application\Retention\RetentionReadinessState
  - Kumwe\App\Application\Retention\RetentionStore
  - Kumwe\App\Application\Retention\RetentionVerdict
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/idempotency
    version: 0.1.3
    symbols_inspected:
      - Kumwe\Idempotency\IdempotencyPurger
      - Kumwe\Idempotency\IdempotencyLedger
    source_inspected:
      - vendor/kumwe/idempotency/src
    tests_inspected:
      - vendor/kumwe/idempotency/tests
  - package: kumwe/automation
    version: 0.2.2
    symbols_inspected:
      - Kumwe\Automation\JobHandler
      - Kumwe\Automation\QueueRuntimePolicy
      - Kumwe\Automation\QueueRuntimePolicyCatalog
    source_inspected:
      - vendor/kumwe/automation/src
    tests_inspected:
      - vendor/kumwe/automation/tests
  - package: kumwe/integration
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Integration\OutboxStore
      - Kumwe\Integration\InboxStore
    source_inspected:
      - vendor/kumwe/integration/src
    tests_inspected:
      - vendor/kumwe/integration/tests
  - package: kumwe/audit
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Audit\Application\AuditArchiveStorage
      - Kumwe\Audit\Application\AuditTrailExport
      - Kumwe\Audit\Domain\StoredAuditArchive
    source_inspected:
      - vendor/kumwe/audit/src
    tests_inspected:
      - vendor/kumwe/audit/tests
  - package: kumwe/record-model
    version: 0.1.4
    symbols_inspected:
      - Kumwe\Record\Model\BusinessRecordReplayWindow
    source_inspected:
      - vendor/kumwe/record-model/src/BusinessRecordReplayWindow.php
    tests_inspected:
      - vendor/kumwe/record-model/tests
search_terms:
  - "retention policy"
  - "purge batch"
  - "expiry drain"
  - "backlog forecast"
  - "legal hold"
  - "time budget"
  - "readiness"
required_capability: "Declare every hot App ledger's retention contract, drain it in time-budgeted adaptive batches and publish backlog, rate and forecast figures that readiness and monitoring act on (V2-SCL-004, V2-SCL-008)."
consumers:
  - src/Infrastructure/Retention/DoctrineRetentionDrain.php
  - src/Infrastructure/Retention/DoctrineRetentionObserver.php
  - src/Infrastructure/Observability/RuntimeMetricCollector.php
  - src/Infrastructure/Persistence/ReadinessProbe.php
  - src/Kernel/ContainerFactory.php
overlap_reviewed: []
decision: approved
decided_by: "Phase 5 scale implementation agent under the standing maintainer mandate"
reviewer: "Phase 5 scale implementation agent (source ownership review; not a human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

Every hot store App appends to — business and delivery idempotency, revisions, outbox, sequenced journal,
inbox receipts, job and process history, export artifacts, audit and sessions — must declare its purpose,
minimum retention, immutability, expiry strategy, states, batch/time/lock budgets, backup, legal-hold,
failure and reconciliation behaviour. Maintenance must sustain twice the enterprise peak expiry rate
(926 rows a second) through time-budgeted adaptive batches, and the six retention metrics and a readiness
verdict must be derivable with bounded probes.

## Why existing package APIs are insufficient

`kumwe/idempotency` exports `IdempotencyPurger`, one bounded batch over one ledger with no time budget,
no observation and no catalogue. `kumwe/automation` exports `JobHandler` and queue policies whose
`retentionDays` covers contributed queues only. `kumwe/integration` exports `OutboxStore::purgeExpired`
for one store. `kumwe/audit` exports archive storage and export values but leaves pruning to the host.
None declares a retention contract across stores or measures drain, backlog or forecast. All are reused
unchanged: the drain delegates to the purgers and stores they own.

## Why extending the owning package is inappropriate

The catalogue spans stores owned by five different packages plus App-only tables (revisions, jobs,
process work, export artifacts). Putting it in any one package would make that package depend on the
others' storage. The budget figures come from App's capacity contract, which no package reads.

## Why a new focused package is inappropriate

The behaviour is operational policy over App's own physical schema and schedules, sized from App's
capacity contract and enforced through App's readiness and metrics. A package would need App's table
names, schedule rows and capacity profile, so there is no portable bounded context.

## App-specific responsibility

Host persistence, orchestration and recovery: which App tables are drained, how fast, under which App
schedules and system principal, and whether this App deployment should take traffic. The application
layer holds only the declarations, the budget arithmetic, the verdict rules and the ports; SQL lives in
`src/Infrastructure/Retention`.

## Tests proving the boundary

`tests/Unit/Application/Retention/RetentionContractTest.php` pins every declaration clause, the adaptive
batch rule, narrowing-only overrides, the forecast and the warn/fail verdict.
`tests/Unit/Application/Automation/PurgeBusinessRecordIdempotencyHandlerTest.php` and
`PurgeIdempotencyRecordsHandlerTest.php` pin that the purge jobs delegate to the drain inside the declared
budget. `tests/Integration/Retention/RetentionDrainIntegrationTest.php` proves drain, observer, run ledger
and seeded schedules on MariaDB and PostgreSQL, and that journal rows with a live outbox source survive.

## Decision

Approved as App-owned retention orchestration under the standing maintainer mandate. The implementing
agent performed the ownership review; this is not an independent or human approval. Revisit when a
package offers a cross-store retention contract or when `kumwe/idempotency` gains a time-budgeted purge.
