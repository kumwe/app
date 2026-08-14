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

## Metrics

Kumwe exposes a Prometheus text exposition (`text/plain; version=0.0.4`) on `GET /metrics`. The format is
a pull format every mainstream collector already reads — Prometheus, the OpenTelemetry Collector's
`prometheus` receiver, Grafana Agent, Datadog's OpenMetrics check, VictoriaMetrics — so it costs Kumwe no
vendor dependency and costs you no adapter. Nothing is pushed: a scrape that does not happen is a gap in
your monitoring, never a stall in the application.

### Turning it on, and keeping it private

The endpoint ships **off**. `config/observability.php` declares `metrics.enabled` false and
`metrics.public` false, and those are the shipped defaults.

| State | What `/metrics` answers |
|---|---|
| `KUMWE_METRICS_ENABLED` unset or false | `404`, with no body. The surface is invisible. |
| Enabled, `metrics.public` false, no `KUMWE_METRICS_TOKEN` | `404`. A misconfigured deployment does not advertise that a metrics surface exists. |
| Enabled, token set, wrong or absent credential | `401` with `WWW-Authenticate: Bearer`, no body. |
| Enabled, token set, correct credential | `200` with the exposition. |

Set `KUMWE_METRICS_TOKEN` (or `KUMWE_METRICS_TOKEN_FILE`) to at least 32 random bytes and give it to the
scraper as `Authorization: Bearer …`. The comparison is constant-time and the token is never logged.

A shared token is the control the application can enforce; it is not a substitute for keeping the port off
the public internet. Do both. Network isolation alone was rejected as the only control because nothing in
the application can verify it, so a single misconfigured ingress would fail open. Reusing Kumwe's own
access tokens was rejected because it would put a database query and a capability check on the one path
that must keep answering while the database is what is going wrong.

### What is exposed

Counters and histograms, accumulated across requests in Redis:

- `kumwe_http_requests_total{method,status}` — the 5xx-rate source.
- `kumwe_http_request_duration_seconds{method}` — histogram; use `histogram_quantile` over `_bucket`.

Gauges, recomputed from the durable rows on each scrape:

- `kumwe_build_info{release,runtime}`, `kumwe_ready`;
- `kumwe_jobs_pending`, `kumwe_jobs_due`, `kumwe_jobs_oldest_due_age_seconds`, `kumwe_jobs_lease_expired`,
  `kumwe_jobs_dead`, `kumwe_jobs_dead_lettered`;
- `kumwe_workers_registered`, `kumwe_worker_heartbeat_age_seconds`;
- `kumwe_schedules_due`, `kumwe_scheduler_lag_seconds`;
- `kumwe_outbox_pending`, `kumwe_outbox_oldest_pending_age_seconds`, `kumwe_outbox_dead`;
- `kumwe_inbox_pending`, `kumwe_inbox_oldest_pending_age_seconds`, `kumwe_inbox_poison`;
- `kumwe_process_work_overdue`, `kumwe_process_work_oldest_overdue_age_seconds`;
- `kumwe_export_queue_depth`, `kumwe_export_artifacts_expired`;
- `kumwe_metrics_scrape_duration_seconds`, `kumwe_metrics_collection_failed`.

`kumwe_metrics_collection_failed` is the one to wire first. It reports that the endpoint answered but
could not read the durable gauges, which is the difference between "the queue is empty" and "I cannot see
the queue".

### Cardinality is a correctness property

Every label a Kumwe metric can carry is enumerated in `src/Infrastructure/Observability/MetricCatalog.php`,
and any value outside its enumeration folds into `other`. The whole exposition is bounded to well under
two hundred time series regardless of traffic, and a unit test re-checks that bound on every change.

There is deliberately **no** `path`, `route`, `site`, `user`, `record` or `tenant` label anywhere. A path
label on this application would publish every business record identifier ever requested and mint a
permanent time series for each. Correlation, event, process and artifact identifiers belong in structured
log fields, which expire; a metric label does not. `config/observability.php` records the forbidden-label
policy and the metric catalogue refuses at boot any metric that violates it.

Queue depth answers *whether* something is stuck. The durable rows and the integration operations surface
answer *which* one.

### Cost

Recording a response costs two pipelined Redis round trips: one for the counter, one covering the
histogram's bucket, sum and count together. Measured on a local Redis over TCP that is **≈0.19 ms per
response**, against **≈0.0001 ms** with metrics off, where the recorder is a no-op and the cost is one
method call into an empty body. Reading every stored series back for a scrape measured ≈0.15 ms. Treat
these as order-of-magnitude figures from a development machine, not a benchmark — the point is that the
enabled cost is a fraction of a millisecond and the disabled cost is nothing.

