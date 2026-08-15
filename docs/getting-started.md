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

## Choose the initial example data

`.env.example` starts a new database with two independent, versioned datasets:

```dotenv
KUMWE_SITE_CONTENT_PROFILE=documentation
KUMWE_BUSINESS_DEMO=true
```

The site-content choices are:

| Value | Initial managed site |
|---|---|
| `documentation` | Complete Kumwe learning site and navigation; the default |
| `placeholder` | Original compact **Welcome to Kumwe** example |
| `blank` | Empty primary menu and no homepage content |

`KUMWE_BUSINESS_DEMO=true` adds the separate VDM client-delivery example: client accounts, service catalogue items,
engagements, service requests, work entries, relationships, lifecycles, and an archived record. Every customer and
transaction is fictional, and the dataset contains no login credentials or secrets. Set it to `false` independently
of the site-content choice. A completely empty start therefore uses:

```dotenv
KUMWE_SITE_CONTENT_PROFILE=blank
KUMWE_BUSINESS_DEMO=false
```

Choose both values before the first `database:migrate`. Each dataset's choice is frozen independently when its first
reconciliation passes validation and begins. Once recorded, a later failure does not release it; retry with the same
value. A different recorded value is refused, preventing a configuration typo during an upgrade from replacing one
installed profile with another. Versioned site-content releases can add or correct
untouched fixtures while customized content remains unchanged. VDM releases may advance untouched definitions and
append new manifest operations. Operators may edit runtime records normally; migration never rewrites or retires an
applied VDM manifest create, relation, action, archive, or policy fixture.

## Start MariaDB, Redis, and Kumwe

```bash
git clone https://github.com/kumwe/app.git
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

Visit <http://localhost:8080/administrator> and sign in. With the default profile, a clean installation contains a
published Kumwe documentation site, a nested `main` menu, site settings, a VDM business example, and reusable Kumwe
logo media. They are ordinary managed records: inspect their fields and permissions, edit an example, or create a
new record alongside them.

## Build the first site

1. Open **Content**, edit one of the installed documentation pages, and verify the change on the public site.
2. Open **Content models** and review the graphical fields and workflow used by pages.
3. Create an **About us** page with the generated fields and save it as a draft.
4. Upload an image under **Media** and choose it from a media-enabled content field. The supplied SVG logos are reusable, read-only example assets.
5. Submit the draft for review and publish it.
6. Open **Navigation**, add the page as a typed **Page** target, and choose its label, URL segment, parent, and order.
7. Open `/about-us`. Nest another page below the item and verify its calculated URL, such as `/about-us/contact-details`.
8. Add a **Page section** target such as `#platform` or a validated custom URL when navigation is not a page route.
9. Open **Settings**, enter the site name, and select a managed page as the homepage. The stable content ID is stored, so changing its slug does not disconnect `/`.
10. Under **Users and access**, create an editor group, grant only the required content capabilities, and assign a test user.

Publishing dates are optional. A published page is public only after `publish_at` and before `unpublish_at` when those values are present.

## Explore the VDM business flow

When `KUMWE_BUSINESS_DEMO=true`, open **Business definitions** to inspect the five related schemas, views, actions,
workflow states, administrator exposure, and portal exposure. Then open **Business** and follow one fictional client
from its service request and engagement to related work entries and catalogue services. The mix of initial, advanced,
completed, and archived examples makes filtering, history, lifecycle controls, relations, and field policy behavior
visible without first constructing a business model by hand.

Create ordinary administrator and portal users explicitly and grant only the roles needed for the scenario you want
to test. The demonstration never creates a shared password, API token, or known production credential.

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
