# Command-line interface

Run Kumwe's CLI from the installed release with the same environment as the web application:

```bash
php bin/kumwe list
```

In production Compose:

```bash
docker compose -f compose.production.yaml exec app php bin/kumwe list
```

Commands return `0` on success and a non-zero status on invalid input, unavailable dependencies, or failed work. Run them as the unprivileged application service account. Content, navigation, settings, and access commands require `--token-file` and enforce the token's capabilities; the verified token subject becomes the audit actor. The token file must be absolute, non-symlinked, and readable only by its owner. Installation, migration, extension-package, and process-lifecycle commands additionally rely on protected host/container access.

## Installation and health

| Command | Purpose |
|---|---|
| `php bin/kumwe-install` | Interactive Composer/ZIP configuration, migration, and owner setup |
| `database:migrate` | Apply pending forward-only migrations |
| `database:status` | List pending migrations; returns `2` when work is pending |
| `app:health` | Check readiness from the application process |
| `user:create-admin` | Create the initial owner from a protected password file |

The owner command requires `--email`, `--name`, and an absolute `--password-file` whose group/other permission bits are clear.

## Tokens and access

```bash
php bin/kumwe token:create \
  --email=owner@example.com \
  --name=deployment-integration \
  --capabilities=content.read,content.create,content.update \
  --expires-at=2027-01-01T00:00:00Z
```

The plaintext token is printed once. Route it directly to a secret manager and clear terminal capture according to site policy.

## Content

The `content` command prints JSON and calls the same content service as the administrator, API, and MCP:

```bash
php bin/kumwe content list --token-file=/run/secrets/kumwe-operator-token
php bin/kumwe content get --token-file=/run/secrets/kumwe-operator-token --id=CONTENT_ID
php bin/kumwe content create \
  --token-file=/run/secrets/kumwe-operator-token \
  --title='About us' \
  --slug=about-us \
  --data='{"body":"<p>About our team.</p>"}'
php bin/kumwe content update \
  --token-file=/run/secrets/kumwe-operator-token \
  --id=CONTENT_ID --version=1 \
  --title='About our team' --slug=about-us \
  --data='{"body":"<p>Updated.</p>"}'
php bin/kumwe content transition \
  --token-file=/run/secrets/kumwe-operator-token \
  --id=CONTENT_ID --version=2 --status=review
php bin/kumwe content trash --token-file=/run/secrets/kumwe-operator-token --id=CONTENT_ID --version=3
php bin/kumwe content restore --token-file=/run/secrets/kumwe-operator-token --id=CONTENT_ID --version=4
```

Use the current returned `version` for each mutation. Add `--deleted=1` to `content list` when recovery work needs trashed records.

## Navigation

```bash
php bin/kumwe navigation list --token-file=/run/secrets/kumwe-operator-token
php bin/kumwe navigation create-menu \
  --token-file=/run/secrets/kumwe-operator-token --handle=main --title='Main menu'
php bin/kumwe navigation items --token-file=/run/secrets/kumwe-operator-token --menu=MENU_ID
php bin/kumwe navigation create-item \
  --token-file=/run/secrets/kumwe-operator-token --menu=MENU_ID \
  --title=About --slug=about --position=10
php bin/kumwe navigation update-item \
  --token-file=/run/secrets/kumwe-operator-token --id=ITEM_ID --version=1 \
  --parent=PARENT_ID --title=Team --slug=team --position=20
```

Other actions are `update-menu`, `delete-menu`, and `delete-item`. Updates and deletes require `--id` and `--version`; pass an empty `--parent=` for a root item.

## Settings

```bash
php bin/kumwe settings get --token-file=/run/secrets/kumwe-operator-token
php bin/kumwe settings update \
  --token-file=/run/secrets/kumwe-operator-token \
  --site-name='Example site' \
  --homepage-slug=home \
  --locale=en \
  --timezone=Africa/Windhoek \
  --search-indexing-enabled=1
```

Only browser-managed site settings belong here. Change database, Redis, proxy, release, and secret configuration through the deployment environment.

## Users, groups, and grants

```bash
php bin/kumwe access users --token-file=/run/secrets/kumwe-identity-token
php bin/kumwe access roles --token-file=/run/secrets/kumwe-identity-token
php bin/kumwe access tokens --token-file=/run/secrets/kumwe-identity-token
php bin/kumwe access create-user \
  --token-file=/run/secrets/kumwe-identity-token \
  --email=editor@example.com --display-name='Site editor' \
  --password-file=/run/secrets/editor-initial-password --status=active
php bin/kumwe access create-role \
  --token-file=/run/secrets/kumwe-identity-token --code=editors --name=Editors
php bin/kumwe access assign-role \
  --token-file=/run/secrets/kumwe-identity-token --user=USER_ID --role=ROLE_ID
php bin/kumwe access grant \
  --token-file=/run/secrets/kumwe-identity-token --role=ROLE_ID \
  --capability=content.update --scope-type=global
```

Additional actions are `update-user`, `revoke-role`, and `revoke-grant`. User updates require the current `--version`. Password files must be absolute, non-symlinked, readable only by their owner, and removed after use.
Revoke API or MCP credentials immediately with `access revoke-token --token-file=... --token=TOKEN_ID`.

## Extensions

```bash
php bin/kumwe extension:list
php bin/kumwe extension:install /absolute/package.zip --key-id=KEY --signature=BASE64
php bin/kumwe extension:activate vendor/name
php bin/kumwe extension:disable vendor/name
php bin/kumwe extension:uninstall vendor/name
```

Restart long-running workers after extension lifecycle changes.

## Workers and schedules

```bash
php bin/kumwe queue:work --queue=default --sleep-ms=1000
php bin/kumwe queue:work --once
php bin/kumwe schedule:run --loop
php bin/kumwe automation create \
  --token-file=/run/secrets/kumwe-automation-token \
  --name="Nightly extension map" \
  --cron="0 2 * * *" \
  --timezone=UTC \
  --job=extensions.runtime.rebuild \
  --payload='{}'
php bin/kumwe automation schedules --token-file=/run/secrets/kumwe-automation-token
php bin/kumwe automation jobs --token-file=/run/secrets/kumwe-automation-token
```

Use `automation enable`, `automation disable`, and `automation delete` with `--id` and `--version`.
Use `automation retry` or `automation cancel` with `--id` for queued jobs. Run long-lived worker and
scheduler processes under a supervisor; use one-shot forms for deployment diagnostics.

## MCP stdio

```bash
php bin/kumwe mcp:serve --token-file=/run/secrets/kumwe-mcp-token
```

The command reads MCP frames from standard input and writes protocol output to standard output. The protected token determines the tool capabilities; local shell access does not imply owner access. Run it under a dedicated service account with no database-administration or unrestricted host-filesystem access.

See [Automation](automation.md), [Extensions](extensions.md), [REST](rest-api.md), and [Operations](operations/README.md) for the underlying contracts.
