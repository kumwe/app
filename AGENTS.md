# Working on Kumwe

Kumwe App is the host application for the Kumwe packages. Keep it small, reliable and easy to extend.
Work on the requested behavior, use the existing package APIs, and leave a change a human can maintain.

## Current maintainer direction — 2026-09-29

Finish the working application and Studio authoring experience. Reduce duplicated package tests,
bookkeeping gates, documentation overhead and the time needed to get useful feedback from a commit.
This direction supersedes older instructions that require an expanding qualification programme,
per-member documentation, approval records, inventory updates or fixed coverage percentages for every edit.

Performance evidence uses bounded concurrent samples. Record the workload, sample size, variation,
hardware and limitations. Neither a 24-hour run nor a 72-hour endurance run is a release prerequisite.
Do not add one. Measured samples support an estimate, not an enterprise capacity guarantee.

## Delivery

- Continue the assigned PR branch. Preserve other contributors' work and push coherent, verified changes.
- Use parallel agents for independent work. Do not create competing implementations or integration branches.
- Agents implement, review and validate. The maintainer merges App PRs; do not merge or publish an unaccepted release.
- Make routine engineering decisions without repeatedly requesting permission. Ask only about a material
  unresolved product choice, missing authority or a genuinely destructive action.
- Say exactly what passed, what failed and what remains. Do not equate a green subset with complete readiness.

## Setup and useful checks

```bash
bash tools/agent-setup.sh
. ./.agent-env
composer qa
npm run check
npm run build
```

The setup script reports which capabilities are available. Use focused tests while implementing:

```bash
vendor/bin/phpunit tests/Unit/Studio
vendor/bin/phpunit --filter RelevantBehavior
composer test:database
npm run test:browser -- --grep 'relevant journey'
```

`composer qa` is the normal PHP check, not a requirement to rerun every deployment and recovery drill for
an unrelated edit. The workflows define the automated lanes. Unit and architecture checks run once;
database tests exercise host persistence and delivery. Broader browser, recovery and artifact checks run
when their behavior changes and in the scheduled or release lanes.

Keep immutable dependency locks, compiled browser assets and translated catalogues coherent when their
inputs change. Release signatures and artifact digests protect the actual bytes we ship; retain them.
Documentation inventories, historical counts and generated governance reports do not define product correctness.

## Test ownership

- A package owns tests for its reusable algorithms, values, schemas and public contracts.
- App owns tests for package composition, authorization, persistence, delivery, extension lifecycle and user flows.
- Before adding a test, check whether the package or an existing App journey already covers the behavior.
- Prefer one focused regression for a real defect. Exercise outcomes rather than source strings, class counts,
  method names, documentation layout or copied package corpora.
- Remove obsolete or duplicated tests after confirming ownership and surviving behavioral coverage.
- Keep tests for transactions, authorization, data integrity, recovery and the working browser journeys.
  Do not hide a real failure with a skip, retry, softened assertion or fabricated evidence.
- Coverage is diagnostic information. A test needs neither attribution paperwork nor extra cases written
  solely to satisfy a percentage.

## Keep the core small

Inspect `composer.lock` and the relevant installed package's public API, source and examples before adding
reusable behavior. Search by responsibility, not just a proposed class name. Use the package API first.
If it is incomplete, fix the owning package and adopt a tested immutable release. Never patch `vendor/`,
copy package implementations, restore removed namespaces or add a parallel registry/service.

App owns host composition, authority, persistence adapters, orchestration, delivery, deployment and recovery.
Libraries own portable behavior. `src/Kernel/ContainerFactory.php` remains the single composition root.
Dependencies point inward: domain and application behavior do not depend on delivery or infrastructure.
Use constructor injection and typed interfaces; no global service locator. The existing dependency check
protects this boundary. Explain a non-obvious ownership decision briefly in the PR; do not create a new
approval record or census simply because a class or method changed.

The server is PHP 8.5 with the native C++ engine. JavaScript is for the frontend. Node/npm may build and
test assets but are not production runtime requirements. Do not introduce Python, Laravel or Symfony.

## Runtime rules

- CMS content and typed business records remain separate models. ERP/LMS/school/business domains belong
  in extensions, using the shared platform infrastructure.
- Keep business rules in application services shared by administrator, portal, REST, CLI, MCP and workers.
- Apply authorization before data, counts, reports or exports leave the service. Keep site, organization,
  field and action boundaries intact; portal/public exposure is opt-in.
- Preserve transaction boundaries, exact numeric values, optimistic concurrency, audit and retry safety.
- Extensions use the existing owner-aware contribution and trusted lifecycle contracts. Disable/revocation
  removes executable contributions while retaining owned data. Purge is a separate explicit operation.
- Migrations move forward. Do not edit shipped migration bytes. Destructive changes need an explicit,
  recoverable operation; caches and Redis are never authoritative business data.
- Do not execute database-authored code. Use the existing bounded expressions and validated inputs.

## Studio

Studio is contextual Content authoring: create/edit, author, save, reopen, preview and publish. It is not
a separate catalogue workspace and does not require users to pre-create a Blueprint manually.
PHP services remain authoritative. Preserve the authored field identities, entered values and presentation.
Use Studio's product contract and existing screenshots/journeys when changing the interaction. Fix the
owning package where the behavior is portable; do not hide an upstream limitation with App-only copies.

## Where to work

| Responsibility | Location |
|---|---|
| Composition and routes | `src/Kernel/ContainerFactory.php` |
| CMS administrator | `src/Administrator/`, `templates/administrator/` |
| Public site / portal | `src/Http/`, `src/Portal/`, `templates/site/`, `templates/portal/` |
| Content and Studio | `src/Content/`, `src/Studio/`, `assets/` |
| Business runtime | `src/BusinessDefinition/`, `src/BusinessSchema/`, `src/BusinessRecord/` |
| Generated delivery and policy | `src/BusinessSurface/`, `src/BusinessSecurity/` |
| Integration and reporting | `src/BusinessIntegration/`, `src/BusinessReporting/` |
| Identity / audit / extensions | `src/Identity/`, `src/Audit/`, `src/Extension/` |
| CLI / MCP | `src/Delivery/Console/`, `src/Infrastructure/Mcp/` |
| Migrations | `src/Infrastructure/Persistence/Migration/` |
| Browser source / built assets | `assets/`, `public/assets/build/` |

## Documentation and hand-back

Read only the documents relevant to the current change. `docs/coding-standard.md` describes code style;
package documentation describes reusable APIs. Old roadmaps and acceptance ledgers are historical context,
not authority to grow the current task or add new promises.

Document public contracts, operational steps and non-obvious reasoning. Do not write comments that merely
repeat a member name. Update relevant user/operator documentation and a short changelog entry when behavior
changes. The PR should state the problem, the fix, the tests actually run and any practical limitation.
No duplicated status dossiers, per-test documentation exercise or generated counter updates are required.
