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
- administrator authentication failures, token failures, permission denials, and rate-limit decisions;
- extension activation failures and runtime-map generation;
- last successful backup verification and clean-target restore drill.

Define engine-specific database dashboards for MariaDB, MySQL, or PostgreSQL while keeping the application-level service objectives the same.

## Logs

Kumwe writes structured application logs to `php://stderr` through Monolog. Collect container or process logs off-host with retention, integrity controls, and restricted access. Correlate events with the request identifier and deployed release.

Never log request bodies by default. Credentials, authorization headers, cookies, passwords, secrets, session identifiers, plaintext tokens, and extension signing material must be redacted. `config/observability.php` records the safe-context and forbidden-label policy.

## Audit records

Application audit records are separate from diagnostic logs. They identify actor, action, target, outcome, time, and safe metadata for content, settings, access control, extensions, and automation. Restrict audit access, retain it according to site policy, and include it in incident preservation. Do not treat application logs as a substitute for audit history.
