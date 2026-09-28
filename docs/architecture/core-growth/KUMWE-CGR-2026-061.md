---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-061
title: "Job origin lookup so a worker runs each job under the correlation it was queued with"
symbols:
  - Kumwe\App\Application\Automation\JobOriginLookup
  - Kumwe\App\Application\Automation\Worker
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/automation
    version: 0.2.2
    symbols_inspected:
      - Kumwe\Automation\JobQueue
      - Kumwe\Automation\StoredJob
      - Kumwe\Automation\JobEnvelope
      - Kumwe\Automation\JobHandler
    source_inspected:
      - vendor/kumwe/automation/src
      - vendor/kumwe/automation/CHARTER.md
    tests_inspected:
      - vendor/kumwe/automation/tests
  - package: kumwe/access-context
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Context\Value\ExecutionContext
    source_inspected:
      - vendor/kumwe/access-context/src
    tests_inspected:
      - vendor/kumwe/access-context/tests
  - package: kumwe/integration
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Integration\EventEnvelope
      - Kumwe\Integration\ProcessManagerHandler
    source_inspected:
      - vendor/kumwe/integration/src
    tests_inspected:
      - vendor/kumwe/integration/tests
search_terms:
  - "correlation identifier"
  - "causation"
  - "job origin"
  - "trace propagation"
  - "worker execution context"
  - "stored job metadata"
required_capability: "Let the worker build each claimed job's execution context with the correlation identifier the producing request or scheduler pass recorded when it queued the job, without the application layer importing persistence or observability infrastructure."
consumers:
  - src/Application/Automation/Worker.php
  - src/Infrastructure/Automation/DoctrineJobQueue.php
  - src/Kernel/ContainerFactory.php
overlap_reviewed: []
decision: approved
decided_by: "P7-D observability implementation agent under the standing maintainer mandate"
reviewer: "P7-D observability implementation agent (source ownership review; not a human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

A job queued during an HTTP request, a scheduler pass or another job must run under the correlation
identifier of the operation that queued it. The worker issues a fresh execution context per claimed job;
before this change it passed the worker process's own correlation identifier, so audit rows, integration
events, follow-up jobs and log lines written by the job could not be joined to the request that caused
them, and `docs/operations/monitoring.md` described a guarantee the runtime did not keep (`P7-D`).

The invariant: the job context's correlation identifier equals the one recorded on the durable job row when
it was queued; a row written before origins were recorded keeps the worker's own correlation, unchanged from
before. Authorization, site ownership and the execution identity the worker issues are unaffected.

## Why existing package APIs are insufficient

`kumwe/automation` 0.2.2: `JobQueue::claim()` returns `StoredJob`, a final readonly value carrying the
identifier, queue, type, payload, schema version, attempt counters, lease token and execution class. It has
no origin, correlation or causation field, and `JobEnvelope` has none either. `JobHandler::handle()` receives
the context the host builds, so the package deliberately leaves context issuance to the host.

`kumwe/access-context` 0.1.2: `ExecutionContext::issueSystem()` and `child()` accept a correlation
identifier but cannot know which one a stored job carries; they are the values being built, not a source.

`kumwe/integration` 0.2.4: `EventEnvelope::correlationId()` and `ProcessManagerHandler::correlationId()`
cover integration events and processes, which already carry correlation in their envelopes; they say nothing
about queued jobs.

## Why extending the owning package is inappropriate

The automation charter (`vendor/kumwe/automation/CHARTER.md`) retains "worker daemons, schedulers,
infrastructure adapters, trusted handler selection, transactions, telemetry, and tenant authority" outside
the package. Recording where a job came from is persistence of the host's own request identifiers, and using
it to issue the job's context is the worker's host authority. Adding a correlation field to `StoredJob` would
make the portable package carry the host's telemetry and identity model into every consumer.

## Why a new focused package is inappropriate

The behaviour is one lookup from a stored row the App's `DoctrineJobQueue` owns to the context the App's
`Worker` issues. There is no portable bounded context: the storage, the identifiers and the context issuance
all belong to the host.

## App-specific responsibility

Host orchestration and persistence. `JobOriginLookup` is the application port through which `Worker` reads the
recorded correlation without importing Doctrine; `DoctrineJobQueue` implements it over the `jobs.correlation_id`
column added by `AsyncTraceContextMigration`, and the kernel wires the queue as the worker's lookup. Moving it
elsewhere would either put persistence in the application layer or put the host's context issuance in a
package that explicitly disclaims it.

## Tests proving the boundary

- `tests/Integration/Infrastructure/AsyncTraceContextPropagationIntegrationTest.php` (MariaDB, MySQL and
  PostgreSQL legs) pins that a job queued from a traced HTTP request runs with that request's correlation in
  its context and in every log line, that a failing attempt is logged under it and redacted, that a legacy row
  without an origin falls back to the worker's correlation, and that the kernel wires the queue as the lookup.
- `tests/Integration/BusinessIntegration/PoisonAndDeadLetterIntegrationTest.php` and
  `tests/Unit/Application/Automation/WorkerTest.php` continue to construct `Worker` without a lookup, proving the
  parameter is optional and the prior behaviour is unchanged when no origin is available.

## Decision

Approved. App owns the port and its Doctrine implementation as host orchestration and persistence. Decided by
the P7-D observability implementation agent under the standing maintainer mandate; reviewed as a source
ownership review by the same agent, which is not a human approval. Revisit if `kumwe/automation` ever adopts a
host-neutral job metadata carrier in a released version, at which point the column and the port should be
replaced by that public API.