A scrape is a fixed number of bounded aggregates over indexes the claim path already maintains, so it does
not grow with table size — ≈2.7 ms for all twenty-four gauges against a local MariaDB. It publishes its own
duration as `kumwe_metrics_scrape_duration_seconds`; alert on that if you want to know when that
assumption stops holding.

Redis being unreachable never becomes an application failure: every recorder call swallows its own
failure, and the readiness probe already reports Redis separately.

### Alerting rules

`deploy/observability/alerts.yaml` ships loadable Prometheus rules for every signal above, with concrete
thresholds. Each rule names the runbook section that says what to do about it and states, in a `caught`
annotation, the specific failure it would have caught — because an alert nobody can act on trains an
on-call rotation to ignore the page that matters. Treat the thresholds as starting points and tune the
`for` durations before the numbers.

## Logs

Kumwe writes one JSON object per line to `php://stderr` through Monolog. Collect container or process logs
off-host with retention, integrity controls, and restricted access.

`config/observability.php` is the contract *and* the runtime behaviour — it is loaded at boot by
`ObservabilityContract` and nothing else reads it, so changing the declaration changes what the process
writes, and a declaration the runtime cannot honour stops the boot. A line looks like this:

```json
{"message":"Integration event dispatch failed.","context":{"event_id":"0199…","correlation_id":"7f3c…",
"causation_id":"0199…","transport":"acme.queue","attempt":3,"classification":"transient",
"will_retry":true,"exception":{"class":"RuntimeException","message":"could not connect to
pgsql://[redacted]@db:5432/app","code":0,"file":"…/DoctrineConnectionFactory.php","line":61},
"request_id":"7f3c…","release":"2.4.1","runtime":"console","outcome":"failure"},"level":300,
"level_name":"WARNING","channel":"kumwe","datetime":"2026-08-14T09:31:02.114+00:00"}
```

Three things are guaranteed on every line and worth relying on when you build queries:

- **`correlation_id`** — the same value across the HTTP request, the jobs it queued, the outbox events it
  produced and the consumers that handled them, so one business operation is `grep`-able end to end.
  `causation_id` names the event or request that directly caused this one. Both come from the durable
  event envelope, not from a log-time guess.
- **`release`, `runtime`, `outcome`** — which build wrote it, which surface (`http` or `console`), and
  whether the thing succeeded. `outcome` is derived from severity when the caller did not state it, so
  `outcome=failure` is a complete filter over failures rather than a partial one.
- **redaction** — any context key naming `authorization`, `cookie`, `password`, `secret`, `set-cookie` or
  `token` loses its value at every nesting level, and an attached exception is reduced to class, scrubbed
  message, file and line with **no stack trace**. Traces carry frame arguments and frame arguments carry
  secrets; the class, message and line are what you actually grep for, and a full trace belongs in a
  debugger against a reproduction.

Never log request or event bodies by default. Credentials, authorization headers, cookies, passwords,
secrets, session identifiers, plaintext tokens, extension signing material, report parameters, export
contents, and sensitive business fields must be redacted. Correlation, event, process, and artifact IDs
belong in structured fields, not high-cardinality metric labels.

Set `KUMWE_LOG_LEVEL` — not `APP_DEBUG` — to change verbosity. Debug also widens the detail a 500 response
discloses, so raising verbosity through it turns a logging decision into a disclosure decision.

### Trace context

Kumwe ships **no tracer and no exporter**, and adding an OpenTelemetry SDK is a supply-chain decision this
release does not take. What it does do is participate in a trace somebody else is recording: a well-formed
W3C `traceparent` on an inbound request is accepted, its `trace_id` and `span_id` are stamped onto every
log line that request writes, and the header is echoed back. A malformed or reserved-all-zero value is
ignored entirely, and no trace identifier is ever invented — an identifier that joins to nothing is worse
than an absent one.

So if your proxy or an upstream service already emits `traceparent`, Kumwe's log stream joins that trace
today. If nothing upstream emits one, `correlation_id` remains the identifier to stitch on. See
`docs/qualification/gap-matrix.md` for what adopting a real tracer would require.

