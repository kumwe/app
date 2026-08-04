# Production deployment

## Topology

`web` is the only service with a host port. It defaults to
`127.0.0.1:8080`; publish it through the site's TLS reverse proxy. `app` is a
non-root PHP-FPM process. PostgreSQL and Redis are attached only to the internal
backend network. The migration task completes before application readiness.

The PHP and nginx images are immutable. Writable runtime state is limited to
tmpfs mounts and the media volume. Containers drop Linux capabilities and set
`no-new-privileges`. PostgreSQL and Redis retain their own named volumes.

## Image pinning

The Compose defaults are readable upstream tags for local evaluation. Production
automation should override every image with an immutable digest:

```bash
export KUMWE_APP_IMAGE_REF=ghcr.io/kumwe/cms/app@sha256:APP_DIGEST
export KUMWE_WEB_IMAGE_REF=ghcr.io/kumwe/cms/web@sha256:WEB_DIGEST
export KUMWE_POSTGRES_IMAGE=postgres@sha256:POSTGRES_DIGEST
export KUMWE_REDIS_IMAGE=redis@sha256:REDIS_DIGEST
```

Never substitute example digest text directly. `KUMWE_APP_IMAGE_REF` and
`KUMWE_WEB_IMAGE_REF` are complete references; Compose does not append a mutable
tag to them.

The Dockerfile also accepts `PHP_IMAGE`, `COMPOSER_IMAGE` and `NGINX_IMAGE` build
arguments. Release engineering should pass verified digest references for all
three bases and record them in provenance.

## Proxy boundary

- Forward only the canonical hostname.
- Send `X-Forwarded-Proto: https` and the original host.
- Set `KUMWE_TRUSTED_PROXIES` to the proxy address ranges, never `0.0.0.0/0`.
- Keep the Compose HTTP binding on loopback or a private interface.
- Apply request-rate and connection limits at the edge as a second layer.

## Deployment check

```bash
docker compose -f compose.production.yaml config --quiet
docker compose -f compose.production.yaml up -d postgres redis
docker compose -f compose.production.yaml run --rm migrate
docker compose -f compose.production.yaml --profile automation up -d app web worker scheduler
docker compose -f compose.production.yaml ps
```

`/health/live` proves that the HTTP process can respond and does not query
dependencies. `/health/ready` checks the database migration ledger and returns
503 until the required schema is available. Readiness is the traffic gate;
liveness must not be used for dependency-driven restarts.

After every deployment, sign in to the administrator, read the configured
homepage, create and remove a disposable draft, execute one idempotent API retry,
and run `queue:work --once` plus `schedule:run` once from the exact application
image. Keep the previous verified image digests and backup until those checks pass.
