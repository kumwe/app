# Capacity estimate from sampled concurrency

`P7-A` under [ADR 0021](../roadmap/decisions/0021-automated-acceptance-and-sampled-capacity.md): no
dedicated hardware and no 24-hour or 72-hour run is required. Every run of the concurrent harness records
what it measured on the host it ran on, fits a labelled scalability model to those measurements, and
estimates larger topologies with the uncertainty and the extrapolation boundary stated. **Measured results
and estimates are kept apart** in the JSON report (`sections.measured` vs `sections.estimated`) and in the
markdown summary (`## Measured` vs `## Estimated (model output, not measurement)`). No figure here is a
supported production capacity, availability or recovery guarantee.

## Running it

```bash
source .agent-env                         # disposable test database, APP_ENV=testing
php tools/perf-harness.php --concurrent --workers=1,2,4 --samples=20 --warmup=5 --repeats=2
# outputs: build/perf/concurrent.json (schema: docs/operations/capacity-report.schema.json)
#          build/perf/concurrent.md   (the summary the capacity workflow publishes)
```

`.github/workflows/capacity.yml` runs the same command on MariaDB, MySQL 8.4 and PostgreSQL 17 for every
pull request (workers 1,2,4 × 30 samples × 3 repeats) and on dispatch with larger inputs, publishes the
markdown to the job summary and retains the JSON, raw per-call samples and logs as artifacts.

## What is recorded

- **Hardware and runtime:** CPU model and exposed logical CPUs, host RAM, visible cgroup CPU/memory limits,
  load average at start, OS, PHP version and extensions, native engine version, runner kind, source commit
  and working-tree digest, harness, lock-file and capacity-contract digests.
- **Database:** driver, server version and an allowlist of durability and sizing settings (MariaDB/MySQL:
  `max_connections`, `innodb_buffer_pool_size`, `innodb_flush_log_at_trx_commit`, `sync_binlog`,
  `innodb_log_file_size`, `transaction_isolation`, `log_bin`, `binlog_format`, `innodb_lock_wait_timeout`;
  PostgreSQL: `max_connections`, `shared_buffers`, `work_mem`, `synchronous_commit`, `fsync`, `wal_level`,
  `max_wal_size`, `default_transaction_isolation`, `checkpoint_timeout`).
- **Design:** worker counts, measured calls per worker per repeat, warm-up calls, repeats; each worker is a
  separate PHP process with its own container and database session, released together by a barrier.
- **Measured per repeat:** attempted/successful/failed calls, successful and failed latency distributions
  (nearest-rank p50/p95/p99, sample standard deviation, CV), wall time from barrier to the last call,
  successful LBT per second, the maximum number of observed overlapping calls, independent sessions, and
  integrity (read-back of every acknowledged record, row count, contiguous sequence numbers).
- **Measured per worker count (`summary`):** mean throughput across repeats with a **Student t 95%
  confidence interval** (`mean ± t₀.₉₇₅,ₙ₋₁ · s/√n`, n = repeats), throughput CV, pooled latency
  distribution, total errors and maximum overlap.

## The model

For each operation the harness fits, by least squares over every repeat that passed all integrity and
overlap checks, the **Universal Scalability Law**

    X(N) = λN / (1 + σ(N − 1) + κN(N − 1))

where N is the number of concurrent callers sharing the one database, λ the single-caller throughput,
σ the contention (serialised fraction) and κ the coherence (crosstalk) penalty, using the linearisation
`N/X(N) = 1/λ + (σ/λ)(N − 1) + (κ/λ)N(N − 1)`. It also fits USL with σ fixed at 0 and **Amdahl's law**
(κ = 0). Fits with a negative σ or κ are physically meaningless; they are reported with a warning and never
used for prediction. The prediction model is the physically valid fit with the highest R² (computed on X),
labelled good (R² ≥ 0.8), fair (≥ 0.5) or poor. With three worker counts USL has three parameters and
little residual freedom; its R² then says little, and more worker counts or repeats are the remedy.

Predictions are made for 1–128 total concurrent callers. **N application servers each running W callers
against one database are N × W callers** — the model describes the shared database and hot rows, and it
assumes application CPU, network and connection limits beyond the measured host do not bind. Every
prediction outside the measured worker range is marked `extrapolation`. The implied daily figure is the
predicted rate × 86,400 against the 5,000,000 LBT/day planning target. The separate `estimates` array
keeps the older per-worker-count time extrapolation (repeat mean × 86,400, observed range, no multiplier).

## Limitations

- Short samples on shared hardware with the database, Redis and all workers on one host: the workers
  compete with the database for the same CPUs, which depresses throughput and inflates κ relative to a
  topology with a separate database host. The fitted curve is a property of this host, not of Kumwe.
- Two or three repeats give very wide t intervals (a lower bound below zero is reported as computed; it
  means the repeat mean is not well determined, not that throughput can be negative).
