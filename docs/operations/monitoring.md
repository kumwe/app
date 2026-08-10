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
