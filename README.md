# Kumwe CMS

Kumwe is an extensible PHP content-management system for websites and portals. It combines a graphical administrator, structured content and publishing workflows, role-based access, menus, installable extensions and templates, REST and MCP integrations, durable automation, and reproducible production delivery.

## Start a local site

Docker Engine with Compose v2 is the shortest path. The default database is MariaDB.

```bash
git clone https://github.com/Kumwe/cms.git
cd cms
cp .env.example .env
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d --wait
curl --fail http://localhost:8080/health/ready
```

The copied environment selects the documentation site and Vast Development Method (VDM) business demonstration by
default. Choose the initial datasets before the first migration. For an empty installation, set:

```dotenv
KUMWE_SITE_CONTENT_PROFILE=blank
KUMWE_BUSINESS_DEMO=false
```

Site content and business data are independent choices. Each dataset's choice is frozen independently when its first
reconciliation passes validation and begins. Once recorded, a later failure does not release it; retry with the same
value. Later migration runs refuse configuration drift. Released site-content revisions may update or retire only
fixtures that still match Kumwe's last-applied state, so administrator customizations remain untouched. VDM
definitions may advance only while untouched. Operators may edit runtime records normally; applied VDM manifest
create, relation, action, archive, and policy checkpoints are immutable, and later manifests may append new operations
but may not rewrite or remove an applied fixture.

The development server serves compiled browser assets through the dedicated router and continuously verifies the local extension runtime, so `/health/ready` remains meaningful after startup. To use another host port, change the single Compose setting in `.env` before starting the services:

```dotenv
KUMWE_HTTP_PORT=9900
```

Compose then publishes `http://localhost:9900`, injects the matching application base URL, and keeps the container listening internally on port 8080. `KUMWE_HTTP_BIND` controls the host interface and defaults to `127.0.0.1`; changing `APP_BASE_URL` alone does not publish a Docker port. Run `docker compose up -d --wait` after changing the listener so Compose recreates the app service and waits for HTTP readiness.

Create an administrator account from a protected password file:

```bash
install -m 0600 /dev/null .admin-password
# Put a password of at least 12 characters in .admin-password.
docker compose run --rm app php bin/kumwe user:create-admin \
  --email=owner@example.com \
  --name="Site owner" \
  --password-file=/app/.admin-password
rm .admin-password
```

Repeat the same command with a different email address whenever another full administrator is required. The host-authorized command reuses the canonical `administrator` role, restores any missing global administrator grants, and refuses an existing email without changing that account's password. Run all pending migrations first; the password file must be absolute inside the container, readable by the application user, and inaccessible to group and other users.

Open <http://localhost:8080/administrator>. The [getting-started guide](docs/getting-started.md) continues through the
editable Kumwe documentation site, typed menu links, the VDM business workflow, user groups, and site configuration.
A default database ships with published documentation, navigation, settings, realistic fictional business records,
and read-only Kumwe logo media. These are managed records, not hardcoded screens or authentication credentials.

## Capabilities

- Create, revise, review, schedule, publish, unpublish, archive, trash, and restore content through generated graphical fields and workflow controls.
- Search and filter content, manage reusable media, order nested menus, and render the same managed navigation in the built-in public presentation.
- Manage menus, nested navigation, users, groups, scoped capability grants, API tokens, site identity, reusable color schemes, interaction styles, extensions, and templates from the administrator when the signed-in user has permission.
- Apply the same content, navigation, identity, and extension rules through application services shared by the browser, CLI, REST, MCP, workers, and scheduler.
- Install signed plugins, components, modules, templates, languages, and packages without rebuilding the application image.
- Graphically author and immutably publish typed business definitions with exact fields, relationships, views, actions, workflows, safe formulas, compatibility plans, and extension-owned contributions.
- Enforce organization and workspace memberships, deny-overrides record and field policies, maker-checker approvals,
  replay-safe step-up authentication, and scoped delegation through every delivery surface.
- Serve an isolated ordinary-user portal with its own sessions, CSRF boundary, account security, approvals, and
  signed extension workspaces, navigation, templates, and routes.
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

All methods install the same application. Configuration is supplied through environment variables or protected secret files, migrations run through `bin/kumwe`, and administrator accounts are created explicitly; public registration cannot claim a new installation.

## Documentation

The [documentation index](docs/README.md) organizes guides by task:

| Goal | Guide |
|---|---|
| Install and configure a site | [Getting started](docs/getting-started.md) and [configuration](docs/configuration.md) |
| Administer content, navigation, users, and settings | [Administrator](docs/administration.md) |
| Define operational entities and extension schemas | [Business definitions](docs/business-definitions.md) |
| Configure policy, approvals, step-up, and scoped authority | [Business security](docs/business-security.md) |
| Use or extend the ordinary-user portal | [Ordinary-user portal](docs/portal.md) |
| Operate Kumwe from a shell | [Command-line interface](docs/cli.md) |
| Integrate an application or AI client | [REST API](docs/rest-api.md) and [MCP](docs/mcp.md) |
| Create extensions, events, migrations, or templates | [Extensions](docs/extensions.md) and [templates](docs/templates.md) |
| Run jobs and schedules | [Workers and scheduler](docs/automation.md) |
| Deploy, monitor, back up, recover, and upgrade | [Operations](docs/operations/README.md) |
| Understand or evolve the design | [Architecture](docs/architecture/README.md) |
| Test and contribute | [Development](docs/development.md) and [contributing](CONTRIBUTING.md) |
| Run the complete CMS locally | [Production demonstration](docs/demonstration.md) |

The machine-readable API contract is [api/openapi/kumwe-v1.json](api/openapi/kumwe-v1.json). Run `php bin/kumwe list` from an installed release for the CLI command index.

## Verify a change

```bash
composer qa
npm ci
npm run check
npm run build
npm run test:browser
```

Pull requests additionally build and start the complete CMS, migrate a clean database, exercise HTTP and CLI behavior, run browser/accessibility/responsive/visual tests, and run the supported database matrix. Release jobs build and scan the exact images and ZIP artifact, generate SBOMs and provenance, and publish signed checksums. See [Development and testing](docs/development.md) for the local and CI contracts.

## License

Copyright (C) 2022–2026 Vast Development Method.

Kumwe is licensed under the GNU General Public License version 2.0; see [LICENSE](LICENSE).
