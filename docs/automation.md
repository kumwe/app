# Workers and scheduler

Kumwe stores jobs, attempts, leases, failed jobs, worker heartbeats, schedules, and occurrence keys in the configured relational database. MariaDB, MySQL, and PostgreSQL run the same worker and scheduler services through Doctrine DBAL.

Multiple workers safely claim work with database row locks. Transient failures use bounded exponential delay; permanent or exhausted failures remain available for inspection. Redis backs login rate limits and provides shared ephemeral cache/lock primitives, but it does not replace the durable queue.

For an active contributed queue, the signed lease, retry, cross-process in-flight, and terminal-row retention limits
are runtime policy for jobs and integration-event deliveries. A durable per-queue lock serializes every claim and
counts live job reservations plus inbox leases across replicas. Consumer/webhook and queue retry limits are
intersected, and normal delivery backpressure defers the outbox without consuming an attempt. Undeclared core
queues continue to use the established defaults.

## Manage automation in the administrator

Users with `automation.manage` can open **Automation** to create schedules, select a registered job type, set cron/timezone/queue/first run and a JSON payload, enable or disable schedules, delete schedules, inspect recent jobs, retry dead jobs, and cancel pending jobs. Every mutation is CSRF-protected and audited.

The administrator controls application-level automation records. Schedule creation uses typed graphical fields supplied by the registered job form; routine administrators do not author payload JSON. Trusted extensions may register graphical fields for their own job type through `AutomationJobFormRegistry`. Starting, stopping, or scaling worker and scheduler processes remains a deployment operation.

## REST integration

All routes require `automation.manage`, an exact site-bound bearer token, and `Kumwe-Site`. Mutations also require `Idempotency-Key`; versioned schedule changes require `If-Match`.

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
php bin/kumwe queue:work --queue=exports --sleep-ms=1000
php bin/kumwe schedule:run --loop
```

Production Compose runs the same commands as dedicated services:

```bash
docker compose -f compose.production.yaml --profile automation up -d worker scheduler
```

Use `queue:work --once` and `schedule:run` without `--loop` for deployment smoke tests. A built-in recurring schedule purges expired administrator sessions every 15 minutes.

The development server (`tools/development-server.sh`, used by development Compose and the browser test
harness) supervises one `queue:work --queue=exports` worker beside the HTTP server, so a CSV export
queued from a report or record workspace completes without a manually started worker. The supervisor
restarts the worker whenever extension reconciliation publishes a new runtime generation. Every other
queue still requires an explicitly started worker, exactly as in production.

## Manage schedules

```bash
php bin/kumwe automation create \
  --token-file=/run/secrets/kumwe-automation-token \
  --site=corporate \
  --name="Rebuild extension runtime" \
  --cron="0 2 * * *" \
  --timezone=UTC \
  --job=extensions.runtime.rebuild \
  --payload='{}'
php bin/kumwe automation schedules --site=corporate --token-file=/run/secrets/kumwe-automation-token
php bin/kumwe automation jobs --site=corporate --token-file=/run/secrets/kumwe-automation-token
php bin/kumwe automation queues --site=corporate --token-file=/run/secrets/kumwe-automation-token
php bin/kumwe automation purge-queue --queue=acme.example.priority --limit=100 \
  --site=corporate --token-file=/run/secrets/kumwe-automation-token
```

`automation queues` exposes job/delivery pending and in-flight breakdowns, their shared in-flight total, terminal
and retention-eligible counts, and the loaded runtime generation. `purge-queue` deletes retained terminal jobs and
their failure/ownership evidence; for terminal inbox deliveries it compacts payload and error detail but preserves
the receipt identity and status as a permanent duplicate tombstone.

Cron expressions use minute, hour, day of month, month, and day of week. Lists, ranges, and steps are supported. Occurrences are calculated in the configured IANA timezone and stored in UTC. A unique occurrence key prevents duplicate dispatch by competing schedulers.

Built-in job types include `system.sessions.purge`, `extensions.runtime.rebuild`, and `content.workflow.transition`. Extension providers may register namespaced handlers with versioned payload schemas. A handler must be safe to retry because a process can terminate after its external side effect but before completion is recorded.

`extensions.runtime.rebuild` and `system.idempotency.purge` are declared installation-global jobs. Their scope is persisted on both schedules and queued occurrences; they remain claimable if the site used to create them is later disabled or deleted, and execute only as their dedicated internal materializer or maintenance principal. Site-owned jobs remain joined to a live, enabled owner. Creating, listing, retrying, canceling, enabling, or deleting installation-global work requires a global `automation.manage` grant; a site-scoped grant cannot cross that boundary.

## Operating rules

- Run at least one worker for every active queue and one scheduler for production features that depend on background
  work. Report exports use the built-in `exports` queue; extensions declare their own queue names.
- Scale workers by queue after measuring queue age and execution time.
- Restart long-running processes after deploying code or activating extensions.
- Do not retry permanent validation or authorization failures.
- Include actor or system identity in jobs that mutate application state so the audit record remains attributable.
- Alert on pending-job age, failed jobs, expired leases, repeated retries, scheduler lag, and stale heartbeats.
- Capture a server-resolved organization/workspace membership and authorization digest when enqueueing protected
  work; revalidate membership, policy generation, security epoch, owner trust, and capability at execution time.
- Apply business record predicates and field usage before job selection, counts, aggregates, reports, or exports.
  Do not serialize an executable policy, SQL fragment, or client-supplied context into a job payload.
- A queued high-impact action carries only the immutable approval request identifier. Execution locks and spends
  the exact current approval and step-up proof; enqueueing never counts as approval.

Worker and scheduler process control is a deployment responsibility. Site administrators may define permitted schedules through application services, but browser users never receive shell or container control.
