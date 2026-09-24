# Scale topology, query bounds and storage forecast

Roadmap packages `P5-G`, `P5-H` and `P5-I`. Companion pages: [Retention](retention.md),
[Scalable metrics](scale-metrics.md), [Capacity estimate](capacity-estimate.md).

## Supported topology (P5-H)

```
             load balancer (readiness: /health/ready, deploy gate: bin/kumwe app:health)
                  │
   ┌──────────────┼──────────────┐
   app replica 1 … app replica N        stateless PHP-FPM; no local authority
   └──────────────┼──────────────┘
                  │  bounded pool: PHP-FPM pm.max_children per replica
   worker pools (one queue:work / integration:work process group per class):
     interactive · sequencing · consumer fan-out · queues/schedules/processes ·
     reports/exports/projections · maintenance/retention
                  │
          one authoritative primary database (MariaDB / MySQL / PostgreSQL)
          optional replicas: eventual reporting only
          Redis: sessions, rate limits, metric counters, wake-ups — never authority
```

- **Authority.** Transactions, row locks, record versions, sequence rows, queue permits, fairness turns,
  idempotency claims, inbox receipts and the journal head live only in the primary database. Redis loss
  degrades wake-up latency and caches, never correctness (`docs/operations/monitoring.md`).
- **Connection budget.** Each PHP process holds one connection. Size
  `N × pm.max_children + Σ worker processes + 10` below `max_connections`, and alert when
  `kumwe_database_connections_in_use / kumwe_database_connections_max` passes 0.70 (normal) or 0.85
  (peak), the contract's utilisation limits. There is no application-side pool to exhaust; backpressure is
  the FPM listen queue plus the durable queues: saturated contributed queues defer outbox dispatch
  (`IntegrationDeliveryBackpressure`), claims skip locked rows instead of waiting, and every lock wait is
  bounded by the engine's lock timeout and reported as `kumwe_transaction_failures_total{class}`.
- **Readiness tied to generation and schema.** `/health/ready` answers from the signed local runtime
  marker for the loaded extension generation; `app:health` additionally checks the migration plan is
  complete and compatible, no non-transactional migration is unresolved, the loaded runtime generation is
  still current, retention is configured and not predicting exhaustion, and the database volume keeps its
  30% reserve.
- **Graceful drain and generation leases.** `queue:work` and `integration:work` finish the job in hand on
  `SIGTERM`/`SIGINT`/`SIGHUP`/`SIGQUIT`, bound handlers with a runtime deadline, and check the loaded
  runtime generation before every claim, exiting when it is stale so the supervisor restarts them on the
  current generation. Claims carry the runtime generation and a fencing token; a stale holder cannot
  settle. Record writers take the shared definition-generation fence, which a schema transition waits for
  and which new writers cannot pass once a transition holds it (`SchemaTransitionWriterFenceIntegrationTest`).
- **Fairness.** Contributed queues grant durable round-robin turns per site and organization before
  priority (`DoctrineJobQueueFairness`), consumer receipts rotate per consumer, site and organization and
  share one execution permit per consumer (`DoctrineInboxStore`), and a declared queue ceiling is a fixed
  set of permit rows (`DoctrineQueuePermits`). `QueuePermitOverclaimAndFairnessIntegrationTest` proves a
  site adding five jobs per claim cannot keep two smaller sites from their turns and that six racing
  claimants never exceed a ceiling of three. Report and export work is bounded per artifact by the row cap
  (100,000) and byte ceiling (128 MiB) and runs as queued jobs under the same queue fairness; a per-site
  cumulative export byte budget is not implemented.

## Query, index and reporting bounds (P5-G)

