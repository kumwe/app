# Monitoring and health

## Health contract

- `GET /health/live` reports HTTP-process liveness and does not query dependencies.
- `GET /health/ready` checks the configured database, required migration, and Redis connection.
- `php bin/kumwe app:health` exposes the same readiness decision to containers and operators.

Use readiness to admit traffic. Do not restart a healthy PHP process solely because a dependency is briefly unavailable; alert and investigate that dependency separately. Monitor Redis independently so authentication latency, memory pressure, eviction, and connection errors are visible before or alongside a readiness failure.

## Minimum signals

Collect and alert on:

- external HTTP availability, latency, 4xx/5xx rate, and TLS expiry;
- PHP-FPM workers, saturation, memory, restarts, and request duration;
- database connections, locks, slow queries, storage, replication, and backup age;
- Redis availability, authentication failures, memory, eviction, persistence, and command latency;
- queue depth and oldest age, retries, failed jobs, expired leases, heartbeat freshness, and scheduler lag;
- integration outbox pending age, dispatch latency, retries, terminal failures, expired leases, replays, and
  retention backlog;
- integration inbox unavailable, reordered, duplicate, and poison outcomes, checkpoint gaps, pending age, and
  consumer handler/runtime generation;
- long-running process age and status, overdue timer/command/compensation work, and cancellation/version conflicts;
- projection lag, active/rebuild generation and checksum, report row-cap/authorization refusals, export queue age,
  artifact expiry, generation/download failures, and checksum mismatches;
- administrator authentication failures, token failures, permission denials, and rate-limit decisions;
- extension activation/reconciliation failures, runtime-map generation, and stale web/worker/scheduler exits;
- last successful backup verification and clean-target restore drill.

Define engine-specific database dashboards for MariaDB, MySQL, or PostgreSQL while keeping the application-level service objectives the same.

## Logs

Kumwe writes structured application logs to `php://stderr` through Monolog. Collect container or process logs off-host with retention, integrity controls, and restricted access. Correlate events with the request identifier and deployed release.

Never log request or event bodies by default. Credentials, authorization headers, cookies, passwords, secrets,
session identifiers, plaintext tokens, extension signing material, report parameters, export contents, and sensitive
business fields must be redacted. Correlation, event, process, and artifact IDs belong in structured fields, not
high-cardinality metric labels. `config/observability.php` records the safe-context and forbidden-label policy.

Durable database rows and audit records are authoritative for event/job/process/export recovery. Redis is
coordination state. Do not report a queue as healthy merely because Redis responds, and do not mutate outbox,
inbox, checkpoints, process work, export metadata, or runtime generations from a monitoring tool. See
[Business integrations and extension SDK](../business-integrations.md#monitoring-and-failure-recovery).

## Audit records

Application audit records are separate from diagnostic logs. They identify actor, action, target, outcome, time, and safe metadata for content, settings, access control, extensions, and automation. Restrict audit access, retain it according to site policy, and include it in incident preservation. Do not treat application logs as a substitute for audit history.

### Tamper evidence

Every `audit_events` row carries a canonical SHA-256 `digest` of its own fields, a `previous_digest` witness link
to the row that was head when it was written, and a database-allocated monotonic `position`. The scheduled
`audit.anchor.record` job seals settled position ranges into the chained `audit_anchors` ledger, which fixes each
range's row count and rolling digest so a later deletion, insertion, or reordering inside it is detectable. Bare
gaps in `position` are **not** evidence of tampering: a rolled-back transaction consumes an auto-increment value,
so gaps occur in an intact trail. The anchored row count is what settles the question.

Run `bin/kumwe audit:verify --site=<site> --token-file=<file>` to re-derive the whole chain on demand; it exits
non-zero and prints the first divergence (class, position, event id). The same walk runs nightly as the
`audit.trail.verify` job, which fails loudly — a divergence becomes a failed and finally dead-lettered job, not a
log line. Verification requires the `audit.manage` capability.

### Append-only enforcement and least-privilege accounts

`UPDATE` and `DELETE` on `audit_events` are refused by database triggers on MariaDB, MySQL, and PostgreSQL. The
only sanctioned removal path is the retention job, which opens a session-scoped window after it has archived and
anchored the range. These triggers stop mistakes and casual tampering; they cannot stop an account that may drop
them. Give the application runtime a database account with `SELECT, INSERT, UPDATE, DELETE` on the application
tables but **without** `SUPER`, `TRIGGER`, or `DROP` (PostgreSQL: not the table owner and without `BYPASSRLS`),
and reserve a separate migration account for schema changes. With that separation the runtime account cannot
remove the guards even if the application is compromised.

### Audit export and retention

`bin/kumwe audit:export --site=<site> --token-file=<file> [--from=N] [--to=N]` writes a checksummed, redacted
NDJSON archive into `storage/private/audit-archives` with `0600` permissions and prints its manifest — key,
SHA-256, byte size, position range, and the anchor sequence the range was sealed under. The archive bytes never
pass through the terminal. The export is gated on `audit.export` and is itself recorded as an
`audit.trail.exported` event. Use it for incident preservation rather than raw database access.

Retention is **off by default**: the `audit.retention.enforce` schedule ships disabled with `retention_days` of
zero, so an unconfigured installation keeps its trail unbounded. To enable it, set a positive `retention_days` on
that schedule and enable it. A pass then archives and prunes only whole anchored ranges older than the window: it
exports the range, chains a `prune` mark carrying the archive checksum and the range's rolling digest into the
anchor ledger, deletes the rows through the guarded window, and records an `audit.trail.pruned` event — all in one
transaction. Evidence is transformed into archived evidence, never silently destroyed. Keep the archives under the
same custody as backups; the trail names their checksums, so an altered archive is detectable.
