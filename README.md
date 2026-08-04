# Kumwe CMS

Kumwe is an extensible PHP content-management system for websites and portals. It combines a graphical administrator, structured content and publishing workflows, role-based access, menus, installable extensions and templates, REST and MCP integrations, durable automation, and reproducible production delivery.

## Start a local site

Docker Engine with Compose v2 is the shortest path. The default database is MariaDB.

```bash
git clone https://github.com/Kumwe/cms.git
cd cms
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d
curl --fail http://localhost:8080/health/ready
```

Create the owner account from a protected password file:

```bash
install -m 0600 /dev/null .admin-password
# Put a password of at least 12 characters in .admin-password.
docker compose run --rm app php bin/kumwe user:create-admin \
  --email=owner@example.com \
  --name="Site owner" \
  --password-file=/app/.admin-password
rm .admin-password
```

Open <http://localhost:8080/administrator>. The [getting-started guide](docs/getting-started.md) continues through the first page, menu, user group, and homepage configuration.

## Capabilities

- Create, revise, review, schedule, publish, unpublish, archive, trash, and restore content.
- Manage menus, nested navigation, users, groups, scoped capability grants, API tokens, site settings, extensions, and templates from the administrator when the signed-in user has permission.
- Apply the same content, navigation, identity, and extension rules through application services shared by the browser, CLI, REST, MCP, workers, and scheduler.
- Install signed plugins, components, modules, templates, languages, and packages without rebuilding the application image.
- Run optimistic, retry-safe API mutations with capability-scoped tokens, ETags, persisted idempotency results, revisions, and audit events.
- Run durable jobs and recurring schedules with bounded retries, leases, occurrence keys, and failure records.
- Deploy immutable nginx and PHP-FPM images with MariaDB by default, or select MySQL or PostgreSQL.

## Supported runtime

| Layer | Supported choice |
|---|---|
| PHP | 8.5 |
| Database | MariaDB current LTS (default), MySQL 8.4 LTS, PostgreSQL 17 |
| Persistence | Doctrine DBAL 4 with one portable schema and repository boundary |
| Redis | Current Redis 8 image line for cache, locks, rate limits, and coordination |
| Web | nginx and PHP-FPM release images |

Every database engine runs the same application services and migrations. Choose one engine per installation; Kumwe does not duplicate its CRUD or workflow pipeline by database.

## Installation choices

| Method | Best for | Guide |
|---|---|---|
| Released Docker images | Reproducible production and container platforms | [Production install](docs/operations/install.md#docker-images) |
| Composer project | PHP hosts and source-controlled deployments | [Production install](docs/operations/install.md#composer-project) |
| Release ZIP | Hosts without server-side Composer | [Production install](docs/operations/install.md#release-zip) |
| Git checkout and development Compose | Contributors and extension development | [Getting started](docs/getting-started.md) |

All methods install the same application. Configuration is supplied through environment variables or protected secret files, migrations run through `bin/kumwe`, and the first administrator is created explicitly; public registration cannot claim a new installation.

## Documentation

The [documentation index](docs/README.md) organizes guides by task:

| Goal | Guide |
|---|---|
| Install and configure a site | [Getting started](docs/getting-started.md) and [configuration](docs/configuration.md) |
| Administer content, navigation, users, and settings | [Administrator](docs/administration.md) |
| Operate Kumwe from a shell | [Command-line interface](docs/cli.md) |
| Integrate an application or AI client | [REST API](docs/rest-api.md) and [MCP](docs/mcp.md) |
| Create extensions, events, migrations, or templates | [Extensions](docs/extensions.md) and [templates](docs/templates.md) |
| Run jobs and schedules | [Workers and scheduler](docs/automation.md) |
| Deploy, monitor, back up, recover, and upgrade | [Operations](docs/operations/README.md) |
| Understand or evolve the design | [Architecture](docs/architecture/README.md) |
| Test and contribute | [Development](docs/development.md) and [contributing](CONTRIBUTING.md) |

The machine-readable API contract is [api/openapi/kumwe-v1.json](api/openapi/kumwe-v1.json). Run `php bin/kumwe list` from an installed release for the CLI command index.

## Verify a change

```bash
composer qa
```

Pull requests additionally build and start the complete CMS, migrate a clean database, exercise HTTP and CLI behavior, and run the supported database matrix. Release jobs build and scan the exact images and ZIP artifact, generate SBOMs and provenance, and publish signed checksums. See [Development and testing](docs/development.md) for the local and CI contracts.

## License

Copyright (C) 2022–2026 Llewellyn van der Merwe and contributors.

Kumwe is licensed under the GNU General Public License version 2.0; see [LICENSE](LICENSE).
