# Kumwe documentation

Use this page as the index for installing, using, extending, integrating, deploying, and maintaining Kumwe.

## Site owners and editors

- [Getting started](getting-started.md): start a local installation and create the owner account.
- [Administrator and publishing](administration.md): configure the site, edit pages, use workflow, schedule publication, and manage extensions.
- [Templates](templates.md): install, activate, and create a public site design.

## Extension and integration developers

- [Extension development](extensions.md): package manifests, service providers, routes, events, dependencies, signatures, install, update, and removal.
- [REST API](rest-api.md): tokens, capabilities, content operations, ETags, retry safety, and the OpenAPI contract.
- [MCP](mcp.md): HTTP and stdio transports, authentication, exposed tools/resources, and safe usage.
- [Workers and scheduler](automation.md): job contracts, worker processes, cron schedules, retries, and failure handling.
- [Development and testing](development.md): local runtime, test suites, static analysis, coding standards, and release checks.

## Production operators

- [Install](operations/install.md)
- [Deploy](operations/deploy.md)
- [Monitor](operations/monitoring.md)
- [Back up and restore](operations/backup-restore.md)
- [Upgrade](operations/upgrade.md)
- [Verify releases](operations/release-verification.md)
- [Respond to incidents](operations/incident-response.md)

Also consult the [security policy](../SECURITY.md), the [REST OpenAPI document](../api/openapi/kumwe-v1.json), and `php bin/kumwe list` for the command-line index shipped with the installed release.
