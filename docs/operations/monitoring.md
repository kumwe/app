# Monitoring and health

## Health contract

- `GET /health/live`: HTTP process liveness only.
- `GET /health/ready`: database connectivity and required migration readiness.
- `php bin/kumwe app:health`: CLI readiness used by the application container.

Alert when readiness remains unavailable after a deployment, but do not restart a
healthy process solely because PostgreSQL is briefly unavailable. Monitor the
PostgreSQL and Redis container health independently.

Minimum external signals are HTTP availability and latency, 5xx rate, FPM
saturation, PostgreSQL connections/locks/storage/replication, Redis memory and
evictions, queue age/depth/failures, worker heartbeat freshness, scheduler lag,
backup age and restore-drill age.

## Logging

Kumwe logs to `php://stderr` through Monolog. Collect container logs off-host
with retention and access control. Request bodies, credentials, cookies and
authorization headers must never be added to logs.

`config/observability.php` defines safe context and forbidden high-cardinality or
sensitive labels. Keep any infrastructure metrics endpoint private and enforce
the same redaction policy in the collector.
