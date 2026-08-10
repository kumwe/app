# Command-line interface

Run Kumwe's CLI from the installed release with the same environment as the web application:

```bash
php bin/kumwe list
```

In production Compose:

```bash
docker compose -f compose.production.yaml exec app \
  /usr/local/bin/kumwe-entrypoint php bin/kumwe list
```

Commands return `0` on success and a non-zero status on invalid input, unavailable dependencies, or failed work. Run them as the unprivileged application service account. Every token-authenticated management command requires both `--site=SITE` and `--token-file=PATH`; the selected site is validated and must exactly match the site bound into the token. The verified token subject becomes the audit actor. The token file must be absolute, non-symlinked, and readable only by its owner. Migration, bootstrap, worker, and scheduler commands use dedicated internal identities instead of bearer tokens. Extension package commands additionally rely on protected host/container access.

## Installation and health

| Command | Purpose |
|---|---|
| `php bin/kumwe-install` | Interactive Composer/ZIP configuration, migration, and owner setup |
| `database:migrate` | Apply pending forward-only migrations |
| `database:recover-lock` | CAS-remove one expired pre-advisory migration owner after a quiesced cutover |
| `database:status` | List pending migrations; returns `2` when work is pending |
| `app:health` | Check readiness from the application process |
| `user:create-admin` | Create the initial owner from a protected password file |

The owner command requires `--email`, `--name`, and an absolute `--password-file` whose group/other permission bits are clear.

Migrations use a database-session advisory lock plus a compatibility row. During an upgrade from a build that only
used the expiring row lock, stop every older application, worker, and scheduler before migrating. If an older process
crashed and its row has expired, read the exact `owner_token` from the `migration_locks` table and run:

```bash
php bin/kumwe database:recover-lock \
  --expected-owner=EXACT_64_HEX_OWNER \
  --confirm-legacy-quiesced
```

The command authorizes the system migration use case, verifies expiry, holds the new advisory namespace, and performs
an exact compare-and-delete. It will not clear an active, changed, malformed, or unconfirmed owner. Run
`database:migrate` immediately afterward.

## Tokens and access

```bash
php bin/kumwe token:create \
  --site=default \
  --email=owner@example.com \
  --name=deployment-integration \
  --capabilities=content.read,content.create,content.update \
  --expires-at=2027-01-01T00:00:00Z \
  --password-file=/run/secrets/kumwe-owner-password
```

The plaintext token is printed once. Route it directly to a secret manager and clear terminal capture according to site policy.

When a password-authenticated operator issues capabilities whose resource policies reach organization-sensitive
records, pass `--organization=IDENTIFIER` and, for a workspace-bound credential, also
`--workspace=IDENTIFIER`. Kumwe resolves that exact live membership from storage and embeds its membership and
policy generations in the token; the identifiers alone confer no authority. A workspace option without an
organization is refused. Issuance authorized by an existing `--token-file` cannot override either selection,
because its organization and workspace come only from the verified token.

CLI credentials use the `kumwe-cli` audience and a closed management purpose. Site, organization, workspace,
membership version, policy generation, security epoch, family, and delegation depth are revalidated before a
concrete command is authorized. A CLI flag cannot select an organization or workspace outside the token's live
membership. Commands that expose business records must use the same record and field policy plan as HTTP, MCP,
workers, and the portal.

## Generated business records

`business-record` prints a stable JSON success or error envelope and exposes no organization-selection flag.
Discovery/schema, list/get, lifecycle, actions, relations/reorder, history, approvals, bounded reports/exports, and
operation status all use the shared generated-business catalog, query decoder, exact-value projector, and
transactional record service.

| Action | Required action-specific options |
|---|---|
| `entities` | none |
| `schema` | `--definition` |
| `list`, `report`, `export` | `--definition`, optional `--query-file` |
| `get` | `--definition`, `--record`, optional `--query-file` |
| `create` | `--definition`, `--values-file`, `--operation-id`, optional `--record` |
| `update` | `--definition`, `--record`, `--expected-version`, `--values-file`, `--operation-id` |
| `archive`, `restore`, `delete` | definition, record, expected version, operation ID |
| `action`, `request-action` | definition, record, expected version, action, operation ID, optional input file |
| `relate`, `unrelate`, `reorder` | definition, record, version, relation input, operation ID |
| `history` | definition, record, optional bounded query file |
| `approvals`, `approval` | optional limit, or exact `--approval-request` |
| `operation` | `--operation-id` |

