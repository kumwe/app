# Alert runbooks

One section per alert in [`deploy/observability/alerts.yaml`](../../deploy/observability/alerts.yaml), named after
the alert so the `runbook` annotation on every page lands here. Each section says what the alert means, what to
check first, what to do, and what makes it clear. Alerts marked **page** are the critical set: each one has an
automated drill (`tools/alert-drill.php`, run by the `Observability drills` workflow on MariaDB and PostgreSQL) that
induces the condition against a real application and database, evaluates the shipped rule with `promtool`, proves
the alert fires with this runbook link, performs the recovery written here, and proves the alert clears. The drill
named under each page section is the evidence that the "Act" steps work.

Log queries below use the structured fields every Kumwe line carries (see
[Monitoring](monitoring.md#logs)): `runtime`, `operation`, `outcome`, `correlation_id`, `causation_id`, `trace_id`,
`job_id`, `event_id`. Inhibition rules in [`alertmanager.yaml`](../../deploy/observability/alertmanager.yaml) mute
the consequences of a cause that already pages, so work the page you received before the tickets it would explain.

## Availability

### KumweScrapeTargetDown

**Page.** Prometheus cannot scrape `/metrics` on one replica for five minutes.

- **Check:** `curl -fsS https://<replica>/health/live`. If liveness answers, the fault is the scrape path: the
  scrape token (`KUMWE_METRICS_TOKEN_FILE`), `KUMWE_METRICS_ENABLED`, or the network policy between Prometheus
  and the replica. If liveness does not answer, the PHP-FPM or web container is down or wedged.
- **Act:** restart the replica's web and app containers; if the token rotated, update the scrape job's
  `authorization.credentials_file` and the replica secret together.
- **Clears when:** the next scrape succeeds (`up == 1`).
- **Drill:** `scrape-target-down` stops the drill web server, then restarts it.

### KumweReadinessFailing

**Page.** A replica has reported `kumwe_ready == 0` for five minutes: its signed runtime marker is missing, stale or
disagrees with the published extension generation, so the load balancer drains it.

- **Check:** `bin/kumwe app:health` on the replica, and the log for `runtime` lines about the extension runtime
  generation. `KumweExtensionRuntimeUntrusted` on the same replica is the more specific cause and inhibits this one.
- **Act:** a marker that aged out means the runtime watcher stopped: restart `bin/kumwe extension:runtime:watch`
  under its supervisor. `bin/kumwe extension:runtime:materialize` publishes a fresh marker at once; confirm
  `GET /health/ready` answers 200.
- **Clears when:** readiness reports 1 for any sample in the five-minute window.
- **Drill:** `readiness-failing` stops the watcher until the marker is older than its thirty-second window, then
  materializes the runtime and starts the watcher again.

### KumweSyntheticProbeFailing

**Page.** An outside-in probe check has failed for five minutes. The `check` label names which: `liveness`,
`readiness`, `public_page` (including request-identifier and trace-context echo), `metrics` or `api`.

- **Check:** run the probe by hand from the probe host:
  `php tools/synthetic-probe.php --base-url=https://<site>` — its JSON lines name the failing step and status.
  A failure with every internal signal green points at TLS, DNS, the ingress or `APP_TRUSTED_HOSTS`.
- **Act:** fix the edge component the probe names — the ingress, TLS, DNS or a trusted-host list
  (`APP_TRUSTED_HOSTS`) that dropped the public name; a `liveness` failure inhibits the other checks, so start
  there.
- **Clears when:** the check passes on the next probe run.
- **Drill:** `synthetic-probe-failing` restarts the replica with `APP_TRUSTED_HOSTS` naming only its address, so the
  probe (which uses the public name) fails every check while the scrape and readiness stay green, then restores
  the list.

### KumweSyntheticProbeStale

Ticket. The probe has not written its textfile for fifteen minutes, so the page above cannot fire.

- **Check:** the probe's timer or cron entry and the textfile collector directory it writes to.
- **Act:** restore the schedule; the probe exits non-zero on failure, so a scheduler that treats that as fatal may
  have stopped rescheduling it.
- **Clears when:** `kumwe_probe_last_run_timestamp_seconds` is fresh again.

### KumweReleaseSkew

Ticket. Two releases have served for thirty minutes.

- **Check:** `count by (release, instance) (kumwe_build_info)` names the replicas on each release.
- **Act:** finish or roll back the deploy; restart the replica that failed its image pull.
- **Clears when:** one release remains.

## HTTP

### KumweServerErrorRateElevated

Ticket. More than 2% of responses are 5xx for ten minutes.

- **Check:** log lines with `outcome=failure` and `runtime=http`; group by `request_id` and exception class.
- **Act:** fix the failing dependency or configuration the exception names. The critical alert inhibits this one.
- **Clears when:** the ratio falls below 2% over five minutes.

### KumweServerErrorRateCritical

**Page.** More than 10% of responses are 5xx for five minutes. Treat as an outage.

- **Check:** which replicas answer 5xx (their access logs, or `runtime=http` log lines with `outcome=failure` and
  the exception class), then `GET /health/ready`, the database and Redis. A replica whose configuration does not
  match the installation — a mis-templated `DB_TABLE_PREFIX` or database name, a stale release — answers every
  request with a 5xx while its liveness stays green. A replica that cannot reach the database at all fails before
  the request pipeline and shows up as `KumweScrapeTargetDown` and a failing probe instead.
- **Act:** take the failing replica out of rotation and redeploy it with the installation's configuration, or
  restore the dependency; do not raise log verbosity through `APP_DEBUG`, which also widens what a 500 discloses.
- **Clears when:** the ratio falls below 10% over five minutes.
- **Drill:** `server-error-rate-critical` sends half the traffic to a second replica restarted with a wrong
  `DB_TABLE_PREFIX`, then restarts it with the installation's configuration.

### KumweRequestLatencyHigh

Ticket. The 95th-percentile request duration has exceeded two seconds for fifteen minutes.

- **Check:** the transport dashboard's lock and rollback panels, then `KumweDatabaseConnectionsSaturated`; a slow
  page after a business-schema plan usually means a missing index.
- **Act:** remove the contention or add the index; scale the web tier only when CPU is the constraint.
- **Clears when:** p95 falls below two seconds over ten minutes.

### KumweDocumentCommitLatencyHigh

Ticket. Committing business documents of one size class takes more than five seconds at the 95th percentile.

- **Check:** the business dashboard's document panel by `operation_class`, and deadlocks on the transport
  dashboard; a hot sequence row serialises every document.
- **Act:** find the serialising row or trigger with the diagnostics surface (`diagnostics.read`) and remove it.
- **Clears when:** p95 falls below five seconds over fifteen minutes.

## Database

### KumweDatabaseConnectionsSaturated

Ticket. Sessions in use exceed 90% of the server ceiling.

- **Check:** the server's process list for long-idle sessions; the worker replica count against
  `max_connections`.
- **Act:** close leaked sessions, reduce worker concurrency, or raise the ceiling with the server's memory in mind.
- **Clears when:** usage falls below 90%.

### KumweDatabaseReplicaLagHigh

Ticket. A replica is more than sixty seconds behind.

- **Check:** the replica's own status (`SHOW REPLICA STATUS`, `pg_stat_replication`); the gauge reads zero when the
  runtime account may not look, so confirm on the server.
- **Act:** fix the replica's I/O or disk; do not promote it while it lags.
- **Clears when:** lag falls below sixty seconds.

### KumweDeadlocksFrequent

Ticket. More than five deadlocks in ten minutes.

- **Check:** the server's latest deadlock report (`SHOW ENGINE INNODB STATUS`, the PostgreSQL log), and log lines
  with `outcome=failure` around the same `correlation_id`s.
- **Act:** make the two write paths take rows in the same order; an extension handler is the usual culprit.
- **Clears when:** fewer than five deadlocks occur in ten minutes.

### KumweLockTimeoutsFrequent

Ticket. More than five lock-wait timeouts in ten minutes.

- **Check:** the diagnostics surface's contention answer, or the server's lock views, for the session holding locks.
- **Act:** end the holding session (often an operator's open client transaction) and fix the long transaction.
- **Clears when:** fewer than five timeouts occur in ten minutes.

### KumweTransactionRollbackRateHigh

Ticket. More than 5% of transactions roll back.

- **Check:** the transport dashboard: deadlocks, lock timeouts and retries explain most rollbacks; the rest are
  refusals logged with `outcome=failure`.
- **Act:** address whichever of those dominates.
- **Clears when:** the ratio falls below 5% over fifteen minutes.

## Queues and scheduler

### KumweNoLiveWorker

**Page.** No worker heartbeat row exists, or the freshest is more than five minutes old.

- **Check:** the worker container's state and its last log lines (`runtime=worker`); a worker that exited on a stale
  runtime generation drains cleanly and removes its own heartbeat.
- **Act:** start the worker (`bin/kumwe queue:work`) under its supervisor; if it exits at once, materialize the
  runtime first.
- **Clears when:** a heartbeat is fresh.
- **Drill:** `no-live-worker` sends the only worker `SIGTERM`, so it drains and removes its heartbeat, then starts
  `bin/kumwe queue:work` again.

### KumweJobQueueStalled

Ticket. Workers are alive but the oldest due job has waited over fifteen minutes.

- **Check:** log lines `Job attempt failed; retry scheduled.` grouped by `job_type`; a queue with no worker assigned.
- **Act:** fix or discard the job type blocking the head of the queue; start a worker for the unserved queue.
- **Clears when:** the oldest due job is younger than fifteen minutes.

### KumweJobLeasesExpiring

Ticket. Claimed jobs hold expired leases.

- **Check:** workers killed mid-job (container restarts) and handlers whose runtime approaches their lease.
- **Act:** nothing is lost — the next claim reaps the lease; raise the lease or make the handler renew it.
- **Clears when:** no expired lease remains.

### KumweJobsDeadLettered

Ticket. The failed-job ledger holds rows.

- **Check:** `bin/kumwe automation jobs --token-file=<file>` (or the administrator Automation screen) lists the dead
  jobs with their exception; the log line `Job dead-lettered.` carries the `correlation_id` of the request that queued each one.
- **Act:** fix the cause, then `bin/kumwe automation retry --id=<job> --token-file=<file>`; cancel it only when the
  work no longer matters.
- **Clears when:** the ledger is empty — retrying or discarding removes the row.

### KumweJobRetryRateHigh

Ticket. A quarter of job attempts end in a retry.

- **Check:** the transport dashboard's job panels and `Job attempt failed; retry scheduled.` lines by `job_type` and
  exception class.
- **Act:** fix the transiently failing dependency; the completed-job rate on the business dashboard hides this cost.
- **Clears when:** the retry ratio falls below 25% over fifteen minutes.

### KumweSchedulerLagging

Ticket. A schedule is more than five minutes overdue.

- **Check:** the scheduler container (`runtime=scheduler` lines stop when it is down).
- **Act:** restart `bin/kumwe schedule:run --loop`; backups, verification and retention resume on their next tick.
- **Clears when:** no schedule is more than five minutes overdue.

## Integrations

### KumweOutboxBacklogAging

Ticket. The oldest undispatched event is more than five minutes old.

- **Check:** `Integration event dispatch failed.` and `deferred by queue backpressure` lines, by `event_type` and
  `transport`.
- **Act:** relieve the backpressure or fix the transport; events are durable and dispatch resumes on its own.
- **Clears when:** the oldest pending event is younger than five minutes.

### KumweOutboxDeadLetters

Ticket. Events have exhausted their delivery attempts.

- **Check:** the integration operations surface lists dead events with their failure.
- **Act:** fix the receiver, then replay the events (audited).
- **Clears when:** no dead event remains.

### KumweDispatchRetryRateHigh

Ticket. A quarter of dispatch attempts end in a retry.

- **Check:** dispatch failure lines by `transport` and exception class.
- **Act:** fix the flapping receiver or transport.
- **Clears when:** the ratio falls below 25% over fifteen minutes.

### KumweInboxBacklogAging

Ticket. The oldest unconsumed receipt is more than ten minutes old.

- **Check:** receipt-attempt failure lines by `consumer_id`; consumers are independent, so one consumer is usually
  responsible.
- **Act:** fix that consumer's dependency; its circuit reopens on its own.
- **Clears when:** the oldest pending receipt is younger than ten minutes.

### KumweInboxPoison

Ticket. Receipts are quarantined as poison.

- **Check:** the inbox operations view names the consumer, event and exception.
- **Act:** ship a signed handler revision that can handle the event; it gets exactly one delivery.
- **Clears when:** no poisoned receipt remains.

### KumweProcessWorkOverdue

Ticket. Long-running process work is more than fifteen minutes past due.

- **Check:** whether the integration worker runs and whether the process type's handler is still registered.
- **Act:** restore the handler or the worker; an overdue compensation is the urgent case.
- **Clears when:** no work is more than fifteen minutes overdue.

### KumweProjectionSequencingStalled

Ticket. Committed events have waited more than five minutes for a journal sequence.

- **Check:** `Projection source sequencing failed.` lines (`operation=projection.sequence`) and the integration
  worker's release.
- **Act:** restart the integration worker on the current release.
- **Clears when:** the oldest staged source is younger than five minutes.

## Reporting

### KumweExportQueueBacklog

Ticket. More than 25 exports are queued or running for thirty minutes.

- **Check:** the job queue signals first; then whether the export job type's handler is registered.
- **Act:** restore the worker or the handler.
- **Clears when:** the queue falls to 25 or fewer.

### KumweExpiredExportsRetained

Ticket. Expired export artifacts are still stored after an hour.

- **Check:** the `export_artifacts` retention schedule and its drain lines.
- **Act:** re-enable the schedule; the next drain deletes the bytes with their rows.
- **Clears when:** no expired artifact remains.

## Retention

### KumweRetentionBacklogStale

Ticket. A store has held drainable rows for more than six hours.

- **Check:** `Retention drain run finished.` and `Retention drain exhausted its budget before clearing the backlog.`
  lines by `store`; the store's schedule; the retention runs ledger.
- **Act:** re-enable the schedule, or raise its budget if every run ends `outcome=behind`.
- **Clears when:** the oldest drainable row is younger than six hours.

### KumweRetentionCapacityForecast

Ticket. A store will reach its capacity within three days at the current slope.

- **Check:** ingest against drain rate for the store on the platform dashboard.
- **Act:** raise the drain budget or shorten the retention window within policy.
- **Clears when:** the forecast exceeds three days.

### KumweRetentionReadinessFailed

Ticket. An enterprise-profile installation lacks a declared retention setting.

- **Check:** the retention readiness verdict in [Retention](retention.md#metrics-and-readiness) and the store
  settings it names.
- **Act:** configure the missing setting.
- **Clears when:** the verdict is ready or warning.

## Recovery

### KumweBackupStale

**Page.** No backup has recorded success for three hours (the recovery point objective is sixty minutes).

- **Check:** the backup timer (`systemctl status kumwe-backup.timer`) and the `runtime=backup` lines of the last cycle;
  the quiesce hook and the database credentials are the usual failures. A value of zero means backups were never
  recorded: confirm `KUMWE_OPERATIONS_STATUS_DIR` points the tools at the application's `storage/operations`.
- **Act:** fix the failing step and run `tools/backup-cycle.sh` (or `tools/backup.sh` under quiesce) now.
- **Clears when:** a backup records success.
- **Drill:** `backup-stale` runs a real `tools/backup.sh`, then one the database refuses (a stale credential), lets
  three hours and twenty minutes pass on the drill clock, then runs `tools/backup.sh` again.

### KumweBackupFailing

Ticket. The latest backup attempt failed after the last success (the stale page inhibits this one).

- **Check:** the failing cycle's `runtime=backup` lines, which carry one `correlation_id` across backup,
  verification and offsite copy.
- **Act:** fix that step; the next successful cycle clears the alert.
- **Clears when:** a later success is recorded.

### KumweRestoreFailed

**Page.** The latest restore or restore verification failed. Every backup cycle verifies its snapshot, so this
also fires when a snapshot you are relying on would not restore.

- **Check:** `runtime=restore` lines with `outcome=failure`; the `operation` label says whether it was a restore or
  a verification. A failed restore publishes nothing; its targets stay for inspection.
- **Act:** verify the previous snapshot with `tools/restore-verify.sh`; take a fresh backup if the latest is damaged.
  Never publish a partially restored target.
- **Clears when:** a later verification (or restore) of the same operation succeeds.
- **Drill:** `restore-failed` tampers with a real backup, verifies it, then verifies an intact one.

## Storage

### KumweStorageNearlyFull

**Page.** A storage volume on a replica has less than 5% free.

- **Check:** which volume (`storage`, `media`, `private`); the largest recent growth — report exports, audit
  archives, media originals.
- **Act:** free space or grow the volume now; expired exports are drained by their retention schedule.
- **Clears when:** free space is above 5%.
- **Drill:** `storage-nearly-full` fills a small real volume, then frees it (CI mounts a tmpfs for it).

### KumweStorageWillFillSoon

Ticket. At the last six hours' rate a volume fills within a day (the nearly-full page inhibits this one).

- **Check:** the volume's growth on the platform dashboard.
- **Act:** plan the cleanup or resize during working hours.
- **Clears when:** the forecast no longer reaches zero within a day.

## Extensions

### KumweExtensionRuntimeUntrusted

**Page.** A replica does not serve a trusted, verified extension runtime generation.

- **Check:** `bin/kumwe extension:runtime:materialize` output on the replica; a signing key mismatch between the
  image and the installation is the usual cause. This alert inhibits `KumweReadinessFailing` on the same replica.
- **Act:** run `bin/kumwe extension:runtime:materialize` to materialize the trusted generation, and make sure the
  runtime watcher is running. Never set `EXTENSIONS_ALLOW_UNSIGNED_LOCAL` in production to silence it.
- **Clears when:** the replica reports a trusted generation.
- **Drill:** `extension-runtime-untrusted` stops the watcher and alters the compiled runtime map so its signature no
  longer verifies, then materializes the trusted generation and starts the watcher.

### KumweRevocationFeedStale

Ticket. The key revocation feed is older than its staleness budget.

- **Check:** egress to `EXTENSIONS_REVOCATION_FEED_URL` and the feed's own warning lines.
- **Act:** restore egress; the next synchronization clears it.
- **Clears when:** a synchronization succeeds within the budget.

## Security

### KumweAuthenticationFailureBurst

**Page.** More than fifty sign-ins failed in ten minutes across the installation.

- **Check:** administrator and portal sign-in failure lines by origin; the attempt budget is already throttling each
  account and origin pair (throttling is inhibited while this fires).
- **Act:** block the source at the ingress; force a password reset for any account that was the target of a
  successful attempt from the same origin.
- **Clears when:** failures fall to fifty or fewer in ten minutes.
- **Drill:** `authentication-failure-burst` sends real failed sign-ins through the administrator login, then stops.

### KumweAuthenticationThrottling

Ticket. Account and origin pairs are being throttled.

- **Check:** whether one account or many; a monitoring integration with a stale password looks like an attack.
- **Act:** fix the integration's credential or block the origin.
- **Clears when:** throttles fall to ten or fewer in ten minutes.

### KumweTokenRejectionBurst

Ticket. Bearer tokens are rejected at an unusual rate.

- **Check:** REST, console and MCP failure lines; a revoked integration token is the usual cause.
- **Act:** reissue the integration's token or block the caller.
- **Clears when:** rejections fall to fifty or fewer in ten minutes.

### KumwePermissionDenialBurst

Ticket. Authorization denials are unusually frequent.

- **Check:** `Authorization decision.` warning lines by `subject`, `action` and `resource_type`.
- **Act:** suspend a probing principal; restore a grant a legitimate integration lost.
- **Clears when:** denials fall to two hundred or fewer in ten minutes.

## Observability

### KumweMetricsCollectionFailing

Ticket. The endpoint answers but cannot read its durable gauges; the alerts that read them are inhibited.

- **Check:** the runtime database account's rights on every application table, and the database itself.
- **Act:** grant the missing rights or restore the database connection.
- **Clears when:** a scrape collects without failure.

### KumweMetricsScrapeSlow

Ticket. Collecting gauges takes more than two seconds.

- **Check:** the database's load, and whether an index behind a gauge was dropped.
- **Act:** restore the index or relieve the database.
- **Clears when:** collection takes under two seconds.
