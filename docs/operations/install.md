# Install Kumwe in production

## Preconditions

- Docker Engine with Compose v2 support.
- A committed `composer.lock`. The production image deliberately refuses an
  unlocked dependency build.
- Three independent random secrets stored outside the repository.
- An empty PostgreSQL database volume.
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

The one-shot `migrate` service completes before `app` starts. Migrations are
forward-only and protected by a PostgreSQL advisory lock.

## Create the owner

Create the first administrator from a protected file mounted into the container:

```bash
install -m 0600 /dev/null /srv/kumwe/secrets/administrator-password
docker compose -f compose.production.yaml run --rm \
  --volume /srv/kumwe/secrets/administrator-password:/run/secrets/administrator-password:ro \
  app php bin/kumwe user:create-admin \
  --email=owner@example.com \
  --name="Site owner" \
  --password-file=/run/secrets/administrator-password
rm /srv/kumwe/secrets/administrator-password
```

Open `https://cms.example.org/administrator`, sign in, create and publish the
homepage, then select its slug under **Settings**.

## Start automation

```bash
docker compose -f compose.production.yaml --profile automation up -d worker scheduler
```

Confirm the worker and scheduler remain healthy and that `php bin/kumwe
schedule:list` reports the built-in session cleanup schedule.