- The workload is fresh single-site small creates and one shared legal-number counter; document lines,
  reads, aged data, background work, retention and the four-business mix are not in the figure.
- Physical row-mutation amplification is measured separately by `tools/perf-storage.php` (8.0 PRM/LBT on
  MariaDB, 10.0 on PostgreSQL in the local run) and is not folded into these throughput figures.
- Extrapolations assume the same data size and configuration at every concurrency.

## Local run, 2026-09-24

Host: 4 × Intel Xeon @ 2.80 GHz, 15.7 GiB RAM, no container limits visible; PHP 8.5.10 with
`kumwe_engine` 1.0.3; MariaDB 10.11.14 (binlog on, `innodb_flush_log_at_trx_commit=1`) and PostgreSQL
16.15, both stock and on the same host. Plan: workers 1, 2, 4; 20 measured calls per worker per repeat
after 5 warm-up; 2 repeats. All 12 repeats per engine passed integrity and overlap checks, 0 errors.

### Measured

| Engine | Operation | Workers | LBT/s mean | 95% CI | CV | p50 / p95 / p99 ms |
|---|---|---|---|---|---|---|
| MariaDB | ordinary_small_mutation | 1 | 17.0 | 0.8–33.1 | 0.11 | 41.6 / 117.6 / 119.9 |
| MariaDB | ordinary_small_mutation | 2 | 30.8 | 26.9–34.7 | 0.01 | 49.6 / 151.9 / 221.3 |
| MariaDB | ordinary_small_mutation | 4 | 24.9 | −14.3–64.2 | 0.17 | 131.7 / 325.8 / 531.4 |
| MariaDB | hot_sequence_commit | 1 | 19.7 | −15.7–55.1 | 0.20 | 41.2 / 88.9 / 274.1 |
| MariaDB | hot_sequence_commit | 2 | 19.5 | −14.8–53.9 | 0.20 | 86.6 / 244.7 / 617.7 |
| MariaDB | hot_sequence_commit | 4 | 25.5 | 11.1–39.9 | 0.06 | 133.2 / 261.8 / 406.0 |
| PostgreSQL | ordinary_small_mutation | 1 | 34.8 | −15.9–85.5 | 0.16 | 26.7 / 47.4 / 58.1 |
| PostgreSQL | ordinary_small_mutation | 2 | 31.1 | −6.6–68.8 | 0.13 | 42.8 / 147.8 / 203.5 |
| PostgreSQL | ordinary_small_mutation | 4 | 47.1 | −17.8–112.1 | 0.15 | 66.7 / 183.4 / 253.2 |
| PostgreSQL | hot_sequence_commit | 1 | 26.1 | −53.6–105.9 | 0.34 | 34.3 / 66.1 / 177.4 |
| PostgreSQL | hot_sequence_commit | 2 | 35.4 | −60.0–130.8 | 0.30 | 46.3 / 183.9 / 230.1 |
| PostgreSQL | hot_sequence_commit | 4 | 41.5 | −3.1–86.1 | 0.12 | 80.7 / 195.8 / 267.4 |

### Estimated (model output, not measurement)

| Engine | Operation | Model (quality) | λ | σ | κ | R² | 8 callers* | 16* | 64* | Daily at best predicted N |
|---|---|---|---|---|---|---|---|---|---|---|
| MariaDB | ordinary | USL σ=0 (fair) | 18.6 | 0 | 0.168 | 0.79 | 14.3 | 7.2 | 1.8 | peak N≈2.4 at 28.6 LBT/s: ≈ 2.5 M/day (49%) |
| MariaDB | hot sequence | Amdahl (poor) | 16.9 | 0.572 | 0 | 0.27 | 27.0 | 28.2 | 29.2 | asymptote 29.5 LBT/s: ≈ 2.5 M/day (51%) |
| PostgreSQL | ordinary | Amdahl (poor) | 27.5 | 0.488 | 0 | 0.25 | 49.9 | 53.0 | 55.5 | asymptote 56.4 LBT/s: ≈ 4.9 M/day (97%) |
| PostgreSQL | hot sequence | USL (fair) | 24.6 | 0.452 | 0.0029 | 0.50 | 45.6 | 46.6 | 38.5 | peak N≈13.9 at 46.6 LBT/s: ≈ 4.0 M/day (81%) |

\* LBT/s, extrapolations beyond the measured 1–4 callers. On this single 4-CPU host no model reaches the
5,000,000 LBT/day planning target within its valid region, and the MariaDB ordinary-create fit predicts
retrograde throughput past about 2–3 callers — consistent with four PHP workers and the database
competing for four CPUs rather than with a database-side limit. The poor and fair fit qualities and the
wide intervals mean these estimates only rank bottlenecks on this host; a larger sample on the capacity
workflow, or an operator run with the database on its own host, is required before any planning use.
Raw reports: `build/perf/concurrent-mariadb.json` and `build/perf/concurrent-pgsql.json` (not committed).
