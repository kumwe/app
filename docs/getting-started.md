# Getting started

This guide starts a development installation with Docker Compose. MariaDB is the default; MySQL and PostgreSQL use the same application and commands.

## Requirements

- Docker Engine with Docker Compose v2
- Git
- One available TCP port, `8080` by default

## Choose the local HTTP port

Development Compose uses `KUMWE_HTTP_PORT` as the single source of truth for the published host port and the application base URL. The default in `.env.example` is:

```dotenv
KUMWE_HTTP_HOST=localhost
KUMWE_HTTP_BIND=127.0.0.1
KUMWE_HTTP_PORT=8080
```

To use port `9900`, change only `KUMWE_HTTP_PORT=9900` before `docker compose up`. The application still listens on port 8080 inside the container, while Compose publishes `http://localhost:9900` on the host and injects the matching `APP_BASE_URL`. Set `KUMWE_HTTP_BIND=0.0.0.0` only when another machine must reach the development server, and add the exact host to `APP_TRUSTED_HOSTS`.

`APP_BASE_URL` remains the canonical URL setting for direct PHP execution and production mapping. Changing it by itself cannot change Docker's host-port publication.

## Start MariaDB, Redis, and Kumwe

```bash
git clone https://github.com/Kumwe/cms.git
cd cms
cp .env.example .env
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d --wait
curl --fail http://localhost:8080/health/ready
```

The source directory is mounted into the PHP 8.5 development container. MariaDB and Redis data persist in named volumes. The app service runs the dedicated development router so compiled styles, JavaScript, media, and other public files are served directly. It also verifies the extension runtime before accepting traffic and refreshes the readiness marker continuously; a healthy migrated installation therefore keeps `/health/ready` at HTTP 200 rather than expiring to 503.

The app container health check calls the real HTTP readiness endpoint. `docker compose up -d --wait` therefore returns only after the server is reachable, the database and Redis are available, and the local extension runtime is current.

When using another port, use the same value in the browser and readiness command, for example:

```bash
curl --fail http://localhost:9900/health/ready
```

## Choose another database

Set one coherent group in `.env` before the first migration:

| Engine | `DB_DRIVER` | `DB_PORT` | `DB_SERVER_VERSION` | `KUMWE_DATABASE_IMAGE` |
|---|---:|---:|---|---|
| MariaDB LTS | `mariadb` | `3306` | Version used by the selected image | `mariadb:lts` |
| MySQL 8.4 LTS | `mysql` | `3306` | `8.4` | `mysql:8.4` |
| PostgreSQL 17 | `pgsql` | `5432` | `17` | `postgres:17-alpine` |

Then start the database and run the same migration command. To change engines after creating local data, back up anything important and remove the old development volumes first:

```bash
docker compose down --volumes
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d --wait
```

`down --volumes` permanently deletes the development database and Redis data. It is not an upgrade method.

## Create the owner

Kumwe creates the first administrator only through the CLI. Public registration cannot claim ownership of a new installation.

```bash
install -m 0600 /dev/null .admin-password
# Add a password of at least 12 characters to .admin-password.
docker compose run --rm app php bin/kumwe user:create-admin \
  --email=owner@example.com \
  --name="Site owner" \
  --password-file=/app/.admin-password
rm .admin-password
```

Visit <http://localhost:8080/administrator> and sign in. Before a managed homepage is published, `/` displays Kumwe's responsive first-run presentation. It is a real styled public template and automatically gives way to the selected published homepage.

## Build the first site

1. Open **Content models** and review the graphical fields and workflow used by pages.
2. Create a page with the generated fields and save it as a draft.
3. Upload an image under **Media** and choose it from a media-enabled content field.
4. Submit the draft for review and publish it.
5. Create a `main` menu under **Navigation**, add the page, and order it.
6. Open **Settings**, enter the site name, and select the page slug as the homepage.
7. Open `/` and `/pages/{slug}` in a private browser window and confirm the managed menu appears.
8. Under **Users and access**, create an editor group, grant only the required content capabilities, and assign a test user.

Publishing dates are optional. A published page is public only after `publish_at` and before `unpublish_at` when those values are present.

## Useful commands

```bash
docker compose run --rm app php bin/kumwe list
docker compose run --rm app php bin/kumwe database:status
docker compose run --rm app php bin/kumwe app:health
docker compose ps
docker compose logs --tail=100 app database redis
docker compose down
```

A `503` from `/health/ready` means Kumwe is deliberately refusing readiness. Inspect `docker compose ps` and `docker compose logs app`; migration, database, Redis, extension-runtime, or filesystem failures are reported there rather than being hidden behind a generic browser error.

Continue with the [administrator guide](administration.md), [configuration reference](configuration.md), or [production installation](operations/install.md).
