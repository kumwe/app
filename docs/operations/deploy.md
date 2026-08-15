# Production deployment

This guide describes the supplied container topology. See [Install](install.md) for initial secrets and owner creation, and [Configuration](../configuration.md) for every application setting.

## Topology

`web` is the only service with a host port and defaults to `127.0.0.1:8080`. Publish it through a TLS reverse proxy or private load balancer. `app`, `worker`, `scheduler`, and `migrate` use the same immutable PHP image and configuration. `database` and Redis exist only on the internal backend network.

The migration task must complete before the application starts. Writable application state is limited to tmpfs plus media and extension volumes. Runtime containers are non-root, read-only, capability-dropped, and configured with `no-new-privileges`.

## Select and pin images

Release images are published at:

```text
ghcr.io/kumwe/cms/app:VERSION
ghcr.io/kumwe/cms/web:VERSION
```

Set complete image references before rendering Compose:

```bash
export KUMWE_APP_IMAGE_REF=ghcr.io/kumwe/cms/app@sha256:APP_DIGEST
export KUMWE_WEB_IMAGE_REF=ghcr.io/kumwe/cms/web@sha256:WEB_DIGEST
export KUMWE_DATABASE_IMAGE=mariadb@sha256:DATABASE_DIGEST
export KUMWE_REDIS_IMAGE=redis@sha256:REDIS_DIGEST
```

Use digests from [release verification](release-verification.md), not the example text. Mutable `latest`, major-line, and database LTS tags are convenient discovery aliases; a production rollout should record and deploy the exact tested digests.

## Database choice

MariaDB LTS is the default. Override one coherent set for another supported engine:

| Engine | `KUMWE_DB_DRIVER` | `KUMWE_DB_PORT` | `KUMWE_DB_SERVER_VERSION` | Example `KUMWE_DATABASE_IMAGE` |
|---|---:|---:|---|---|
| MariaDB LTS | `mariadb` | `3306` | Version matching the image | `mariadb:lts` |
| MySQL 8.4 | `mysql` | `3306` | `8.4` | `mysql:8.4` |
| PostgreSQL 17 | `pgsql` | `5432` | `17` | `postgres:17-alpine` |

Choose the engine before the first migration. Switching an existing site requires a separately tested logical data migration; changing only the image and driver variables is not a database conversion.

For a managed database, keep the application variables but omit or profile out the bundled `database` service in a deployment-specific Compose overlay. Use TLS verification, private networking, backups, monitoring, and a least-privilege migration account supplied by the database platform.

## Proxy boundary

- Forward only the canonical hostname.
- Replace client-supplied forwarding headers at the public edge, then send either RFC 7239 `Forwarded` or
  `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, and, for non-default ports, `X-Forwarded-Port`.
- Set `KUMWE_TRUSTED_PROXIES` to the exact IPv4/IPv6 addresses or CIDR ranges of every trusted proxy hop, never all
  networks. The immediate address observed by PHP must match this list before Kumwe uses any forwarded value.
- Keep the Compose HTTP binding on loopback or a private interface.
- Match request-size limits at the proxy, nginx, PHP, and Kumwe boundary.
- Apply connection and rate controls at the edge in addition to Kumwe's account and token controls.

## Extension trust boundary

Installing an extension means trusting its publisher with the application process. Kumwe's controls decide
what is **admitted** — signature, trust store, revocation feed, install-time admission — and what stays
admitted. They are not a runtime boundary around code that has already been admitted, and Kumwe does not
claim to be one. The supported tier is **trusted in-process extension code**; untrusted and marketplace
PHP is unsupported.

The consequence for this deployment is that the controls bounding an admitted extension are yours, not the
application's. The full inventory of the ambient authority such code inherits — filesystem, network,
environment, database and process — sits in
[Architecture: extensions](../architecture/extensions.md#trust-posture) beside the control for each. Five
of them belong on the deployment checklist:

- run the application, worker and scheduler as a dedicated unprivileged user, and mount the source
  read-only;
- default-deny outbound network egress and allowlist only the destinations the installation needs;
- grant the application's database account only the rights it uses, keeping DDL, backup and administrative
  rights on separate accounts, as the audit-trail triggers already require;
- disable the PHP process functions for the application SAPI where the deployment does not need them;
- treat every extension install as a change with the same review as a code deployment, because that is
  what it is.

## Deploy

```bash
docker compose -f compose.production.yaml config --quiet
docker compose -f compose.production.yaml pull
docker compose -f compose.production.yaml up -d database redis
docker compose -f compose.production.yaml run --rm migrate
docker compose -f compose.production.yaml --profile automation up -d app web worker scheduler
docker compose -f compose.production.yaml ps
curl --fail --silent http://127.0.0.1:8080/health/live
curl --fail --silent http://127.0.0.1:8080/health/ready
```

`/health/live` proves only that the HTTP process responds. `/health/ready` verifies database connectivity and the required migration ledger. Use readiness as the traffic gate; do not restart a healthy process merely because an external dependency is briefly unavailable.

## Post-deployment acceptance

From outside the stack and from the exact deployed application image:

1. sign into the administrator with a non-owner test account;
2. create, edit, review, publish, render, unpublish, trash, and restore a disposable page;
3. create a disposable menu item and exercise a permitted user/group change;
4. repeat one REST mutation with the same idempotency key and confirm replay behavior;
5. run `queue:work --once` and `schedule:run`;
6. confirm a capability-limited account receives `403` for a forbidden administrator and API action;
7. inspect logs, worker heartbeat, queue age, database health, and Redis health;
8. retain the previous image digests and verified backup until acceptance succeeds.

Also sign in through `/portal` as an ordinary user and verify administrator cookies cannot authenticate it; select
a permitted organization/workspace; prove a denied record is non-enumerable; exercise TOTP enrollment and one
fresh challenge; approve and spend a disposable maker-checker request; reject replay; then disable a test extension
and verify its contributed capability, policy, navigation, route, and template disappear without a restart.

CI performs an equivalent clean deployment for MariaDB, MySQL, and PostgreSQL before release. Site-specific acceptance remains necessary because proxy, TLS, storage, extensions, and identity policy differ by installation.
