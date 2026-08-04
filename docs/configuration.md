# Configuration

Kumwe separates site settings from deployment configuration.

- A user with `settings.manage` changes safe site behavior in **Administrator → Settings**. These values are audited and stored in the database.
- Operators provide infrastructure, credentials, trusted-network boundaries, and release identity through environment variables or protected secret files. These values are intentionally unavailable to browser users.

This prevents a compromised administrator session from changing database credentials, reading secrets, disabling HTTPS boundaries, or replacing the running release.

## Administrator settings

The settings screen manages:

| Setting | Purpose |
|---|---|
| Site name | Public name used by the active template |
| Homepage slug | Published page rendered at `/` |
| Default locale | Default language/locale identifier |
| Timezone | Site scheduling and display timezone |
| Search indexing enabled | Whether public indexing is permitted |

Saving settings requires `settings.manage`, a valid administrator session, and a CSRF token. The update records the actor and changed keys in the audit log. Settings supplied by an extension must declare a schema, validation rules, secret classification, and capability before they appear in the interface.

Disabling search indexing makes the dynamic `/robots.txt` disallow crawling and adds `X-Robots-Tag: noindex, nofollow, noarchive` to public page responses. It is a crawler instruction, not access control; keep private content unpublished and protected by authorization.

## Application environment

Start from `.env.example` for development. Production Compose maps operator-facing `KUMWE_*` variables into these application variables.

| Variable | Meaning | Production guidance |
|---|---|---|
| `APP_ENV` | `development`, `testing`, or `production` | `production` |
| `APP_DEBUG` | Detailed development failures | `false` |
| `APP_BASE_URL` | Canonical absolute URL | HTTPS URL |
| `APP_TRUSTED_HOSTS` | Comma-separated accepted hostnames | Exact public hosts |
| `APP_TRUSTED_PROXIES` | Comma-separated proxy address ranges | Only the actual proxy network |
| `APP_MAX_BODY_BYTES` | Maximum parsed request body | Match proxy and PHP limits |
| `APP_ADMIN_SESSION_SECONDS` | Administrator session lifetime | 300–604800 seconds |
| `APP_SECRET` | Session and application secret | At least 32 random bytes; prefer `APP_SECRET_FILE` in containers |
| `KUMWE_RELEASE` | Running release identifier | Exact deployed version |
| `EXTENSIONS_ALLOW_UNSIGNED_LOCAL` | Allow unsigned local packages | `false` in production |

Never set `APP_TRUSTED_PROXIES` to all networks. Kumwe must know which intermediary is allowed to supply forwarded request information.

## Database

| Variable | MariaDB default | MySQL | PostgreSQL |
|---|---|---|---|
| `DB_DRIVER` | `mariadb` | `mysql` | `pgsql` |
| `DB_HOST` | `database` | `database` | `database` |
| `DB_PORT` | `3306` | `3306` | `5432` |
| `DB_SERVER_VERSION` | Release-compatible MariaDB version string | `8.4` | `17` |
| `DB_TABLE_PREFIX` | `kumwe_` | `kumwe_` | `kumwe_` |

`DB_NAME`, `DB_USER`, and `DB_PASSWORD` identify the database. Production containers load the password from `DB_PASSWORD_FILE`. `DB_SSLMODE` accepts `disable`, `prefer`, `require`, `verify-ca`, or `verify-full`; public or cross-network database connections should use certificate verification.

The database account needs permission to create and alter Kumwe-prefixed tables while migrations run. It does not need global or server-administration privileges.

## Redis

| Variable | Purpose |
|---|---|
| `REDIS_HOST` | Redis hostname |
| `REDIS_PORT` | Redis port, normally `6379` |
| `REDIS_PASSWORD` | Redis authentication secret; use `REDIS_PASSWORD_FILE` in containers |
| `REDIS_DATABASE` | Logical database number, `0` by default |
| `REDIS_NAMESPACE` | Installation-specific key prefix, `kumwe.cms` by default |
| `KUMWE_REDIS_IMAGE` | Redis image reference used by Compose |

The supplied Compose deployment tracks the supported Redis 8 line for easy updates. Production change control should resolve and record the tested digest before rollout. Redis backs administrator-login throttling and supplies the shared runtime primitives for disposable caches and distributed locks; the selected relational database remains authoritative. Use a distinct namespace for installations that share a Redis endpoint.

## Secret files

The production entrypoint recognizes `APP_SECRET_FILE`, `DB_PASSWORD_FILE`, and `REDIS_PASSWORD_FILE`. Each file must be readable only by the deployment service, contain exactly one non-empty secret, and remain outside the repository and release package. The entrypoint loads the value into the process and unsets the file-variable name before launching PHP.

After changing process configuration, replace the affected web and worker containers. After changing administrator settings, no container restart is required.
