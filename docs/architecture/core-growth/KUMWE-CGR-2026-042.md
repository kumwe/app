---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-042
title: "Operator ledger census and document commit observation for scalable metrics"
symbols:
  - Kumwe\App\Application\Retention\LedgerCensus
  - Kumwe\App\Application\Retention\LedgerCount
  - Kumwe\App\BusinessRecord\Application\DocumentCommitObserver
  - Kumwe\App\BusinessRecord\Application\DocumentCommitTimingRecorder
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/record-query
    version: 0.1.4
    symbols_inspected:
      - Kumwe\Record\Query\RecordQuerySpecification
      - Kumwe\Record\Query\RecordAggregate
    source_inspected:
      - vendor/kumwe/record-query/src
    tests_inspected:
      - vendor/kumwe/record-query/tests
  - package: kumwe/reporting
    version: 0.1.5
    symbols_inspected:
      - Kumwe\Reporting\Domain\ReportDefinition
      - Kumwe\Reporting\Contract\ProjectionWriter
    source_inspected:
      - vendor/kumwe/reporting/src
    tests_inspected:
      - vendor/kumwe/reporting/tests
  - package: kumwe/transaction
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Transaction\Contract\TransactionManager
    source_inspected:
      - vendor/kumwe/transaction/src
    tests_inspected:
      - vendor/kumwe/transaction/tests
search_terms:
  - "exact count"
  - "statement timeout"
  - "cost class"
  - "operation class metric"
  - "document commit timing"
required_capability: "Give operators an exact, timeout-bounded row count of each hot ledger that monitoring never runs (V2-SCL-005), and let the document commit path report line counts and duration to low-cardinality metrics without the application layer importing observability infrastructure."
consumers:
  - src/Infrastructure/Retention/DoctrineLedgerCensus.php
  - src/Infrastructure/Observability/MetricDocumentCommitObserver.php
  - src/BusinessRecord/Application/BusinessRecordService.php
  - src/Kernel/ContainerFactory.php
overlap_reviewed: []
decision: approved
decided_by: "Phase 5 scale implementation agent under the standing maintainer mandate"
reviewer: "Phase 5 scale implementation agent (source ownership review; not a human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

Monitoring must not run table-scale exact counts on the primary; an operator still needs the exact
figure on demand, with its cost declared and a server-enforced timeout. Separately, the capacity
contract's document operation classes and line counts must reach metrics from the one place that knows
them, the document command, without an application-to-infrastructure import.

## Why existing package APIs are insufficient

`kumwe/record-query` and `kumwe/reporting` bound business queries and reports but know nothing of App's
operational ledgers. `kumwe/transaction` exposes transaction boundaries only. No package declares a
diagnostic cost class, a statement timeout or a document-commit notification.

## Why extending the owning package is inappropriate

The ledgers counted are App tables spanning several packages' concepts, and the document command and
its timing recorder are App's own `BusinessRecordService` collaborators.

## Why a new focused package is inappropriate

Both capabilities are thin ports over App persistence and App composition; a package would carry only an
interface and a value with App-specific semantics.

## App-specific responsibility

Host diagnostics and orchestration: the census port is what the operator diagnostics surface composes,
and the observer port is how App's document command reports to App's metric catalogue. SQL and metric
recording stay in infrastructure.

## Tests proving the boundary

`tests/Integration/Infrastructure/BoundedGaugeAndCensusIntegrationTest.php` proves the census counts
exactly under a timeout and declares `table_scan` on MariaDB and PostgreSQL.
`tests/Unit/Infrastructure/Observability/OperationalInstrumentationTest.php` proves committed documents
are classified by line count and abandoned ones are not reported.

## Decision

Approved as App-owned diagnostics and composition under the standing maintainer mandate; the implementing
agent performed the review, which is not a human approval. Revisit if a package adopts an operational
diagnostics contract.
