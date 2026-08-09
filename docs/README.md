# Kumwe documentation

Use this index to install, operate, administer, integrate, extend, or evolve Kumwe.

## Install a site

- [Getting started](getting-started.md): launch the development stack and create the owner.
- [Configuration](configuration.md): administrator settings, environment variables, secrets, database selection, and Redis.
- [Production installation](operations/install.md): released Docker images, Composer project, and release ZIP.
- [Production deployment](operations/deploy.md): topology, image pinning, proxy boundary, and deployment checks.

## Use and administer Kumwe

- [Administrator](administration.md): content workflow, menus, users, groups, permissions, settings, tokens, extensions, and templates.
- [Business definitions](business-definitions.md): typed entities, fields, relationships, safe formulas, publication, and extension ownership.
- [Transactional business runtime](business-runtime.md): schema plans, typed relational records, bounded queries, recovery, and lifecycle.
- [Command-line interface](cli.md): installation, health, tokens, extensions, workers, schedules, and MCP stdio.
- [Workers and scheduler](automation.md): durable jobs, retries, recurring work, and worker operation.
- [Templates](templates.md): build, install, activate, and verify a public design.

## Integrate and extend Kumwe

- [REST API](rest-api.md): authentication, content, navigation, identity, optimistic concurrency, and retry safety.
- [MCP](mcp.md): stdio and Streamable HTTP transports, capabilities, tools, resources, and safe writes.
- [Extensions](extensions.md): manifests, providers, events, migrations, dependencies, signatures, lifecycle, and tests.
- [OpenAPI contract](../api/openapi/kumwe-v1.json): machine-readable REST v1 schema.

## Operate production

- [Runnable production demonstration](demonstration.md)
- [Operations index](operations/README.md)
- [Monitoring and health](operations/monitoring.md)
- [Backup and restore](operations/backup-restore.md)
- [Upgrade](operations/upgrade.md)
- [Release verification](operations/release-verification.md)
- [Incident response](operations/incident-response.md)
- [Security policy](../SECURITY.md)

## Maintain and evolve the project

- [Development and testing](development.md): local checks, database matrix, deployment tests, and release gates.
- [Architecture guide](architecture/README.md): stable boundaries, persistence choice, delivery parity, extension model, and growth paths.
- [Coding standard](coding-standard.md): documentation blocks, types, naming, structure, and errors, for human and agent contributors.
- [Contributing](../CONTRIBUTING.md): repository workflow and contribution requirements.

Run `php bin/kumwe list` in an installed release for the exact CLI command index. Public documentation describes released behavior; temporary plans and internal implementation status do not belong here.
