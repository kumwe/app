# Runnable production demonstration

The demonstration builds the same immutable PHP-FPM and nginx image targets used by production acceptance, starts the complete MariaDB, Redis, migration, application, web, worker, and scheduler topology, and creates an isolated administrator. It uses a dedicated Compose project and does not reuse development containers or data.

Docker Engine with Compose v2 and OpenSSL are required. From the repository root, run:

```bash
bash tools/production-demo.sh
```

When readiness passes, the command prints the local administrator address and the generated demonstration credentials. Open the address in a current Chromium, Firefox, or Safari browser. The local connector binds only to `127.0.0.1:18080`; a real deployment must remain behind the HTTPS reverse proxy described in the [deployment runbook](operations/deploy.md).

The useful review path is:

1. Open the dashboard and command palette.
2. Create a content item through generated fields, then move it through review and publication.
3. Upload an image in **Media** and choose it from a media field.
4. Create and reorder a nested item in **Navigation**, then open the public site.
5. Inspect the graphical content model and workflow builders.
6. Create a typed job in **Automation** without writing JSON.
7. Resize the browser to verify the mobile navigation and authoring layout.

Stop the stack and delete only its isolated containers, volumes, and generated demonstration secrets with:

```bash
bash tools/production-demo.sh down
```

For automated evidence, the pull-request workflow repeats this production topology on MariaDB, MySQL, and PostgreSQL. It also exercises administration, REST, MCP, authorization, idempotency, automation, restart persistence, backup, and clean restore. Browser evidence separately covers desktop and mobile rendering, WCAG 2.2 AA checks, and screenshot comparison.
