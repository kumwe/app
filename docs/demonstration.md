# Runnable production demonstration

The demonstration builds the same immutable PHP-FPM and nginx image targets used by production acceptance, starts
the complete MariaDB, Redis, migration, application, web, worker, and scheduler topology, and creates an isolated
administrator. It explicitly selects the complete Kumwe documentation site and Vast Development Method (VDM)
business example. It uses a dedicated Compose project and does not reuse development containers or data.

Docker Engine with Compose v2 and OpenSSL are required. From the repository root, run:

```bash
bash tools/production-demo.sh
```

When readiness passes, the command prints the local administrator address and freshly generated demonstration
credentials. The script stores those credentials only in its owner-only temporary state directory and generates a
different value for a new state directory; Kumwe's profiles never contain a user or password. Open the address in a
current Chromium, Firefox, or Safari browser. The local connector binds only to `127.0.0.1:18080`; a real deployment
must remain behind the HTTPS reverse proxy described in the [deployment runbook](operations/deploy.md).

The useful review path is:

1. Open `/` and follow the installed documentation menu from **Start here** through **Operate Kumwe**.
2. Open **Content** and inspect the fields, revision, publication state, and ownership of a documentation page.
3. Create a content item through generated fields, move it through review and publication, and add it to the nested
   `main` menu.
4. Open **Business definitions** and inspect the five related VDM schemas, workflows, views, actions, and exposure
   rules.
5. Open **Business** and trace a fictional client account through its service request, engagement, catalogue service,
   and work entries; compare active, completed, and archived examples.
6. Create a limited ordinary user through the normal user workflow and use it to validate portal visibility and
   denied fields without relying on a shared seeded password.
7. Create a typed job in **Automation**, inspect reports/exports, and resize the browser to verify mobile behavior.

Stop the stack and delete only its isolated containers, volumes, and generated demonstration secrets with:

```bash
bash tools/production-demo.sh down
```

For automated evidence, the pull-request workflow repeats this production topology on MariaDB, MySQL, and PostgreSQL. It also exercises administration, REST, MCP, authorization, idempotency, automation, restart persistence, backup, and clean restore. Browser evidence separately covers desktop and mobile rendering, WCAG 2.2 AA checks, and screenshot comparison.
