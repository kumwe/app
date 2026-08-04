# Getting started

This guide starts a development installation with Docker Compose. MariaDB is the default; MySQL and PostgreSQL use the same application and commands.

## Requirements

- Docker Engine with Docker Compose v2
- Git
- Port 8080 available

## Start MariaDB, Redis, and Kumwe

```bash
git clone https://github.com/Kumwe/cms.git
cd cms
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d
curl --fail http://localhost:8080/health/ready
```

The source directory is mounted into the PHP 8.5 development container. MariaDB and Redis data persist in named volumes.

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
docker compose up -d
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

Visit <http://localhost:8080/administrator> and sign in.

## Build the first site

1. Create a page and save it as a draft.
2. Submit the draft for review and publish it.
3. Create a menu under **Navigation** and add the page.
4. Open **Settings**, enter the site name, and select the page slug as the homepage.
5. Open `/` and `/pages/{slug}` in a private browser window.
6. Under **Users and access**, create an editor group, grant only the required content capabilities, and assign a test user.

Publishing dates are optional. A published page is public only after `publish_at` and before `unpublish_at` when those values are present.

## Useful commands

```bash
docker compose run --rm app php bin/kumwe list
docker compose run --rm app php bin/kumwe database:status
docker compose run --rm app php bin/kumwe app:health
docker compose logs --tail=100 app database redis
docker compose down
```

Continue with the [administrator guide](administration.md), [configuration reference](configuration.md), or [production installation](operations/install.md).
