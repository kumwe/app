# Retention and hot-ledger capacity

Findings `V2-SCL-004` and `V2-SCL-008`, roadmap package `P5-E`. The executable declaration is
`src/Application/Retention/RetentionCatalogue.php`; this page is its operator reading. A store cannot be
appended to without a declaration there: `RetentionContractTest` fails the build when a store lacks one.

## The required drain rate

The capacity contract's enterprise profile peaks at 463 logical writes a second and
`retention_budget_rule.maintenance_drain_multiple_of_peak_expiry` is 2, so every drainable ledger must
sustain **926 rows a second** while ordinary traffic continues. Each drain runs every minute with a
30-second time budget (duty cycle ½), so a run must remove rows at ≥ 1,852 a second while it runs.

The seeded `business.record.idempotency.purge` schedule used to allow 500 × 10 = 5,000 deletions an hour
(120,000 a day). Runs are now bounded by **time, not count**: a run starts at the store's minimum batch,
doubles the batch while a batch transaction finishes inside half the 250 ms lock budget, halves it when a
batch overruns the lock budget, and stops when a batch comes back short (backlog cleared), the 30-second
budget is spent, or the optional `maximum_batches` cap is hit. Payload keys (`batch_size`,
`maximum_batches`, `time_budget_seconds`) may only narrow the declared budget; widening is refused before
anything is deleted. Measured drain rates on the development host are in
[`capacity-estimate.md`](capacity-estimate.md#retention-drain-measurements).

## Store declarations

| Store (`store` label) | Purpose | Minimum retention | Immutable | Eligibility | Drain job | Batch | Required setting |
|---|---|---|---|---|---|---|---|
| `business_idempotency` | Replay a typed record command | until `expires_at` (replay window, default 30 d retention) | no | indexed `expires_at` | `business.record.idempotency.purge` | 200–1,000 | enabled schedule |
| `delivery_idempotency` | Replay a keyed HTTP/MCP mutation | until `expires_at` (24 h) | no | indexed `expires_at` + unowned | `system.idempotency.purge` | 200–10,000 | enabled schedule |
| `revisions` | Authoritative record history | kept for good | yes | none — never drained | — | — | — |
| `outbox_source_events` | Committed event source, replay | 90 d after dispatch | no | indexed `retained_until`, journaled only | `system.retention.drain` | 200–10,000 | enabled schedule with `store` |
| `sequenced_journal` | Ordered projection source | 90 d, below every live checkpoint, outbox row gone | yes | `recorded_at`, checkpoint floor | `system.retention.drain` | 200–5,000 | same |
| `inbox_receipts` | Per-consumer receipts and tombstones | evidence compacted after 1 d; tombstone removed after 90 d once the outbox row is gone | no | terminal status + `updated_at` | `system.retention.drain` | 200–5,000 | same |
| `job_history` | Settled core-queue jobs and dead letters | 7 d | no | terminal status + `completed_at`/`updated_at` | `system.retention.drain` | 200–5,000 | same |
| `process_history` | Settled process work items | 30 d | no | terminal status + `updated_at` | `system.retention.drain` | 200–5,000 | same |
| `export_artifacts` | Downloadable exports | until `expires_at` | no | indexed `expires_at`; bytes deleted first | `system.retention.drain` | 50–500 | same |
| `audit` | Tamper-evident trail | operator-set `retention_days` | yes | whole anchored ranges only | `audit.retention.enforce` | one range | enabled schedule **and** positive `retention_days` |
| `sessions` | Live sessions, proofs, tokens | until `expires_at` | no | indexed `expires_at` | `system.sessions.purge` (per site) | — | enabled schedule |

Contributed queues keep their signed `retentionDays` and are purged through the queue runtime operations;
the generic job drain excludes them so no job is under two windows.

States, lock/time/replication budgets, backup, legal-hold, failure and reconciliation text per store are
in the catalogue. In short: every row is in every full snapshot and a restore resumes draining from the
restored state; a **legal hold** is applied by disabling the store's schedule (the forecast metric then
shows the exhaustion date); a failed run is retried under its attempt budget and dead-lettered, while
backlog and oldest-age keep rising until an operator acts. Replication budget: a batch is one short
transaction of at most the batch ceiling, so a replica applies it as one event group no larger than the
batch; lag is observed through `kumwe_database_replica_lag_seconds` where the engine reports it.

## Audit pruning

Audit is pruned only by `audit.retention.enforce`, only whole anchored ranges, and now only after the
just-written archive has been **re-read from the private store**: size, SHA-256, line count and manifest
range must match (`FilesystemAuditArchiveVerifier`), otherwise the whole pass rolls back and nothing is
deleted. The archive directory `storage/private/audit-archives` must be copied off-host by the backup
cycle (`tools/backup-cycle.sh`); a restore drill of that copy is the operator's proof that the off-host
archive is restorable. Leave `retention_days` at 0 until that drill has passed.

## Metrics and readiness

`/metrics` publishes, per `store`: `kumwe_retention_ingest_rows_per_second`,
`kumwe_retention_expiry_rows_per_second`, `kumwe_retention_drain_rows_per_second`,
`kumwe_retention_backlog_rows`, `kumwe_retention_oldest_age_seconds`,
`kumwe_retention_forecast_seconds_to_capacity` (315,360,000 = no exhaustion predicted), plus
`kumwe_retention_drained_rows_total` and `kumwe_retention_readiness` (0 ready, 1 warning, 2 failed).
Every probe is a bounded index range capped at 100,000 entries; a capped backlog is a lower bound.
Ingest and expiry are counted over a five-minute window; drain comes from the last recorded run
(`retention_runs`).

`bin/kumwe app:health` (the thorough readiness probe) assesses the observations:

- a required setting absent or disabled → **warning** under `KUMWE_CAPACITY_PROFILE=baseline` (default),
  **not ready** under `KUMWE_CAPACITY_PROFILE=enterprise`;
- a backlog at capacity, or a forecast of exhaustion within 24 h → **not ready**;
- a forecast within 7 days → **warning** (logged).

On a fresh installation only `audit` is unconfigured, because retention is off by default; an enterprise
installation must configure it (after proving off-host archive restore) before it reports ready.
