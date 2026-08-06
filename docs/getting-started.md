# Getting started

This guide starts a development installation with Docker Compose. MariaDB is the default; MySQL and PostgreSQL use the same application and commands.

## Requirements

- Docker Engine with Docker Compose v2
- Git
- One available host TCP port; `8080` is the default and is configurable

## Start MariaDB, Redis, and Kumwe

```bash
git clone https://github.com/Kumwe/cms.git
cd cms
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d --wait
curl --fail http://localhost:8080/health/ready
```

The source directory is mounted into the PHP 8.5 development container. MariaDB and Redis data persist in named volumes. The application container starts only after both dependencies are healthy, verifies the local extension runtime before accepting traffic, refreshes its readiness marker continuously, and uses the development router so committed CSS, JavaScript, images, and other public files bypass the application front controller.

## Choose another HTTP port

The application listens on port `8080` inside the container. `KUMWE_HTTP_BIND` and `KUMWE_HTTP_PORT` select the host interface and host port. `APP_BASE_URL` remains the canonical URL used by Kumwe, so its port must match the published host port.

For example, edit `.env` as follows to use <http://localhost:9900>:

```dotenv
KUMWE_HTTP_BIND=127.0.0.1
KUMWE_HTTP_PORT=9900
APP_BASE_URL=http://localhost:9900
```

Apply the updated mapping and wait for application readiness:

```bash
docker compose up -d --wait
curl --fail http://localhost:9900/health/ready
```

Set `KUMWE_HTTP_BIND=0.0.0.0` only when the development site intentionally needs to be reachable from other machines, and update `APP_TRUSTED_HOSTS` and `APP_BASE_URL` for the hostname clients will use.

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

Visit <http://localhost:8080/administrator> when using the default port and sign in.

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
docker compose logs --tail=100 app database redis
docker compose down
```

A `503` from `/health/ready` means the application has deliberately not accepted traffic. Inspect `docker compose logs app` for migration, database, Redis, extension-runtime, or filesystem errors. A successful `docker compose up -d --wait` confirms the container health check before returning.

Continue with the [administrator guide](administration.md), [configuration reference](configuration.md), or [production installation](operations/install.md).
