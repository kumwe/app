# Monitoring and health

## Current health contract

- `GET /health/live`: HTTP process liveness only.
- `GET /health/ready`: database connectivity and required migration readiness.
- `php bin/kumwe app:health`: CLI readiness used by the application container.

Alert when readiness remains unavailable after a deployment, but do not restart a
healthy process solely because PostgreSQL is briefly unavailable. Monitor the
PostgreSQL and Redis container health independently.

Minimum external signals are HTTP availability and latency, 5xx rate, FPM
saturation, PostgreSQL connections/locks/storage/replication, Redis memory and
evictions, queue age/depth/failures once workers are enabled, scheduler lag,
backup age and restore-drill age.

## Logging and metrics status

The current kernel logs to `php://stderr` through Monolog. Container logs must be
collected off-host with retention and access control. It does not yet install the
JSON formatter or redaction processor described by `config/observability.php`;
operators must not claim structured JSON logging until that kernel integration is
tested. Request bodies, credentials, cookies and authorization headers must never
be added to logs.

`config/observability.php` is the versioned target contract for required context,
redaction, health behavior and safe metric labels. Metrics and tracing default to
disabled because no exporter is wired into the kernel yet. Do not expose a
`/metrics` route publicly or configure dashboards against one until the adapter is
implemented and authenticated on an internal network.
