# Clean Kumwe 2.x installation

## Preconditions

- Docker Engine with Compose v2 support.
- A committed `composer.lock`. The production image deliberately refuses an
  unlocked dependency build.
- Three independent random secrets stored outside the repository.
- An empty PostgreSQL database volume. Kumwe 1.x tables are not converted.
- A TLS-terminating reverse proxy in front of the loopback-bound web port.

Create secrets with restrictive permissions:

```bash
install -d -m 0700 /srv/kumwe/secrets
openssl rand -base64 48 > /srv/kumwe/secrets/app-secret
openssl rand -base64 32 > /srv/kumwe/secrets/database-password
openssl rand -base64 32 > /srv/kumwe/secrets/redis-password
chmod 0600 /srv/kumwe/secrets/*
```

Set deployment inputs in the operator shell or a protected deployment-system
environment. Do not commit them to `.env`:

```bash
export KUMWE_RELEASE=2.0.0
export KUMWE_BASE_URL=https://cms.example.org
export KUMWE_TRUSTED_HOSTS=cms.example.org
export KUMWE_TRUSTED_PROXIES=10.20.0.10
export KUMWE_APP_SECRET_FILE=/srv/kumwe/secrets/app-secret
export KUMWE_DB_PASSWORD_FILE=/srv/kumwe/secrets/database-password
export KUMWE_REDIS_PASSWORD_FILE=/srv/kumwe/secrets/redis-password
```

Validate the rendered model before it can create resources:

```bash
docker compose -f compose.production.yaml config --quiet
docker compose -f compose.production.yaml build app web migrate
```

The build expects `composer.lock`, installs production dependencies with an
authoritative class map, verifies PHP platform requirements, and puts only
`public/` in the nginx image.

Start the stack:

```bash
docker compose -f compose.production.yaml up -d
docker compose -f compose.production.yaml ps
curl --fail --silent http://127.0.0.1:8080/health/live
curl --fail --silent http://127.0.0.1:8080/health/ready
```

The one-shot `migrate` service must complete before `app` starts. It uses only
forward Kumwe 2.x migrations and PostgreSQL advisory locking. Do not import the
historical `sql/install.sql`; it is a Kumwe 1.x artifact.

## Automation activation gate

`worker` and `scheduler` exist under the Compose `automation` profile. The
current kernel must register `queue:work` and `schedule:run` before enabling it.
Verify this from the exact image being deployed:

```bash
docker compose -f compose.production.yaml run --rm app php bin/kumwe list
```

Only after both commands appear may operators start the profile:

```bash
docker compose -f compose.production.yaml --profile automation up -d worker scheduler
```

This gate prevents absent Phase 7 commands from becoming production crash loops.