Every invocation also requires `--site` and `--token-file`. Mutation operation IDs are stable safe identifiers and
must be reused only for an identical retry. Existing-record mutations require the positive version last read.
Values, action input, query documents, target values, and reorder lists are accepted only from absolute,
owner-only, regular, non-symlinked files capped at 2 MiB; this avoids leaking PII or secrets through process lists.
For `get`, the query file uses the same bounded projection object as the other generated surfaces; for example,
`{"projection":{"fields":["name"],"includes":["members"]},"include_archived":false}`. At most 64 fields and
four declared relationships may be requested. Browse-only filters, sorting, pagination, and aggregates are rejected.
`request-action` creates the same exact maker-checker request as the browser, REST, and MCP facades, including
typed custom-action input validation. `approvals` and `approval` inspect only canonical `business_record` requests
whose active definition still declares the bound high-impact action for CLI. Unrelated approval families,
malformed bindings, stale actions, and exact denied details are omitted without enumeration. Checker visibility
still comes from approval authority and does not require the maker's action-execution grant. The CLI deliberately
exposes no approval vote: a decision still requires the browser's fresh, session-bound
step-up proof. The canonical approval binding includes the authenticated surface, and proof consumption accepts
only administrator or portal sessions, so a CLI-originated high-impact request also cannot be consumed by CLI.
Supplying `--approval-request` re-proves the exact binding and fails closed under that boundary; complete the
high-impact request, decision, and execution through one browser surface. Ordinary non-high-impact actions remain
fully executable and idempotent through CLI.

```bash
php bin/kumwe business-record list \
  --site=corporate \
  --token-file=/run/secrets/kumwe-cli-token \
  --definition=acme.sales_order \
  --query-file=/run/kumwe/query.json

php bin/kumwe business-record update \
  --site=corporate \
  --token-file=/run/secrets/kumwe-cli-token \
  --definition=acme.sales_order \
  --record=SO-00042 \
  --expected-version=7 \
  --operation-id=sales-order-42-update-0008 \
  --values-file=/run/kumwe/order-values.json
```

Success is `{"ok":true,"data":...,"meta":{"action":"...","surface":"cli"}}`. Failures have a stable
redacted `error.code` and non-zero status: 64 usage, 65 malformed data, 66 missing/denied, 69 unavailable, 73
conflict/version, 75 in progress, 77 authorization, 78 configuration, and 1 for an unexpected generic failure.
Exact decimals remain strings; internal keys, redaction markers, policy details, arbitrary exception messages,
secret values, and protected file paths never appear. See
[Generated business surfaces](architecture/generated-business-surfaces.md).

## Content

The `content` command prints JSON and calls the same content service as the administrator, API, and MCP:

```bash
php bin/kumwe content list --site=corporate --token-file=/run/secrets/kumwe-operator-token
php bin/kumwe content get --site=corporate --token-file=/run/secrets/kumwe-operator-token --id=CONTENT_ID
php bin/kumwe content create \
  --token-file=/run/secrets/kumwe-operator-token \
  --site=corporate \
  --title='About us' \
  --slug=about-us \
  --content-type=page \
  --data='{"body":"<p>About our team.</p>"}'
php bin/kumwe content update \
  --token-file=/run/secrets/kumwe-operator-token \
  --site=corporate \
  --id=CONTENT_ID --version=1 \
  --title='About our team' --slug=about-us \
  --data='{"body":"<p>Updated.</p>"}'
php bin/kumwe content transition \
  --token-file=/run/secrets/kumwe-operator-token \
  --site=corporate \
  --id=CONTENT_ID --version=2 --status=review
php bin/kumwe content trash --site=corporate --token-file=/run/secrets/kumwe-operator-token --id=CONTENT_ID --version=3
php bin/kumwe content restore --site=corporate --token-file=/run/secrets/kumwe-operator-token --id=CONTENT_ID --version=4
```

Use the current returned `version` for each mutation. Add `--deleted=1` to `content list` when recovery work needs trashed records.

### Content models

The `content-model` command manages the same site-scoped, immutable definitions used by the administrator and REST API. Set `--kind=content-type` or `--kind=workflow`; definition updates require the current positive `--version`.

```bash
php bin/kumwe content-model list --kind=workflow \
  --site=corporate --token-file=/run/secrets/kumwe-content-token
php bin/kumwe content-model create --kind=workflow \
  --site=corporate --token-file=/run/secrets/kumwe-content-token \
  --handle=legal-review --name='Legal review' \
  --states='[{"key":"draft","name":"Draft","initial":true,"public":false},{"key":"approved","name":"Approved","initial":false,"public":true}]' \
  --transitions='[{"from":"draft","to":"approved","required_capability":"content.publish"}]'
php bin/kumwe content-model create --kind=content-type \
  --site=corporate --token-file=/run/secrets/kumwe-content-token \
  --handle=article --name=Article --workflow=WORKFLOW_ID \
  --schema='{"type":"object","properties":{"body":{"type":"string"}},"required":["body"],"additionalProperties":false}'
```

