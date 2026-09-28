# Scalable metrics

Finding `V2-SCL-005`, roadmap package `P5-F`. `/metrics` must never run a table-scale exact aggregate
on the primary. This page records how each durable gauge is computed, what it cost at representative
size on each engine, and which low-cardinality metric classes exist.

## How gauges are computed

`RuntimeMetricCollector` runs a fixed set of statements whose cost does not grow with table size:

- **Depths** (`kumwe_jobs_pending`, `kumwe_outbox_pending`, …) count over a derived table capped at
  10,000 rows (`RuntimeMetricCollector::PROBE_CAP`). A capped value is a lower bound; the
  `kumwe_metrics_capped_gauges` gauge says how many depths hit the cap in that scrape.
- **Ages** read the first entry of an ordered index range (`ORDER BY col LIMIT 1`) instead of `MIN()`
  or `MAX()`. The retention migration adds the `(status, available_at)` job index and the
  `(status, created_at)` / `(status, first_received_at)` outbox and inbox indexes these probes use.
- **Retention figures** (`RetentionObserver`) cap at 100,000 index entries and use five-minute windows.
- **Server figures** (`kumwe_database_connections_in_use`, `…_max`, `kumwe_database_replica_lag_seconds`)
  read the engine catalogue with one constant-cost statement each; a figure the account may not read is
  published as 0.

The durable rows remain authoritative. The **exact** figure is an operator diagnostic:
`LedgerCensus::count(RetentionStore, timeoutMs)` (`DoctrineLedgerCensus`) declares cost class
`table_scan` and runs under a server-enforced timeout (MariaDB `SET STATEMENT max_statement_time`, MySQL
`MAX_EXECUTION_TIME`, PostgreSQL `SET LOCAL statement_timeout`, 1–30,000 ms). A timeout is returned as a
`timedOut` count, never an exception. It is an application service for the operator diagnostics surface;
no scrape, readiness poll or CLI command in this change calls it.

## Benchmark at representative size

`php tools/perf-gauges.php --rows=200000 --repeats=5 --drain-rows=50000` seeds 200,000 rows into `jobs`
and `integration_outbox` (10% pending) on the disposable test database, times the old exact statement
beside its bounded replacement (median of 5 after one warm-up) with the engine plan, then removes the
rows. Host: 4 × Intel Xeon @ 2.80 GHz, 15 GB RAM, database, PHP and tool on one machine; MariaDB
10.11.14 and PostgreSQL 16.15 with stock configuration. Reports: `build/perf/gauges-<engine>.json`.

| Gauge | MariaDB exact ms | MariaDB bounded ms | PostgreSQL exact ms | PostgreSQL bounded ms |
|---|---|---|---|---|
| `kumwe_jobs_pending` (20,000 matching) | 17.3 | 6.2 | 1.17 | 0.81 |
| `kumwe_jobs_dead` | 0.10 | 0.13 | 0.09 | 0.10 |
| `kumwe_jobs_oldest_due_age_seconds` | 0.05 (569 before the `(status, available_at)` index) | 0.11 | 0.14 | 0.11 |
| `kumwe_outbox_pending` (20,000 matching) | 30.5 | 4.9 | 1.45 | 1.28 |
| `kumwe_outbox_oldest_pending_age_seconds` | 14.2 (342 before the index) | 0.17 | 0.16 | 0.12 |
| retention ingest probe (outbox) | 0.07 | 0.09 | 0.08 | 0.10 |

Every bounded plan is an index range or index-only scan (plans are in the JSON). The bounded depth cost
is capped by `PROBE_CAP` regardless of backlog; the exact count's cost grows linearly with matching rows,
which is what the budget refuses. Budget: a scrape of all gauges stays under 250 ms at this size;
`kumwe_metrics_scrape_duration_seconds` reports the live figure. MySQL 8.4 was not available locally; the
capacity workflow runs it.

## Retention drain measurements

The same tool drains 50,000 expired business idempotency claims through the production drain with the
declared budget (30 s, batches 200→1,000, 250 ms lock budget, half duty cycle):

| Engine | Rows | Batches | Run time | Run rate | Sustained (×0.5) | Required |
|---|---|---|---|---|---|---|
| MariaDB 10.11 | 50,000 | 56 | 9.24 s | 5,411 rows/s | 2,706 rows/s | 926 rows/s |
| PostgreSQL 16 | 50,000 | 52 | 0.36 s | 139,546 rows/s | 69,773 rows/s | 926 rows/s |

Both exceed twice the enterprise peak expiry on this host. The drain ran on an otherwise idle database;
the concurrent-load figure is not measured here and remains an operator qualification.

## Low-cardinality metric classes

All labels are closed enumerations; nothing is labelled by site, user, record, event, document or raw
route. The whole exposition is bounded below 200 series (`MetricCatalogTest`).

| Class | Families |
|---|---|
| Operation class | `kumwe_operation_duration_seconds{operation_class}`: `document_100_line_commit`, `document_1000_line_commit`, `queue_time_to_start`, `delivery_age` |
| Transaction / lock / deadlock / retry | `kumwe_transactions_total{outcome}`, `kumwe_transaction_duration_seconds`, `kumwe_transaction_failures_total{class=deadlock\|lock_timeout}` (outermost transactions; a retried command shows as a rolled-back transaction followed by a committed one) |
| Document line / commit | `kumwe_document_lines_total`, document classes above (bytes are bounded by `DocumentWriteBudget` and not exported as a series) |
| Sequencing and dispatch | `kumwe_sequenced_events_total`, `kumwe_projection_staging_backlog`, `kumwe_projection_staging_oldest_age_seconds`, `kumwe_dispatch_settlements_total{outcome}` |
| Claim and settlement | `kumwe_queue_claims_total`, `kumwe_queue_settlements_total{outcome}` |
| Idempotency and retention | the per-`store` retention gauges for `business_idempotency` and `delivery_idempotency`, and `kumwe_retention_drained_rows_total{store}` ([Retention](retention.md)) |
| Connection saturation / replica lag | `kumwe_database_connections_in_use`, `kumwe_database_connections_max`, `kumwe_database_replica_lag_seconds` |
| Backlog age | the existing `*_oldest_*_age_seconds` gauges plus `kumwe_retention_oldest_age_seconds{store}` |