Durable database rows and audit records are authoritative for event/job/process/export recovery. Redis is
coordination state. Do not report a queue as healthy merely because Redis responds, and do not mutate outbox,
inbox, checkpoints, process work, export metadata, or runtime generations from a monitoring tool. See
[Business integrations and extension SDK](../business-integrations.md#monitoring-and-failure-recovery).

## Dependency failure and recovery

What follows is observed behaviour, not intent: each statement is produced by a drill that kills a real process
or takes a real dependency away, listed at the end of the section.

**Redis is gone.** The two things Redis carries fail in opposite directions, on purpose. The sign-in attempt
budget fails *closed*: an unreachable server refuses the attempt rather than admitting one nobody counted, and the
refusal is deliberately not the throttling error that callers already absorb, so no handler can turn an outage
into an open door. The public settings cache degrades: reads are served from the authoritative table and each one
records a warning naming the operation that failed, because SQL is the source of truth and refusing would turn a
dead cache into a site-wide outage. Locks are never reported as taken. Readiness reports not-ready rather than
raising, so the replica drains. **An established client does not heal when Redis returns** — a request-scoped
process picks up a new connection on its next request, and a long-lived worker picks one up when it is restarted,
which is what the supervisor's restart policy is for. Alert on the warning above: a steady stream of it means
every public read is now a query.

**The database is gone.** There is no reconnect wrapper, by design, but the behaviour is not uniform and the
difference matters when reading an incident. A *severed session* — a failover, an idle-session reaper, an
administrative `KILL` — is absorbed: the driver reports the loss, closes the connection, and the next statement
opens a new one, so the worker records its failed attempt on a fresh session and drains cleanly with the job left
retryable. A *server that is gone* cannot be absorbed: the worker dies inside its settlement without recording
anything, the job's lease is left standing exactly as it was, and no failure record is invented for an attempt
nobody could write. Recovery is the supervisor restarting the process and the lease expiring; the replacement
claims the job as its second attempt and completes it once. Expect one unsettled reserved job per killed worker
for the length of its lease, and alert on expired leases rather than on the crash itself.

**A worker is killed mid-job.** `SIGKILL` leaves no chance to release anything, which is the point: the fence,
not the process table, decides ownership. The job stays reserved and *unclaimable* for the rest of its lease even
though nothing is executing it, then a replacement claims it under a new token with the attempt count moved on.
The heartbeat is not load-bearing — a stale heartbeat row is a monitoring signal, never a recovery mechanism.

**A handler or an outbound endpoint wedges.** Both durable workers bound their work with a wall-clock alarm. A
job handler that overruns its runtime lease is aborted and settled as a failed attempt reading *The job exceeded
its maximum runtime lease.* in `failed_jobs`. An integration effect that overruns is aborted and recorded on the
outbox row as *The integration effect exceeded its dispatch deadline.*, with a retry scheduled; the bound is four
fifths of the dispatch lease, so the attempt is recorded while the fence is still that worker's rather than
racing a sibling's re-delivery. Alert on either message: it means an endpoint or a handler is not answering.

**Work that can never succeed.** A consumer that fails every delivery spends its attempt budget and is
quarantined as poison; it is not delivered again, and the receipt names the exception. Only a signed handler
revision frees it, and that is worth exactly one delivery, after which the receipt is settled and further
deliveries are duplicates. A job that fails every attempt is dead-lettered into `failed_jobs` and never handed
out again. Both are terminal states an operator must act on, not transient conditions that clear themselves.

**Storage refuses a write.** A missing volume, an unwritable root and a read-only filesystem each produce a typed
failure naming the step, publish nothing partial, and leave no temporary file behind.

Drills, all of which kill something real: `tests/Integration/Infrastructure/RedisOutageIntegrationTest.php`,
`tests/Integration/Automation/KilledWorkerRecoveryIntegrationTest.php`,
`tests/Integration/Automation/DatabaseLossRecoveryIntegrationTest.php`,
`tests/Integration/BusinessIntegration/HungEndpointDeadlineIntegrationTest.php`,
`tests/Integration/BusinessIntegration/PoisonAndDeadLetterIntegrationTest.php`,
`tests/Integration/Infrastructure/UnwritableStorageIntegrationTest.php` and the runtime-lease alarm cases in
`tests/Unit/Application/Automation/WorkerTest.php` all run in continuous integration. The restore-interruption
drill, `tools/restore-interruption-drill.sh`, is operator-run; see
[Backup and restore](backup-restore.md#knowing-a-restore-finished-and-re-running-one-that-did-not).

## Audit records

Application audit records are separate from diagnostic logs. They identify actor, action, target, outcome, time, and safe metadata for content, settings, access control, extensions, and automation. Restrict audit access, retain it according to site policy, and include it in incident preservation. Do not treat application logs as a substitute for audit history.

### Tamper evidence

Every `audit_events` row carries a canonical SHA-256 `digest` of its own fields, a `previous_digest` witness link
to the row that was head when it was written, and a database-allocated monotonic `position`. The scheduled
`audit.anchor.record` job seals settled position ranges into the chained `audit_anchors` ledger, which fixes each
range's row count and rolling digest so a later deletion, insertion, or reordering inside it is detectable. Bare
gaps in `position` are **not** evidence of tampering: a rolled-back transaction consumes an auto-increment value,
so gaps occur in an intact trail. The anchored row count is what settles the question.

Run `bin/kumwe audit:verify --site=<site> --token-file=<file>` to re-derive the whole chain on demand. The same
walk runs nightly as the `audit.trail.verify` job, which fails loudly on a divergence — it becomes a failed and
finally dead-lettered job, not a log line. Verification requires the `audit.manage` capability.

The command has three verdicts, and a deployment gate should branch on all three:

| Exit | `append_only_enforcement` | Meaning |
| --- | --- | --- |
| `0` | `active` | The chain verified and the database is refusing rewrites. This is the intended posture. |
| `2` | `not_installed` | The chain verified, but the append-only triggers are **not** on this server. Nothing is known to have been tampered with; nothing is preventing it either. Printed on stderr. |
| `1` | either | The trail diverged, or the command could not run. The first divergence is printed with its class, position, and event id. |

The enforcement field is read from the server's catalog on every run, not from anything the migration recorded, so
it stays true after a restore onto a different server and after a DBA grants or revokes the privilege.

### Append-only enforcement and least-privilege accounts

`UPDATE` and `DELETE` on `audit_events` are refused by database triggers on MariaDB, MySQL, and PostgreSQL. The
only sanctioned removal path is the retention job, which opens a session-scoped window after it has archived and
anchored the range. These triggers stop mistakes and casual tampering; they cannot stop an account that may drop
them. Give the application runtime a database account with `SELECT, INSERT, UPDATE, DELETE` on the application
tables but **without** `SUPER`, `TRIGGER`, or `DROP` (PostgreSQL: not the table owner and without `BYPASSRLS`),
and reserve a separate migration account for schema changes. With that separation the runtime account cannot
remove the guards even if the application is compromised.

#### When the server will not grant them

Installing the triggers needs a privilege managed database services withhold by default, so `database:migrate`
does **not** insist on it. If the server refuses, the migration records the refusal and completes; it never
aborts. That is deliberate — demanding the privilege would make Kumwe uninstallable on Amazon RDS, Cloud SQL and
Azure Database for MySQL as they ship. Only a genuine privilege refusal is absorbed (MySQL and MariaDB `1419`,
`1227` and `1142`; PostgreSQL SQLSTATE `42501`); any other failure still aborts the migration.

**What the migration account needs, per platform:**

- **MySQL and MariaDB** — the `TRIGGER` privilege on the schema, **plus** either the `SUPER` privilege or
  `log_bin_trust_function_creators = 1` whenever binary logging is enabled. With binlog on and neither of those,
  the server answers `ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is enabled`.
  On managed services, set the `log_bin_trust_function_creators` parameter to `1` in the parameter group (RDS,
  Cloud SQL) or use the equivalent server parameter (Azure), then re-run `database:migrate`.
- **PostgreSQL** — ownership of `audit_events`, or the `TRIGGER` privilege on it plus `CREATE` on its schema.
  Without it the server answers `SQLSTATE 42501: permission denied for table …`.

**What you lose without it, and what you do not.** You lose *prevention*: nothing stops a rogue or mistaken
`UPDATE`/`DELETE` at the database, so the trail is append-only by application discipline only. You do **not**
lose *tamper evidence*, which is the actual claim this subsystem makes. Digest chaining, witness links, monotonic
positions, the anchor ledger, `audit:verify` and `audit:export` all work identically and still make a mutated,
deleted, reordered or inserted row detectable after the fact. Enforcement is defence in depth on top of that, not
the thing that makes the trail trustworthy.

**Detecting and closing the gap.** `bin/kumwe audit:verify` exits `2` and reports
`"append_only_enforcement": "not_installed"` on any server where the guards are absent, so a qualification run
cannot mistake it for a guarded installation. To close it, grant the privileges above and re-run
`bin/kumwe database:migrate` — the migration is repeatable and will install the triggers on the next pass without
touching anything else. Until then, compensate with least-privilege runtime accounts (above), scheduled
`audit.trail.verify` runs, and off-host retention of `audit:export` archives.

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
