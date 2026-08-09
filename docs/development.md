# Development and testing

Kumwe develops and releases on PHP 8.5. Every persistence and deployment change is tested against MariaDB LTS, MySQL 8.4, and PostgreSQL 17. The development image contains Composer and both PDO driver families.

## Local checks

```bash
cp .env.example .env
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app composer qa
docker compose run --rm app php bin/kumwe database:migrate
docker compose up -d --wait
curl --fail http://localhost:8080/health/ready
npm ci
npm run check
npm run build
```

Individual checks:

```bash
composer architecture:policy
composer docs:api
composer cs
composer analyse
composer test:unit
composer test:integration
composer security:audit
npm run test:browser
```

Frontend dependencies are locked in `package-lock.json`. Production serves the committed hashed files under `public/assets/build`; rebuilding them must leave that directory unchanged. Browser tests run Chromium at desktop and mobile viewports, scan rendered pages against WCAG 2.2 AA rules, and compare screenshots under `tests/Browser/screenshots`.

The dedicated development-Compose acceptance workflow repeats the documented fresh installation on port 9900. It verifies the Compose-injected base URL, the host-port mapping, HTTP readiness, administrator and public CSS/JavaScript delivery, the database-seeded example homepage and menu, and readiness again after the 30-second runtime-marker lifetime. Changes to development startup, ports, routing, assets, or runtime materialization must keep this executable regression green.

Run integration tests once for each database group in [Getting started](getting-started.md#choose-another-database). A change that passes only the default database is not portable.

## Code and documentation standard

[The coding standard](coding-standard.md) is normative for every change. Every class, method, property,
class constant, and enum case carries a documentation block ending in `@since`, so the runtime source
reads as its own reference alongside the prose in this folder. `composer docs:api` fails the build when
a documentable member is missing a block, a description, a `@since`, a `@param`, or a `@return`, and
`composer docs:format` applies the alignment rules mechanically. Both tools are dependency free, so
they run before `composer install` and inside minimal images.

## Test ownership

- Add focused unit tests for every Kumwe-owned class with meaningful branching or invariants.
- Test repositories, migrations, transaction boundaries, locks, queues, and concurrency against real database services rather than database mocks.
- Test administrator and API authorization both positively and negatively for every capability.
- Test content, navigation, settings, identity, and extensions through their shared application services and through the relevant delivery surfaces.
- Test extension archives with traversal, links, duplicate paths, expansion limits, invalid manifests, compatibility failures, unknown keys, bad signatures, migration failures, and interrupted activation.
- Test worker retries, permanent failure classification, lease expiry, duplicate schedule occurrences, and restart behavior.

Coverage is a missing-test signal, not the release decision. New code must keep the configured line/branch floor, while security policies and state transitions require explicit behavior and mutation-resistant assertions.

## Full deployment contract

Pull-request CI must do more than run PHPUnit. For each supported database it:

1. builds the PHP 8.5 application and production web images from locked dependencies;
2. starts a clean database and Redis service;
3. runs forward migrations from an empty database;
4. creates an owner through the CLI;
5. starts nginx, PHP-FPM, worker, and scheduler;
6. waits for liveness and readiness;
7. exercises administrator login/CSRF/capabilities, public rendering, REST authentication/idempotency/concurrency, MCP initialization, queue work, and scheduling;
8. restarts application processes and proves durable state remains available;
9. scans source and the exact runtime images and publishes test evidence.
10. runs browser, responsive, accessibility, and visual-regression tests against a migrated installation.

Artifact tests separately install the Composer project and release ZIP into empty directories, apply configuration, migrate, start the application, and run the same acceptance probe. A release tag may publish images or archives only after these tests succeed.

## Recovery and release checks

Before a release:

1. create a complete backup with `tools/backup.sh`;
2. verify it with `tools/restore-verify.sh`;
3. restore it into empty database and filesystem targets with `tools/restore.sh`;
4. boot the restored site and run the deployment acceptance probe;
5. verify extension/runtime files and media checksums;
6. produce SBOMs, vulnerability reports, checksums, signatures, image digests, and provenance.

Backup/restore tooling must be exercised for every supported database engine. Site operators should also perform scheduled off-host recovery drills because CI cannot test site-specific storage, identity, proxy, or extension dependencies.

## Before opening a pull request

- Keep the worktree free of generated secrets, logs, database dumps, and built vendor files.
- Update OpenAPI and task documentation with behavior changes.
- Update the [architecture guide](architecture/README.md) only when an invariant or stable interface changes; do not add temporary progress notes.
- Run the narrowest test while developing, then the complete local quality suite and at least the default MariaDB deployment.
- Include the risk, migration, compatibility, and recovery implications in the pull-request description.