Use `get` with `--id`, or `update` with `--id`, `--version`, and the complete replacement definition. Pass `--allow-breaking=1` only after deliberately reviewing consumers; historical content remains pinned to its earlier versions.

## Business definitions

The `business-definition` command drives the same versioned catalogue as the administrator and the REST API. Reads (`list`, `get`, `draft`, `history`, `compatibility`) require `content.read`; everything else requires `content.update`.

```bash
php bin/kumwe business-definition list --site=corporate --token-file=/run/secrets/kumwe-modeller-token

php bin/kumwe business-definition import --site=corporate --token-file=/run/secrets/kumwe-modeller-token \
  --definition-file=/run/secrets/asset-definition.json

php bin/kumwe business-definition compatibility --site=corporate \
  --token-file=/run/secrets/kumwe-modeller-token --handle=site.corporate.asset

php bin/kumwe business-definition publish --site=corporate --token-file=/run/secrets/kumwe-modeller-token \
  --handle=site.corporate.asset --expected-revision=4 --confirmed=1
```

`import` reads the document from a protected file rather than the command line, so a definition never reaches the process table. Inspect `compatibility` before publishing: a plan that changes behaviour or data refuses to publish unless you pass `--confirmed=1`. Use `--version` with `get` to read a specific published version, and `supersede`, `deprecate` or `reject` with `--version` to retire one.

## Business schema plans

The `business-schema` command inspects and applies compiled schema plans. Each stage names its own capability, so an operator can be granted inspection without approval, or approval without execution.

| Action | Capability |
|---|---|
| `definitions`, `plans`, `get` | `business.schema.read` |
| `plan` | `business.schema.plan` |
| `approve` | `business.schema.approve` |
| `execute` | `business.schema.execute` |
| `recover` | `business.schema.recover` |
| `purge-plan` | `business.schema.destructive` |

```bash
php bin/kumwe business-schema plan --site=corporate --token-file=/run/secrets/kumwe-schema-token \
  --definition=DEFINITION_ID

php bin/kumwe business-schema get --site=corporate --token-file=/run/secrets/kumwe-schema-token \
  --plan=PLAN_ID

php bin/kumwe business-schema approve --site=corporate --token-file=/run/secrets/kumwe-approver-token \
  --plan=PLAN_ID --expected-checksum=CHECKSUM

php bin/kumwe business-schema execute --site=corporate --token-file=/run/secrets/kumwe-executor-token \
  --plan=PLAN_ID
```

`get` returns the plan with its durable step journal and the canonical `checksum`. Approval binds to that exact checksum: if the plan changed after you inspected it, approval fails rather than applying something you did not read. High-impact and destructive plans additionally require `--confirmation` and recorded recovery evidence via `--evidence`; see [the transactional business runtime](business-runtime.md) for what that evidence must prove. After an interrupted execution, inspect the journal first, then use `recover`.

## Navigation

```bash
php bin/kumwe navigation list --site=corporate --token-file=/run/secrets/kumwe-operator-token
php bin/kumwe navigation create-menu \
  --site=corporate --token-file=/run/secrets/kumwe-operator-token --handle=main --title='Main menu'
php bin/kumwe navigation items --site=corporate --token-file=/run/secrets/kumwe-operator-token --menu=MENU_ID
php bin/kumwe navigation create-item \
  --site=corporate --token-file=/run/secrets/kumwe-operator-token --menu=MENU_ID \
  --title=About --slug=about --position=10 \
  --target-type=content --content=CONTENT_ID
php bin/kumwe navigation update-item \
  --site=corporate --token-file=/run/secrets/kumwe-operator-token --id=ITEM_ID --version=1 \
  --parent=PARENT_ID --title=Team --slug=team --position=20 \
  --target-type=content --content=TEAM_CONTENT_ID
```

Use `--target-type=anchor --target-url='#platform'` for a section link, or `--target-type=url --target-url=/administrator` for a safe custom destination. Other actions are `get-item`, `update-menu`, `delete-menu`, and `delete-item`. Updates and deletes require `--id` and `--version`; pass an empty `--parent=` for a root item.

## Settings

