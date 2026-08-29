# Upgrade Kumwe

Kumwe releases use forward-only Doctrine migrations serialized by a database-backed lock. Upgrade only from a healthy supported release and verify PHP, database, Redis, and extension compatibility before replacing running artifacts.

## Pre-stable 2.0 development reset

The canonical extraction of the extension manifest, package, runtime and author-facing SPI contracts into
`kumwe/extension-sdk` is an intentional ownership reset before the first supported 2.0 alpha. It changes the
self-checksums of these migration-ledger entries because their source now imports the canonical SDK types:

- `20260805040000_token_and_trust_lifecycles`
- `20260809010000_business_security_portal`
- `20260816010000_resource_ownership_scope`
- `20260817010000_interface_message_overrides`
- `20260821010000_period_posting_lock`
- `20260824020000_studio_host_sessions`

An installation made from an earlier, unsupported 2.0 development snapshot must be reinstalled from a clean
schema and its migration ledger established by the new baseline. Do not copy the old ledger forward or add
historical checksum exceptions. There is intentionally no legacy namespace loader, alias, remapping layer,
or dual App/SDK contract ownership.

## Procedure

1. Read the release notes and [verify](release-verification.md) checksums, signatures, provenance, SBOMs, and image digests.
2. Confirm the release supports the installed database engine and exact server line.
3. Verify every active extension against the target Kumwe and PHP versions.
4. Quiesce writes at the reverse proxy or maintenance boundary.
5. Create and independently verify a complete backup from the current release.
6. Pull or stage the target artifacts without replacing running processes.
7. Run the target migration command once; competing migration processes must wait or fail safely.
8. Start the target application, confirm readiness, then replace web and automation processes.
9. Exercise login, public rendering, a reversible draft mutation, menu read, capability denial, API idempotency replay, and one worker/scheduler iteration.
10. Record deployed digests and retain the previous artifacts and backup according to policy.

For Compose:

```bash
docker compose -f compose.production.yaml pull
docker compose -f compose.production.yaml run --rm migrate
docker compose -f compose.production.yaml --profile automation up -d --no-deps app web worker scheduler
curl --fail --silent http://127.0.0.1:8080/health/ready
```

For Composer or ZIP installations, stage the complete new release in a sibling directory, keep site-specific environment/secrets and persistent storage outside the release tree, run migrations from the staged release, then atomically switch the web-server release link. Do not overwrite vendor or source files in place.

## Failure handling

Schema migrations are forward-only. Do not attempt a down-migration against a live database. Keep writes closed and preserve logs. If the schema is healthy, deploy a compatible fixed application release. If durable state must be reverted, restore the verified pre-upgrade backup into an empty database and new media/extension targets, validate it in isolation, and then cut over.

Changing MariaDB, MySQL, or PostgreSQL engine is a data-migration project, not an ordinary Kumwe release upgrade. Rehearse logical export/import, type conversion, sequence/identity behavior, collation, timezones, extensions, query plans, and full acceptance tests before cutover.
