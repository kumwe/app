# Workers and scheduler

Kumwe stores jobs, attempts, leases, failed jobs, worker heartbeats, schedules, and occurrence keys in the configured relational database. MariaDB, MySQL, and PostgreSQL run the same worker and scheduler services through Doctrine DBAL.

Multiple workers safely claim work with database row locks. Transient failures use bounded exponential delay; permanent or exhausted failures remain available for inspection. Redis backs login rate limits and provides shared ephemeral cache/lock primitives, but it does not replace the durable queue.

## Manage automation in the administrator

Users with `automation.manage` can open **Automation** to create schedules, select a registered job type, set cron/timezone/queue/first run and a JSON payload, enable or disable schedules, delete schedules, inspect recent jobs, retry dead jobs, and cancel pending jobs. Every mutation is CSRF-protected and audited.

The administrator controls application-level automation records. Starting, stopping, or scaling worker and scheduler processes remains a deployment operation.

## REST integration

All routes require `automation.manage`. Mutations also require `Idempotency-Key`; versioned schedule changes require `If-Match`.

| Method | Path | Purpose |
|---|---|---|
| `GET`, `POST` | `/api/v1/schedules` | List job types/schedules or create a schedule |
| `GET`, `PATCH`, `DELETE` | `/api/v1/schedules/{id}` | Read, enable/disable, or delete a schedule |
| `GET` | `/api/v1/jobs?limit=100` | Inspect recent jobs |
| `POST` | `/api/v1/jobs/{id}/retry` | Retry a dead job |
| `POST` | `/api/v1/jobs/{id}/cancel` | Cancel a pending job |

## Run automation

```bash
php bin/kumwe queue:work --queue=default --sleep-ms=1000
php bin/kumwe schedule:run --loop
```

Production Compose runs the same commands as dedicated services:

```bash
docker compose -f compose.production.yaml --profile automation up -d worker scheduler
```

Use `queue:work --once` and `schedule:run` without `--loop` for deployment smoke tests. A built-in recurring schedule purges expired administrator sessions every 15 minutes.

## Manage schedules

```bash
php bin/kumwe automation create \
  --token-file=/run/secrets/kumwe-automation-token \
  --name="Rebuild extension runtime" \
  --cron="0 2 * * *" \
  --timezone=UTC \
  --job=extensions.runtime.rebuild \
  --payload='{}'
php bin/kumwe automation schedules --token-file=/run/secrets/kumwe-automation-token
php bin/kumwe automation jobs --token-file=/run/secrets/kumwe-automation-token
```

Cron expressions use minute, hour, day of month, month, and day of week. Lists, ranges, and steps are supported. Occurrences are calculated in the configured IANA timezone and stored in UTC. A unique occurrence key prevents duplicate dispatch by competing schedulers.

Built-in job types include `system.sessions.purge`, `extensions.runtime.rebuild`, and `content.workflow.transition`. Extension providers may register namespaced handlers with versioned payload schemas. A handler must be safe to retry because a process can terminate after its external side effect but before completion is recorded.

## Operating rules

- Run at least one worker and one scheduler for production features that depend on background work.
- Scale workers by queue after measuring queue age and execution time.
- Restart long-running processes after deploying code or activating extensions.
- Do not retry permanent validation or authorization failures.
- Include actor or system identity in jobs that mutate application state so the audit record remains attributable.
- Alert on pending-job age, failed jobs, expired leases, repeated retries, scheduler lag, and stale heartbeats.

Worker and scheduler process control is a deployment responsibility. Site administrators may define permitted schedules through application services, but browser users never receive shell or container control.
