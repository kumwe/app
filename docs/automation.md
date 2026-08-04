# Workers and scheduler

Jobs, attempts, leases, failed jobs, worker heartbeats, schedules, and occurrence keys are stored in PostgreSQL. Multiple workers safely claim work with row locking; transient failures use bounded exponential delay, and permanent or exhausted failures are retained for inspection.

## Run processes

```bash
php bin/kumwe queue:work --queue=default --sleep-ms=1000
php bin/kumwe schedule:run --loop
```

For production Compose:

```bash
docker compose -f compose.production.yaml --profile automation up -d worker scheduler
```

Use `queue:work --once` and `schedule:run` without `--loop` for smoke tests. A built-in schedule purges expired administrator sessions every 15 minutes.

## Manage schedules

```bash
php bin/kumwe schedule:create \
  --name="Rebuild extension runtime" \
  --cron="0 2 * * *" \
  --timezone=UTC \
  --job=extensions.runtime.rebuild \
  --payload='{}'
php bin/kumwe schedule:list
```

Cron expressions use five fields: minute, hour, day of month, month, and day of week. Lists, ranges, and steps are supported. Occurrences are calculated in the configured IANA timezone and persisted in UTC. A unique occurrence key prevents duplicate dispatch.

Built-in job types are `system.sessions.purge`, `extensions.runtime.rebuild`, and `content.workflow.transition`. Extension providers may register handlers in the job-handler registry; job types and payload schemas must be namespaced and versioned. A handler must be safe to retry because a worker can terminate after its side effect but before completion is recorded.

Monitor pending-job age, dead jobs, expired leases, and heartbeat freshness. Restart workers after deploying code or activating extensions.