| Bound | Where enforced | Value |
|---|---|---|
| Page size | `kumwe/record-query` `RecordQuerySpecification` | 1–200 |
| Sorts | same | ≤ 5, no duplicate field |
| Filter depth / relation hops / operations | same | ≤ 8 / ≤ 2 / ≤ 64 |
| Pagination | same; `RecordCursorCodec` | keyset cursor only, signed, bound to the query digest |
| Row and field policy before joins, counts and paging | `DoctrineBusinessRecordQueryCompiler` with `BusinessRecordAccessPlan` | compiled into the `WHERE` |
| Value set | `RecordRequestGuard::values` | ≤ 256 fields, depth/node budget via `RecordValueGuard` |
| History window | `RecordHistoryQuery` | 1–200 revisions, cursor by version |
| Document payload | `DocumentWriteBudget` | declared line and byte budget |
| Interactive report rows | `kumwe/reporting` `ReportDefinition::synchronousRowCap` | 1–1,000 |
| Export rows / columns / cell length | `ReportService`, `RecordExportReportProvider` | ≤ 100,000 / 64 / 4,096 |
| Export bytes | `FilesystemExportArtifactStorage` | ≤ 128 MiB (max 512 MiB) |
| Administrator list depth | `DoctrineAccessControlRepository::MAXIMUM_OFFSET` (new) | offset ≤ 10,000; filtered walks examine ≤ 10,100 rows |
| Scrape and readiness probes | `RuntimeMetricCollector::PROBE_CAP`, `DoctrineRetentionObserver::PROBE_CAP` | 10,000 / 100,000 index entries |
| Exact diagnostics | `LedgerCensus` | ≤ 30 s server statement timeout |

Indexes for scope, identity, version and the fields a definition declares indexed, unique, sortable or
filterable are created by the physical schema compiler when the definition is installed, and `tests/Integration/Performance` holds the
declared hot plans (`docs/quality/hot-plans.json`) to an indexed access path on every engine.
Not yet bounded by code in this change: a per-statement execution-time cap on generated record browses
(the engines' lock and statement timeouts apply) and a declared result-byte cap on synchronous REST pages
beyond the 200-row page size.

## Storage forecast (P5-I)

`php tools/perf-storage.php --records=500` creates 500 ordinary records through `BusinessRecordService`
and measures per logical business transaction (LBT) on the same host as the other samples:

| Measured per LBT | MariaDB 10.11 | PostgreSQL 16 |
|---|---|---|
| Physical row mutations (PRM/LBT) | 8.0 (session handlers) | 10.0 (tuple counters) |
| Table bytes | 12,812 | 6,373 |
| Index bytes | 3,146 | 1,786 |
| Log bytes (binlog / WAL) | 19,946 | 11,671 |
| Undo indicator Δ over 500 LBT | +45 history list length | +500 dead tuples |
| Largest per-table growth | staging 3,146 + 262, revisions 3,047 + 1,081, audit 2,982 + 360, outbox 2,097 | revisions 1,376 + 541, outbox 1,180 + 360, staging 1,180 + 197, audit 1,163 + 180 |

Estimated at 5,000,000 LBT a day from those bytes (ordinary creates only; InnoDB sizes move in pages, so
the MariaDB figure is coarse): **MariaDB ≈ 80 GB/day of table+index growth and ≈ 100 GB/day of binary
log; PostgreSQL ≈ 41 GB/day and ≈ 58 GB/day of WAL**; 30 days ≈ 2.4 TB / 1.2 TB before retention. With the
30% reserve, provision ≈ 3.4 TB (MariaDB) or ≈ 1.7 TB (PostgreSQL) for a 30-day hot window, plus 2× the
largest table for an online rebuild. Replica network amplification equals the log bytes per replica.
Staging rows in the sample were not yet sequenced; in steady state they move to the journal and are
removed. Backup size and restore time come from the backup drills (`docs/operations/backup-restore.md`);
document-line, update-heavy and aged-data amplification are not measured here.

The **30% free-space reserve** is a readiness guardrail: set `KUMWE_DATABASE_DATA_PATH` to a directory on
the database data volume that the application host can see; `app:health` then warns (baseline) or fails
(`KUMWE_CAPACITY_PROFILE=enterprise`) when free space drops below 30%. Without the setting the guardrail
reports nothing and the operator must monitor the volume directly.
