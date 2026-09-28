---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-043
title: "Per-site cumulative export byte budget charged at export completion"
symbols:
  - Kumwe\App\BusinessReporting\Application\ExportSiteByteBudget
  - Kumwe\App\BusinessReporting\Application\ExportSiteByteBudgetExhausted
layer: application
capability_index_sha256: "0cb5a6ccf58ee28862f139c4002f47176deea9b4df2fa49959ded48bcbf28737"
packages_reviewed:
  - package: kumwe/reporting
    version: 0.1.5
    symbols_inspected:
      - Kumwe\Reporting\Domain\ReportDefinition
      - Kumwe\Reporting\Contract\ProjectionWriter
    source_inspected:
      - vendor/kumwe/reporting/src
    tests_inspected:
      - vendor/kumwe/reporting/tests
  - package: kumwe/automation
    version: 0.2.2
    symbols_inspected:
      - Kumwe\Automation\JobQueue
      - Kumwe\Automation\QueueRuntimePolicy
    source_inspected:
      - vendor/kumwe/automation/src
    tests_inspected:
      - vendor/kumwe/automation/tests
  - package: kumwe/transaction
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Transaction\Contract\TransactionManager
    source_inspected:
      - vendor/kumwe/transaction/src
    tests_inspected:
      - vendor/kumwe/transaction/tests
search_terms:
  - "export byte budget"
  - "per-site quota"
  - "cumulative bytes"
  - "fairness"
  - "rate limit"
required_capability: "Bound the cumulative bytes one site may publish as report exports in a window, so one site cannot consume the installation's export capacity (P5-H fairness), charged atomically with the export's completion so a rolled-back completion is never charged and concurrent completions cannot both pass on the same remaining budget."
consumers:
  - src/BusinessReporting/Application/ExportAttemptPublisher.php
  - src/BusinessReporting/Application/ExportGenerationService.php
  - src/BusinessReporting/Infrastructure/DoctrineExportSiteByteBudget.php
  - src/Kernel/ContainerFactory.php
overlap_reviewed:
  - Kumwe\App\BusinessReporting\Infrastructure\FilesystemExportArtifactStorage
  - Kumwe\Automation\QueueRuntimePolicy
decision: approved
decided_by: "Phase 5 scale implementation agent under the standing maintainer mandate"
reviewer: "Phase 5 scale implementation agent (source ownership review; not a human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

Each export artifact is already bounded by the storage ceiling (128 MiB, at most 512 MiB) and each export
runs as a queued job under per-site queue fairness, but nothing bounded the sum of a site's exports: one
site could publish artifact after artifact until the export volume filled. The export completion needs a
per-site cumulative byte budget that is charged in the completion transaction and refuses the completion
that would pass it.

## Why existing package APIs are insufficient

`kumwe/reporting` defines reports and projections, not export artifacts or their storage. `kumwe/automation`
bounds queue concurrency (`QueueRuntimePolicy`) and claim fairness, which limit how many exports run at
once, not how many bytes a site accumulates. `kumwe/transaction` provides the transaction boundary only.
`FilesystemExportArtifactStorage` bounds one artifact, not a site's total.

## Why extending the owning package is inappropriate

Export artifacts, their completion protocol and their storage are App's `BusinessReporting` module; no
package owns them, so the budget has no package to extend.

## Why a new focused package is inappropriate

The capability is one port and one refusal that App's export completion calls; a package would carry an
interface whose only meaning is App's export lifecycle.

## App-specific responsibility

App composes the export completion (`ExportAttemptPublisher`, `ExportGenerationService`) and decides the
durable failure code a refused artifact records. The SQL — one conditional `UPDATE` on a one-row-per-site
ledger — stays in infrastructure (`DoctrineExportSiteByteBudget`).

## Tests proving the boundary

`tests/Integration/BusinessReporting/ExportSiteByteBudgetIntegrationTest.php` proves accumulation, refusal,
the UTC-day window, rollback, per-site isolation and six racing processes on MariaDB and PostgreSQL.
`tests/Unit/BusinessReporting/ExportGenerationPolicyFenceTest.php` proves a refused completion fails the
artifact durably with `site_byte_budget` and keeps none of its bytes.

## Decision

Approved as App-owned export lifecycle behaviour under the standing maintainer mandate; the implementing
agent performed the review, which is not a human approval. Revisit if a package adopts export artifacts.
