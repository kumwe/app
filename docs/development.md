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

## The quality contract

[`docs/quality/contract.json`](quality/contract.json) is the single definition of every check this
repository runs and of the lane — local, merge, nightly, release — each one runs in. Each entry names its
owner, its purpose, the artifact it produces, its cadence and the workflow and job that carries it. Read it
rather than reading four workflows and inferring the union.

`composer qa` is still the entry point a contributor uses, and it no longer restates the list: `composer
quality:contract` fails when a check declared for the local lane is missing from `qa`, when `qa` runs a
check the contract does not declare, when a check names a workflow or job that does not exist, or when the
job declared to carry a command no longer contains it. Nightly and release execute manifest-owned checks
through `php tools/quality-contract.php --run --cadence=nightly|release`; a check with an explicit binding
for that lane is delegated to the named provisioned job and is not run a second time by the generic process.
Adding a gate therefore means adding it to the contract and assigning any special runtime requirements to
the workflow job that provides them; the build says so if the two drift.

```bash
composer quality:contract                                # the contract matches what is executed
php tools/quality-contract.php --run --cadence=nightly   # execute one lane end to end
```

Individual checks:

```bash
composer architecture:policy      # textual predicates and the semantic dependency graph
composer architecture:dependencies
composer quality:contract
composer coverage:attribution
composer docs:api
composer openapi:check
composer translation:check
composer translation:strings
composer assets:direction
composer cs
composer analyse
composer test:unit
composer test:integration
composer test:idempotency -- --engine=mariadb
composer test:artifact
composer security:audit
composer security:secrets
npm run test:browser
```

`composer architecture:policy` now evaluates every dependency edge in `src/` against the layer graph in
[`docs/architecture/layers.json`](architecture/layers.json), not only the four textual predicates it used to
run. Edges that already pointed the wrong way are recorded in
[`docs/architecture/dependency-baseline.json`](architecture/dependency-baseline.json) with an owner, the
finding that removes them and an expiry. The baseline only ever shrinks: a new violation fails immediately,
an entry that no longer violates fails as stale so it has to be deleted, and an entry past its expiry fails
outright.

`composer test:idempotency` runs the integration suite again against the database the previous run left
behind and judges the result against
[`docs/quality/idempotency-baseline.json`](quality/idempotency-baseline.json). The formerly recorded six
second-pass failures have been removed and the baseline is empty. CI now appends both declared passes to the
ordinary integration run: `repeat` reuses the database in declaration order, then `reverse` lists the same
integration classes in reverse while preserving the methods' declaration order. This proves three consecutive
runs against one database on MariaDB, MySQL and PostgreSQL. Any new failure, stale exemption or expired entry
fails the gate. Run it after any change to a test that installs a definition, contribution or extension.

`composer test:artifact` is the deployed-artifact lane. It builds the released selection, installs it with
`--no-dev` and an authoritative classmap, seals the tree, and runs the regression cases in
[`docs/quality/deployed-artifact-cases.json`](quality/deployed-artifact-cases.json) inside it — the four
defects the last programme found only in production deployment acceptance. It needs no database and no
containers, so run it locally before you push anything that touches packaging, autoloading, archive reading
or key material.

`composer security:secrets` is the same pinned gitleaks scan the security workflow runs, and it reads
the branch's history rather than its working tree: a secret-shaped literal introduced by an earlier
commit still fails after a later commit changes it, so fixing one means rewriting the commit that
introduced it. It requires a Docker daemon and fails loudly without one, which is why it is not part of
`composer qa` — that suite is documented above as running inside the application container, where no
daemon is available.

For a change that touches a template, a stylesheet or a user-facing string, recompile the message
catalogues and re-run the two translation gates. `composer translation:compile` rewrites
`resources/localization/compiled/` from the XLIFF under `resources/localization/messages/`, and the
compiled artifact is committed like every other generated output:

```bash
composer translation:compile
composer translation:check
composer translation:strings
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

Compatibility fixtures in `tests/Fixtures/ExtensionApi` are immutable released inputs. Add a new schema/SPI fixture
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

- Add focused unit tests for every Kumwe-owned class with meaningful branching or invariants.
- Test repositories, migrations, transaction boundaries, locks, queues, and concurrency against real database services rather than database mocks.
- Test administrator and API authorization both positively and negatively for every capability.
- Test content, navigation, settings, identity, and extensions through their shared application services and through the relevant delivery surfaces.
- Test extension archives with traversal, links, duplicate paths, expansion limits, invalid manifests, compatibility failures, unknown keys, bad signatures, migration failures, and interrupted activation.
- Test worker retries, permanent failure classification, lease expiry, duplicate schedule occurrences, and restart behavior.

Coverage is a missing-test signal, not the release decision. New code must keep the configured floor, while
security policies and state transitions require explicit behavior and mutation-resistant assertions.

What "the configured floor" means is in [`docs/quality/coverage-contract.json`](quality/coverage-contract.json),
and `composer coverage:attribution` and `composer coverage:ratchet` execute it. Three things are worth knowing
before you write a test:

- **The canonical measurement is MariaDB.** It is the primary engine, and measuring anywhere else attributes
  one engine's driver branches and calls the result the product's coverage.
- **A behavioural test attributes what it exercises.** `#[CoversNothing]` is allowed on tests whose subject is
  not a class under `src/` — architecture and source-shape tests, template renders, shipped schemas — and on
  the behavioural tests that already carried it when the gate was switched on. That second list carries an
  owner and an expiry, only ever shrinks, and a new behavioural test cannot join it.
- **The ratchet judges your change, not the average.** At least 90% of the executable lines a change adds or
  edits under `src/` must be covered, and the global figure may not fall by more than a quarter of a point.
  The branch floor `pcov` could never measure has been replaced by one it can: at least 80% of the refusal
  lines — executable `throw` lines — a change adds or edits under Domain and Application logic must have
  been executed, because a covered `throw` line is line-level proof the refusing branch was actually taken.
  The contract entry records what it replaced and why, so the gate never looks stronger than it is.

## Full deployment contract

Pull-request CI must do more than run PHPUnit. For each supported database it:

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
- Run the narrowest test while developing, then the complete local quality suite and at least the default MariaDB deployment.
- Include the risk, migration, compatibility, and recovery implications in the pull-request description.
