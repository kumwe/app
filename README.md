# Kumwe CMS

Kumwe is an extensible PHP content-management system for websites and portals. It provides a browser-based administrator, structured page content, publishing workflow, installable extensions and templates, a versioned REST API, MCP access, durable jobs, scheduled tasks, and container-based deployment.

## What you can do

- Create, revise, schedule, publish, archive, trash, and restore pages.
- Select the site homepage and name from the administrator.
- Install signed plugins, components, modules, templates, languages, and packages without editing the application image.
- Change the public presentation by activating a template extension.
- Integrate through capability-scoped bearer tokens and the OpenAPI-documented REST API.
- Run MCP over Streamable HTTP or local stdio for discovery and review workflows.
- Execute retrying background jobs and recurring cron schedules with PostgreSQL-backed workers.
- Deploy a hardened nginx, PHP-FPM, PostgreSQL, Redis, worker, and scheduler stack.
- Create authenticated backups of the database, media, and installed extensions, then restore them into clean targets.

## Try Kumwe locally

Requirements: Docker Engine and Docker Compose v2.

```bash
cp .env.example .env
docker compose run --rm app composer install
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d
```

Create the first administrator from a protected password file:

```bash
install -m 0600 /dev/null .admin-password
# Put a password of at least 12 characters in .admin-password, then run:
docker compose run --rm app php bin/kumwe user:create-admin \
  --email=owner@example.com \
  --name="Site owner" \
  --password-file=/app/.admin-password
rm .admin-password
```

Open <http://localhost:8080/administrator>, create a page, move it from draft to review and then published, and set its slug as the homepage under **Settings**.

## Documentation

The [documentation index](docs/README.md) routes each audience to the relevant guide:

| Goal | Guide |
|---|---|
| Install and make the first website | [Getting started](docs/getting-started.md) |
| Create and publish content | [Administrator and publishing](docs/administration.md) |
| Build plugins, modules, or components | [Extension development](docs/extensions.md) |
| Build and activate a site template | [Template development](docs/templates.md) |
| Integrate another system | [REST API](docs/rest-api.md) and [MCP](docs/mcp.md) |
| Run jobs and schedules | [Workers and scheduler](docs/automation.md) |
| Deploy and operate production | [Production deployment](docs/operations/deploy.md) |
| Back up or recover a site | [Backup and restore](docs/operations/backup-restore.md) |
| Upgrade safely | [Upgrade](docs/operations/upgrade.md) |
| Test or maintain Kumwe | [Development and testing](docs/development.md) |
| Report a vulnerability | [Security policy](SECURITY.md) |

The machine-readable REST contract is [api/openapi/kumwe-v1.json](api/openapi/kumwe-v1.json). Run `php bin/kumwe list` inside the application container for the current CLI command index.

## Supported runtime

Kumwe requires PHP 8.4 or 8.5 and PostgreSQL 17. Redis 8 is included for operational services. Production releases are delivered as immutable application and web images, with signed checksums, provenance, SBOMs, dependency auditing, and container vulnerability scans.

## Quality checks

```bash
composer qa
bash tools/restore-verify.sh /absolute/path/to/backup
```

CI runs coding standards, maximum-level static analysis, PHPUnit on PHP 8.4 and 8.5 against PostgreSQL 17, dependency and source policy checks, a real backup/restore exercise, secret scanning, filesystem and image scanning, production image builds, and SBOM generation.

## License

Copyright (C) 2022–2026 Llewellyn van der Merwe and contributors.

Kumwe is licensed under the GNU General Public License version 2.0; see [LICENSE](LICENSE).
