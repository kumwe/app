# Getting started

## Requirements

- Docker Engine with Docker Compose v2
- Git
- Ports 8080 and 5432 available to the Compose project

## Start the development stack

```bash
git clone https://github.com/Kumwe/cms.git
cd cms
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d
curl --fail http://localhost:8080/health/ready
```

The source directory is mounted into the PHP container. PostgreSQL and Redis data are retained in named volumes.

## Create the owner

Kumwe creates the first administrator only through the command line. Public registration can never claim ownership of a new installation.

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

## Publish the first page

1. Select **New page**, enter a title and URL slug, then save the draft.
2. Move the draft to **Review**, then to **Published**.
3. Open **Settings**, set the site name, and enter that page's slug as the homepage.
4. Visit <http://localhost:8080/>. The same page remains available at `/pages/{slug}`.

Publishing dates are optional. A published page is public only after `publish_at` and before `unpublish_at` when those values are present.

## Stop or reset the local installation

```bash
docker compose down
```

To delete the local PostgreSQL and Redis volumes as well, use `docker compose down --volumes`. That permanently removes the local site's data; create a backup first if it matters.

Use the [administrator guide](administration.md) next, or follow the [production installation](operations/install.md) for a public deployment.
