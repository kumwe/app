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

Changes to demo manifests, reconciliation, migration startup, content/navigation/settings services, or generated
business behavior must also exercise fresh databases with:

- the default `documentation` plus VDM selection;
- `placeholder` content to prove the released compact example remains installable; and
- `blank` plus `KUMWE_BUSINESS_DEMO=false` to prove a clean installation remains clean.

Use a separate disposable Compose project or remove only a known test project's volumes between cases. Each dataset's
choice is deliberately persisted when its first reconciliation passes validation and begins. Reusing one database
with a different recorded value must fail rather than masquerade as a successful profile-switch test. Re-run a
completed migration with the same values to prove idempotence. For a higher site-content manifest, edit an installed
page and menu item to prove customization preservation. For VDM, prove an untouched definition may advance, a
customized definition is refused, new manifest operations may be appended, and changed or removed applied operations
and policies fail closed.

## Checks and feedback

Use `composer qa` for the normal PHP checks, and focused PHPUnit/browser cases while implementing.
The Composer scripts and workflow files show the executable commands. Historical inventories and
coverage percentages are not merge gates.

Ordinary code changes run static/semantic checks and unit tests once, host integration and functional
tests on the three supported databases in parallel, and desktop/mobile Chromium on MariaDB. Changes
that only edit prose do not boot runtime matrices. Obsolete runs are cancelled when a new commit arrives.

Scheduled/manual qualification runs the wider locale/browser matrix, repeat and reverse-order database
checks, recovery and deployment acceptance. Packaging, recovery and performance changes also select their
relevant bounded checks. Release verification still checks the exact artifact identities and signatures.

`composer test:database` runs integration and functional suites. `composer test:idempotency` is available
for changes affecting restart/replay behavior; it is not a requirement for an unrelated UI edit.
`composer test:artifact` checks packaging, autoloading, archive and key-material behavior.

Package repositories own reusable algorithm, value and schema tests. App tests prove its wiring,
authorization, persistence, delivery and extension lifecycle. Do not copy a package corpus or assert
source strings when an existing behavioral test already covers the host result.
`npm run check` verifies App schemas, assets, localization and exact Studio dependency wiring; its release check
already verifies the materialized Studio catalog. Full upstream corpus replay remains available explicitly as
`npm run check:studio-corpus` when investigating a dependency upgrade.

`composer architecture:policy` checks dependency direction. `composer security:audit` checks locked
dependencies; `composer security:secrets` checks repository history. The latter needs Docker locally.
Optional documentation/report generators remain editing aids, not a second definition of readiness.

For a change that touches a template, a stylesheet or a user-facing string, recompile the message
catalogues and re-run the translation gates. `composer translation:compile` rewrites
`resources/localization/compiled/` from the XLIFF under `resources/localization/messages/`, and the
compiled artifact is committed like every other generated output:

```bash
composer translation:compile
composer translation:check
composer translation:strings
composer translation:quality
composer assets:direction
git diff --exit-code resources/localization
```

[`docs/interface-translation.md`](interface-translation.md) states the message-identifier grammar, what
the gate treats as user-facing, and how the override chain resolves.

For a generated-business change, also run focused record/surface/OpenAPI/CLI/MCP tests, compile the golden contract,
and execute the neutral cross-surface browser fixture with JavaScript enabled and disabled:

```bash
composer openapi:compile
composer openapi:check
composer test -- tests/Unit/BusinessSurface tests/Unit/OpenApi
composer test -- tests/Integration/BusinessRecord tests/Functional
npm run test:browser -- --grep 'generated business'
git diff --exit-code api/openapi public/assets/build
```

Contract output must be byte-identical on a second compilation and across all supported database jobs. Generated
delivery acceptance covers metadata omission, exact strings, stale versions, replay/key reuse/in-progress, policy
cursor drift, cross-site/organization denial, oversized/chunked bodies, deep/wide filters, include fan-out, relation
choices, action/approval/history bindings, caller-bound status, and adapter dependency parity. See
[Generated business surfaces](architecture/generated-business-surfaces.md).

For a manifest-schema-4 or integration change, additionally build a fresh scaffold and the asset-inspection proof
twice, require identical package checksums, run static plus lifecycle conformance, and exercise the event/report
tests and deployment matrix:

```bash
php bin/kumwe extension:scaffold acme/conformance \
  --namespace='Acme\Conformance' --target=/absolute/tmp/conformance \
  --label='Conformance component' --version=1.0.0
php bin/kumwe extension:build /absolute/tmp/conformance --output=/absolute/tmp/conformance.zip
php bin/kumwe extension:conformance /absolute/tmp/conformance.zip
composer test -- tests/Unit/BusinessIntegration tests/Integration/BusinessIntegration
composer test -- tests/Unit/BusinessReporting tests/Integration/BusinessReporting
```

Compatibility fixtures in `vendor/kumwe/extension-sdk/resources/fixtures` are immutable released inputs. Add a new SDK schema/SPI fixture
for a new revision; never rewrite an older fixture to make a breaking parser change pass. See
[Business integrations and extension SDK](business-integrations.md#required-conformance-evidence).

Frontend dependencies are locked in `package-lock.json`. Production serves the committed hashed files under `public/assets/build`; rebuilding them must leave that directory unchanged. Browser tests run Chromium at desktop and mobile viewports, scan rendered pages against WCAG 2.2 AA rules, and compare screenshots under `tests/Browser/screenshots`.

The dedicated development-Compose acceptance workflow repeats the documented fresh installation on port 9900. It
verifies the Compose-injected base URL, the host-port mapping, HTTP readiness, administrator and public CSS/JavaScript
delivery, the selected documentation/menu fixtures and VDM records, and readiness again after the 30-second
runtime-marker lifetime. Changes to development startup, ports, routing, assets, profiles, or runtime materialization
must keep this executable regression green.

Run integration tests once for each database group in [Getting started](getting-started.md#choose-another-database). A change that passes only the default database is not portable.

## Code and documentation standard

[The coding standard](coding-standard.md) is normative for every change. Every class, method, property,
class constant, and enum case carries a documentation block ending in `@since`, so the runtime source
reads as its own reference alongside the prose in this folder. `composer docs:api` fails the build when
a documentable member is missing a block, a description, a `@since`, a `@param`, or a `@return`, and
`composer docs:format` applies the alignment rules mechanically. Both tools are dependency free, so
they run before `composer install` and inside minimal images.

## Test ownership

Add a focused regression when a changed behavior needs protection. Keep real-database tests for
transactions, locking, migrations, retries and recovery, and positive/negative authorization tests at
the shared application boundary. Existing user journeys should cover the visible result.

Packages own their reusable behavior; App owns host integration. Remove duplicate package cases rather
than maintaining two copies. Do not require a test for each trivial member or a source-code fingerprint.
Coverage reports help find gaps; they do not impose a percentage or attribution paperwork.

## Full deployment contract

Scheduled and release qualification exercises the deployed product on each supported database:

1. builds the PHP 8.5 application and production web images from locked dependencies;
2. starts a clean database and Redis service;
3. runs forward migrations from an empty database;
4. creates an owner through the CLI;
5. starts nginx, PHP-FPM, worker, and scheduler;
6. waits for liveness and readiness;
7. exercises administrator login/CSRF/capabilities, public rendering, REST authentication/idempotency/concurrency,
   MCP initialization, the signed asset-inspection component, outbox/inbox work, contributed jobs, reports/exports,
   queue work, and scheduling;
8. restarts application processes and proves durable state remains available;
9. scans source and the exact runtime images and publishes test evidence.
10. runs browser, responsive, accessibility, and visual-regression tests against a migrated installation;
11. disables/reactivates the proof component and verifies its owned data, event receipts, export metadata, checksums,
    and audit evidence after clean-target backup/restore on MariaDB, MySQL, and PostgreSQL.

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
- Run `composer security:secrets` for any change that adds a test fixture, an example, or configuration.
  Derive fixture values from a readable stem or a fixed label rather than writing random-looking
  literals; where a fixed vector is the point, allowlist that one fingerprint in `.gitleaksignore` with
  the reason beside it, never a path or a rule.
- Update OpenAPI and task documentation with behavior changes.
- Update the [architecture guide](architecture/README.md) only when an invariant or stable interface changes; do not add temporary progress notes.
- Run the focused tests while developing, then the normal checks and any additional lane relevant to the change.
- Include the risk, migration, compatibility, and recovery implications in the pull-request description.
