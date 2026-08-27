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
different value for a new state directory; Kumwe's profiles never contain a user or password. Unless
`KUMWE_DEMO_ACCESS=false` is exported, the script also provisions the full demonstration cast — the accountant,
clerk, bookkeeper, stockkeeper, and system administrator staff accounts plus the portal members of the fictional
client organizations — and writes their generated sign-ins to `demo-access-credentials.json` inside the same state
directory. That one file is how a reviewer enters every demonstrated role without any shared or seeded secret.

Unless `KUMWE_DEMO_EXTENSIONS=false` is exported, the script also installs the shipped example extensions —
announcements, asset inspection, the audit listener, and the Horizon site theme (installed as selectable; activating it stays an operator decision) — through the signed extension pipeline, so the
demonstration shows contributed screens, reports, durable jobs, and portal surfaces exactly as a real extension
would deliver them. Set the variable to a comma-separated subset such as `announcements` to install only some.
Open the address in a current Chromium, Firefox, or Safari browser. The local connector binds only to
`127.0.0.1:18080`; a real deployment must remain behind the HTTPS reverse proxy described in the
[deployment runbook](operations/deploy.md).

This production demonstration does **not** currently prove the completed Studio product journey. App's composed
Studio surface edits only the Blueprint of an existing Content-type version; the Content model and Entry still use
separate forms. Studio is therefore not expected as a top-level navigation item, but it is also not yet available in
the ordinary Content create/edit context as required by the product target. Do not present the current Blueprint
canvas, the `0.1.0-rc.1` package label, or the fallback forms as completed contextual authoring. The one App host
record and the acceptance that must be added to this demonstration are in
[Studio authoring in Kumwe App](studio-composition-authoring.md).

The useful review path is:

1. Open `/` and follow the installed documentation menu from **Start here** through **Operate Kumwe**.
2. Open **Content** and inspect the fields, revision, publication state, and ownership of a documentation page.
3. Create a content item through generated fields, move it through review and publication, and add it to the nested
   `main` menu.
4. Open **Business definitions** and inspect the twelve related VDM schemas — the consultancy graph plus the
   commerce graph of products, quotations with ordered lines, invoices, subscriptions, and domain registrations —
   with their workflows, views, actions, and exposure rules.
5. Open **Business** and trace a fictional client account through its service request, engagement, catalogue service,
   and work entries; then follow the commerce side from an accepted quotation to its invoice, subscription, and
   domains, comparing paid, issued, and overdue examples.
6. Sign in as each staff account from the credentials file — accountant, clerk, bookkeeper, stockkeeper, and system
   administrator — and compare which administrator areas and record operations each role can reach.
7. Sign in at `/portal` as a client member from the credentials file and confirm the member sees only that
   organization's quotations, invoices, subscriptions, and domains beside the shared product catalogue; accept the
   pending quotation as the Desert Bloom member to watch a portal workflow action.
8. Create a typed job in **Automation**, inspect reports/exports, and resize the browser to verify mobile behavior.

Stop the stack and delete only its isolated containers, volumes, and generated demonstration secrets with:

```bash
bash tools/production-demo.sh down
```

For automated evidence, the pull-request workflow repeats this production topology on MariaDB, MySQL, and PostgreSQL. It also exercises administration, REST, MCP, authorization, idempotency, automation, restart persistence, backup, and clean restore. Browser evidence separately covers desktop and mobile rendering, WCAG 2.2 AA checks, and screenshot comparison.