```bash
php bin/kumwe settings get --site=corporate --token-file=/run/secrets/kumwe-operator-token
php bin/kumwe settings update \
  --token-file=/run/secrets/kumwe-operator-token \
  --site=corporate \
  --site-name='Example site' \
  --homepage-content=CONTENT_UUID \
  --locale=en \
  --timezone=Africa/Windhoek \
  --search-indexing-enabled=1 \
  --presentation-logo=/media/LOGO_ID/logo.svg \
  --presentation-primary-menu=main \
  --presentation-active-scheme=corporate \
  --presentation-button-style=solid \
  --presentation-button-shape=rounded \
  --presentation-header-style=glass
```

Presentation options omitted from an update retain their current database values. Use `--presentation-footer=TEXT` for the public footer, `--presentation-schemes-json='[...]'` to replace the reusable validated scheme list, or `--presentation-json='{...}'` to replace the complete presentation object returned by `settings get`. Only browser-managed site settings belong here. Change database, Redis, proxy, release, and secret configuration through the deployment environment.

## Users, groups, and grants

```bash
php bin/kumwe access users --site=corporate --token-file=/run/secrets/kumwe-identity-token
php bin/kumwe access roles --site=corporate --token-file=/run/secrets/kumwe-identity-token
php bin/kumwe access tokens --site=corporate --token-file=/run/secrets/kumwe-identity-token
php bin/kumwe access create-user \
  --token-file=/run/secrets/kumwe-identity-token \
  --site=corporate \
  --email=editor@example.com --display-name='Site editor' \
  --password-file=/run/secrets/editor-initial-password --status=active
php bin/kumwe access create-role \
  --site=corporate --token-file=/run/secrets/kumwe-identity-token --code=editors --name=Editors
php bin/kumwe access assign-role \
  --site=corporate --token-file=/run/secrets/kumwe-identity-token --user=USER_ID --role=ROLE_ID
php bin/kumwe access grant \
  --site=corporate --token-file=/run/secrets/kumwe-identity-token --role=ROLE_ID \
  --capability=content.update --scope-type=global
```

Additional actions are `update-user`, `revoke-role`, and `revoke-grant`. User updates require the current `--version`. Password files must be absolute, non-symlinked, readable only by their owner, and removed after use.
Revoke API or MCP credentials immediately with `access revoke-token --site=corporate --token-file=... --token=TOKEN_ID`.

## Extensions

```bash
php bin/kumwe extension:list --site=corporate --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe extension:install /absolute/package.zip \
  --site=corporate --token-file=/run/secrets/kumwe-extension-token \
  --key-id=KEY --signature=BASE64
php bin/kumwe extension:activate vendor/name \
  --site=corporate --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe extension:activate vendor/theme \
  --site=corporate --surface=site --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe extension:disable vendor/name \
  --site=corporate --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe extension:uninstall vendor/name \
  --site=corporate --token-file=/run/secrets/kumwe-extension-token
php bin/kumwe theme:administrator:recover --confirm=restore-core-administrator
```

Restart long-running workers after extension lifecycle changes.

## Workers and schedules

```bash
php bin/kumwe queue:work --queue=default --sleep-ms=1000
php bin/kumwe queue:work --once
php bin/kumwe schedule:run --loop
php bin/kumwe automation create \
  --token-file=/run/secrets/kumwe-automation-token \
  --site=corporate \
  --name="Nightly extension map" \
  --cron="0 2 * * *" \
  --timezone=UTC \
  --job=extensions.runtime.rebuild \
  --payload='{}'
php bin/kumwe automation schedules --site=corporate --token-file=/run/secrets/kumwe-automation-token
php bin/kumwe automation jobs --site=corporate --token-file=/run/secrets/kumwe-automation-token
```

Use `automation enable`, `automation disable`, and `automation delete` with `--id` and `--version`.
Use `automation retry` or `automation cancel` with `--id` for queued jobs. Run long-lived worker and
scheduler processes under a supervisor; use one-shot forms for deployment diagnostics.

## MCP stdio

```bash
php bin/kumwe mcp:serve \
  --site=corporate \
  --token-file=/run/secrets/kumwe-mcp-token
```

The command reads MCP frames from standard input and writes protocol output to standard output. `--site` is required and the protected token must have been issued for that exact site. The token determines the tool capabilities; local shell access does not imply owner access. Run it under a dedicated service account with no database-administration or unrestricted host-filesystem access.

See [Automation](automation.md), [Extensions](extensions.md), [REST](rest-api.md), and [Operations](operations/README.md) for the underlying contracts.
