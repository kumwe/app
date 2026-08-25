# Working on Kumwe

This file is the one road. Read it, follow the recipe that matches your change, then stop.
Do not start by reading the roadmap, the gap matrix, the quality contract, or the
interface programme. Those are depth. This file tells you **when** to open them.

Coding rules live in [`docs/coding-standard.md`](docs/coding-standard.md). Programme
contracts live in [`docs/roadmap/`](docs/roadmap/README.md). This file does not replace
them. It is the checklist that makes a change survive the gates they enforce.

---

## 0. Environment bootstrap

One command provisions any sandbox — Claude Code cloud, OpenAI Codex, Cursor, a
devcontainer, or a bare checkout. It is idempotent, degrades gracefully, and ends
with a capability report saying which verification tiers work where you are:

```bash
bash tools/agent-setup.sh
```

Vendor files only point at that script; no setup logic lives anywhere else.
Claude Code cloud runs it automatically (`.claude/settings.json` SessionStart
hook). On other cloud platforms, paste the command into the environment's
setup-script field. The outbound domains a sandbox must allow live in
[`tools/agent-egress.txt`](tools/agent-egress.txt) — when a step is blocked, the
script names the group to enable. Before any push, the local gate is
`composer qa` (section 6); the database-backed lane additionally wants
`. ./.agent-env` first.

---

## 1. Sixty seconds

Kumwe is two products behind one set of rules: a CMS (pages, media, menus, publishing)
and a business application platform (typed definitions, records, policies, portal).
Five surfaces — administrator, portal, REST, CLI, MCP — call the **same** application
services. What one allows, the others allow. What one refuses, the others refuse.

| | |
|---|---|
| **Now** | Gate A is **passed** (2026-08-22, [ADR 0010](docs/roadmap/decisions/0010-gate-a-assessment.md)). Do not reopen it. |
| **Next** | Runtime work. Code quality and stability first. Docs and tests only when they protect changed runtime behaviour. |
| **Open work** | [`docs/roadmap/STATUS.md`](docs/roadmap/STATUS.md) and [`docs/roadmap/findings.json`](docs/roadmap/findings.json). |
| **Done work** | [`CHANGELOG.md`](CHANGELOG.md). |
| **Durable contracts** | [`docs/roadmap/README.md`](docs/roadmap/README.md) — presence there is not an open-state claim. |

```
[ ] Open STATUS.md. Confirm Gate A is passed and pick one open package or finding ID.
[ ] Cite that ID in the change. Unplanned work cites nothing and goes straight to CHANGELOG.md.
[ ] Read only the package you are executing (roadmap README §9) and any ADR it names.
[ ] Do not read docs/qualification/gap-matrix.md to plan. It is historical evidence.
[ ] Follow the recipe below that matches what you are about to touch.
[ ] Run the hand-back commands. Re-record the baseline if inventory moved.
[ ] Same pull request: live indexes and changelog move together. Never set a finding to closed.
```

---

## 2. Where things live

Full map: [`docs/architecture/map.md`](docs/architecture/map.md). Compact version:

| You want to change | Go here |
|---|---|
| HTTP pipeline, route table | `src/Kernel/ContainerFactory.php` |
| Public site page | `src/Http/Handler/`, `templates/site/` |
| CMS administrator page | `src/Administrator/Http/Handler/`, `templates/administrator/` |
| Portal page | `src/Portal/Http/`, `templates/portal/` |
| REST `/api/v1` (CMS / older) | `src/Delivery/Http/Api/` |
| REST for a later context | that module's `Delivery/Api/` |
| CLI command | `src/Delivery/Console/` |
| MCP tool | `src/Infrastructure/Mcp/` (HTTP wrapper: `src/Delivery/Http/Mcp/`) |
| Authorization ("may this actor?") | `src/Application/Authorization/` |
| Users, sessions, tokens, TOTP | `src/Identity/` |
| CMS content / workflow / menus | `src/Content/`, `src/Workflow/`, `src/Navigation/` |
| Studio host integration | `src/Studio/`; browser shell in administrator handlers/assets/templates; exact package family in `resources/studio-contract/` |
| Business definitions | `src/BusinessDefinition/` |
| Physical schema / DDL | `src/BusinessSchema/` |
| Typed business records | `src/BusinessRecord/` (`BusinessRecordService` is the write/read facade) |
| Generated admin/portal/REST/CLI/MCP | `src/BusinessSurface/` |
| Record/field policy, approvals | `src/BusinessSecurity/` |
| Outbox, inbox, contributed jobs | `src/BusinessIntegration/` |
| Reports, projections, exports | `src/BusinessReporting/` |
| Extensions | `src/Extension/` |
| Jobs, queue, scheduler | `src/Application/Automation/` + `src/Infrastructure/Automation/` |
| Audit trail | `src/Audit/` |
| Translations | `src/Localization/` + `resources/localization/` |
| Migrations | `src/Infrastructure/Persistence/Migration/` |
| Composition / wiring | `src/Kernel/` — the only place that may see every layer |
| Front-end sources | `assets/` → committed `public/assets/build/` |
| Example extensions | `examples/extensions/` (`extensions/` is the empty runtime volume) |

Two products, not one with two names. CMS (`Content`) and business (`BusinessRecord`) are
forbidden to import each other. Do not unify them.

---

## 3. How a request travels

```
public/index.php
  → Mezzio pipeline (auth, locale, CSRF/session or bearer)
  → a PSR-15 handler in one of the five HTTP trees
  → an application service (policy lives here)
  → domain invariants
  → infrastructure adapter (Doctrine, Redis, Twig, MCP protocol)

CLI  bin/kumwe → Delivery\Console → the same application service
MCP  /mcp or `kumwe mcp:serve`   → the same application service
Jobs Application\Automation\Worker → the same application service
```

A delivery adapter may validate input, authenticate, and format a result. It must not
own a business rule, query a generated table, or write application tables.

Dependencies point inward and are enforced: `docs/architecture/layers.json` +
`composer architecture:policy`. New wrong-way edges fail immediately. The recorded
exemptions in `docs/architecture/dependency-baseline.json` only ever shrink.

---

## 4. The watchers

These fail a green product change because a **number, hash, or inventory** moved and
nobody told the record. This is the hole.

| If you… | This fails | Fix |
|---|---|---|
| Add or remove a `public function test…(` | `composer baseline:check` | `composer baseline:record` and commit `docs/quality/baseline.json` |
| Add a route, CLI command, migration, OpenAPI operation, skip, or lockfile change | same | same |
| Add a test without a documentation block | `composer docs:tests` | Write the block. **Do not** add the new test to `docs/quality/test-docblock-baseline.json` |
| Document an old test that is in that baseline | `docs:tests` (stale entry) | Delete that entry **and** decrement `entry_count` |
| Add `#[CoversNothing]` on a behavioural test | `composer coverage:attribution` | Use `#[CoversClass]`. The pending list is empty and cannot grow |
| Add a test with no coverage attribute | PHPUnit risky | `#[CoversClass]` or a reasoned `#[CoversNothing]` path |
| Add a Domain → Application or Delivery → Infrastructure import | `composer architecture:policy` | Invert it (port inward). Do not grow `dependency-baseline.json` |
| Add a CLI command and edit `cli-v1.json` in place | `composer cli:contract` | Additive successor generation; the pinned count lives in the contract, tests, tools and roadmap prose — a successor generation moves them together |
| Add an MCP tool and edit `mcp-v1.json` in place | `composer mcp:contract` | Same freeze. 75 tools. |
| Change a public extension type | `composer extension:contract` | New generation + new fixture. Never rewrite `tests/Fixtures/ExtensionApi/` |
| Edit XLIFF or a user-facing string | `translation:check` / `translation:strings` | `composer translation:compile` and commit compiled catalogues |
| Rebuild front-end and leave `public/assets/build` dirty | CI frontend job | Commit the hashed build, or don't rebuild |
| Add a graphical route or template without cataloguing it | `composer interface:programme` | Register it in `docs/interface-standard/programme/surface-inventory.json` |
| Add runtime lines without a `#[CoversClass]` test executing them | CI coverage ratchet (canonical MariaDB leg only) | 90% changed-line and 80% changed-refusal floors; write the test, name the class |
| Validate persistence SQL on one engine | The MySQL and PostgreSQL CI legs | Run the cross-engine lane locally: `DB_DRIVER=pgsql DB_PORT=5432` after `tools/agent-setup.sh` |
| Change visible UI without refreshing browser baselines | Hashed screenshot comparisons in the browser jobs | The `refresh-browser-baselines` workflow regenerates them; a CSS change without it loops CI |
| Mark a finding `closed` | `composer roadmap:check` | Delete the finding, remove the STATUS row, write `CHANGELOG.md` |
| Write "delivered" in the STATUS open-work table | `roadmap:check` | Remove the row. Completion language belongs in the phase board and changelog |
| Widen a PHPDoc type (`list<string>` → `array`) | PHPStan max | Add prose. Never widen or delete an existing type |
| Touch a shipped migration that checksums its own bytes | install integrity | Document **before** it ships. Never formatter-pass it afterwards |
| Introduce a secret-shaped literal, even in a later-fixed commit | `composer security:secrets` | Rewrite the introducing commit, or fingerprint-allowlist in `.gitleaksignore` |

`composer qa` already includes `baseline:check`. Adding a legitimate test without
re-recording the baseline is the most common red build.

---

## 5. Recipes

Pick one. Do every box. Then the hand-back commands.

### Add a unit / integration / functional / architecture test

```
[ ] Class and every test method have a documentation block: what behaviour is pinned,
    @return void, @since 2.0.0. 120-column limit. New tests are not debt; they are not
    added to test-docblock-baseline.json.
[ ] #[CoversClass(TheSubject::class)] on behavioural tests.
    #[CoversNothing] only if the file lives under a reasoned path in
    docs/quality/coverage-contract.json (architecture, tools, schemas, fixtures).
[ ] Method name starts with test. That is what the baseline counts.
[ ] No named getenv('FOO') in Integration/Functional tests.
[ ] Integration tests that install definitions or extensions must be idempotent.
    The idempotency baseline is empty; a new second-pass failure is fatal.
[ ] Security-sensitive change: adversarial cases as well as the happy path.
[ ] composer baseline:record
```

### Add a PHP class under `src/`

```
[ ] final readonly class is the default. Constructor promotion. Native types everywhere.
[ ] Documentation block on the class, every method, property, constant, and enum case.
    @since 2.0.0 last. Never rewrite an existing @since.
[ ] Put it in the module that owns the concept (section 2). Do not invent a sixth HTTP tree.
[ ] Dependencies through the constructor. No static container.
[ ] Domain and application code must not import Doctrine, Redis, Twig, HTTP messages,
    or process globals. If you need them, you are in the wrong layer: introduce a port.
[ ] composer docs:format
[ ] Focused unit test. Integration test if it is an infrastructure boundary.
[ ] Cover what you wrote, in a test that names the class in #[CoversClass]: CI's canonical
    MariaDB leg enforces 90% of your changed executable lines and 80% of your changed
    refusal (throw) lines, and only tests that name the class credit its lines. This
    ratchet is CI-only — composer qa cannot measure it — so an untested branch is a red
    build forty minutes out. A refusal nothing has taken is a branch nothing has tested.
[ ] Persistence SQL or driver-facing change? Run the same tests on the cross-engine lane
    (DB_DRIVER=pgsql DB_PORT=5432, provisioned by tools/agent-setup.sh where available):
    PostgreSQL refuses what MariaDB coerces, and only CI runs all three engines.
[ ] Sandbox on PHP 8.4? Whatever is 8.5-only — deprecations fail both PHPStan and the
    suite in CI — stays invisible locally until the packages.sury.org egress line is
    allowed. Treat any 8.5-deprecated API as forbidden.
[ ] Then the "add a test" recipe, including baseline:record.
```

### Add an HTTP route

```
[ ] Register it in src/Kernel/ContainerFactory.php. That is the route table the
    baseline parses.
[ ] Handler goes in the tree in section 8, not "wherever Delivery felt right".
[ ] Declare the required capability. Browser forms need CSRF. REST needs the
    exact Kumwe-Site header, ETag, and an idempotency key on mutations.
[ ] The handler calls an application service. It does not write tables.
[ ] The handler class itself follows the "Add a PHP class" recipe above
    (documentation blocks, focused test, baseline).
[ ] Graphical route (site, administrator, or portal) or a new template:
    register the surface and template in
    docs/interface-standard/programme/surface-inventory.json —
    composer interface:programme compares route and template inventories exactly.
[ ] composer baseline:record  (route list is pinned)
[ ] If REST: composer openapi:compile && composer openapi:check
```

### Add a CLI command or MCP tool

```
[ ] CLI: src/Delivery/Console/. MCP: src/Infrastructure/Mcp/.
[ ] Do not overwrite docs/machine-contract/cli-v1.json or mcp-v1.json.
    Incompatible or additive surface changes need a successor generation.
[ ] The magic counts (44 commands, 75 MCP tools) live in the retained contract,
    in tests, and in tools. A successor generation updates them together.
[ ] composer cli:contract  /  composer mcp:contract
[ ] composer baseline:record
```

### Touch a template, stylesheet, or user-facing string

```
[ ] Identifiers, not prose, in PHP. Copy lives in resources/localization/messages/.
[ ] composer translation:compile
[ ] composer translation:check && composer translation:strings && composer assets:direction
[ ] Logical CSS properties, not left/right. composer assets:direction scans Vite inputs.
[ ] git diff --exit-code resources/localization
```

### Touch generated business behaviour

```
[ ] One catalog, one query decoder, one projector, one BusinessRecordService.
[ ] composer openapi:compile && composer openapi:check
[ ] Focused tests under tests/Unit/BusinessSurface, tests/Integration/BusinessRecord,
    tests/Functional
[ ] npm run test:browser -- --grep 'generated business' when the surface is public
[ ] git diff --exit-code api/openapi public/assets/build
```

### Complete planned work (a package or finding)

```
[ ] Delete the finding from docs/roadmap/findings.json. Do not set state: closed.
[ ] Remove the package/finding from the STATUS.md open-work table.
    Do not write complete / delivered / done in that table.
[ ] Write the substance into CHANGELOG.md under Added / Changed / Fixed / Security /
    Deprecated / Removed. Keep-a-Changelog format. Cite the evidence merge-stably:
    an entry written on a branch cites its pull request as (#123), because the rebase
    merge rewrites every branch commit hash and roadmap:check refuses a dangling one
    on the first master run. Cite a commit hash only when it already sits on master;
    after a rebase leaves an old hash dangling, repoint it to the rebased twin
    (match by commit message) or replace it with the pull-request citation.
[ ] Lower the STATUS ledger snapshot counts. Update the phase-board cell if a phase moved.
[ ] Leave the package definition in docs/roadmap/README.md. That is the durable contract.
[ ] composer roadmap:check
```

### Unplanned work

Write it straight into `CHANGELOG.md`. There is nothing to remove, because it was
never in the live indexes. Absence from the roadmap is not a reason to omit the changelog.

### Do not

- Skip, retry-away, or delete a legitimate test to make a gate green.
- Mix cleanup / formatting / "while I was here" with the substantive change.
- Add Symfony or Laravel, a Kumwe 1.x compatibility layer, or a second composition root.
- Grow a shrinking baseline (dependency, idempotency, CoversNothing pending, test-docblock).
- Re-litigate D1–D18. They are settled.
- Start Phase 5 before `P2-I` (the performance harness) exists.
- Treat KIS programme gates A–F as product Gate A / Gate B. Product gates live only
  in `docs/roadmap/`.

---

## 6. Hand-back commands

`composer qa` is the local gate. Its member set is
[`docs/quality/contract.json`](docs/quality/contract.json), not the short lists in
older contributor files. It currently runs, in order:

```
architecture:policy → baseline:check → quality:contract → docs:api →
docs:format:check → docs:tests → extension:contract → cli:contract →
mcp:contract → interface:programme → roadmap:check → openapi:check →
translation:check → translation:strings → assets:direction →
coverage:attribution → cs → analyse → test
```

```bash
composer qa                 # the local lane. Must be green.
composer docs:format        # apply alignment; qa only dry-runs it
composer security:secrets   # history-wide gitleaks; needs Docker; not inside qa
composer baseline:record    # when tests, routes, commands, migrations, skips,
                            # lockfiles, or OpenAPI operations moved
```

When `composer install` is unavailable, the documentation tools still run:

```bash
php tools/verify-docblocks.php src
php tools/format-docblocks.php src
php tools/verify-roadmap.php
```

A test fixture almost never needs a random-looking literal. Derive it from a readable
stem (`str_repeat("\x01", 32)`, a hash of a known string). A genuinely fixed vector
earns a fingerprint-scoped entry in `.gitleaksignore` with the reason beside it.
Never exempt a path or a rule wholesale.

Frontend is **not** in `qa`:

```bash
npm ci && npm run check && npm run build
# public/assets/build and public/assets/site.css must be clean
```

Extra, when the change needs them:

```bash
composer test:idempotency -- --engine=mariadb   # installs definitions/extensions
composer test:artifact                          # packaging, autoload, archives, keys
npm run test:browser                            # public HTML behaviour
```

---

## 7. Non-negotiables

1. **Every documentable member carries a documentation block.** Format and tag order
   are in `docs/coding-standard.md` section 3.
2. **Every block ends with `@since`.** Never rewrite an existing `@since`.
3. **Never widen or delete an existing PHPDoc type.** PHPStan runs at level `max`.
4. **Documentation-only passes must not alter statements, signatures, or imports.**
5. **Dependencies arrive through constructors.** No static container, no service locator.
6. **Preserve the dependency direction.** Domain depends on nothing; application
   depends on domain; infrastructure and delivery depend inward.
7. **Do not add Symfony or Laravel** as direct production dependencies, and do not
   add Kumwe 1.x compatibility layers.

A fixture, a route, a command, and a changelog sentence are all load-bearing. The
gates treat them that way.

---

## 8. Dual homes — put new code in the existing one

The tree grew two products and five HTTP entry points. That is real. New code follows
the convention below rather than adding a sixth path. Refactors of these dual homes
belong to Lane M / `P3-D` / `V2-ARC-002`, in their own pull request, never mixed
into a behaviour change.

| Kind | Existing home | Not here |
|---|---|---|
| Public site + shared middleware | `src/Http/` | `src/Delivery/Http/` |
| REST + MCP HTTP + dashboard decoders | `src/Delivery/Http/` | `src/Http/` |
| CMS admin handlers | `src/Administrator/Http/Handler/` | `BusinessSurface` |
| Generated business admin/portal | `src/BusinessSurface/Delivery/` | `src/Administrator/Http/` |
| Context-owned admin (definitions, schema, reports) | `<Module>/Delivery/Administrator/` | `src/Administrator/Http/` |
| Cross-cutting use cases (authz, jobs, tx, idempotency) | `src/Application/` | a new top-level folder |
| Context use cases | `<Context>/Application/` | `src/Application/` |
| "May this actor do X?" | `Application\Authorization\AuthorizationGateway` | `Identity\Application\Authorization` |
| How grants are stored | `src/Identity/` | the gateway |
| Which rows/fields this membership may see | `src/BusinessSecurity/Policy/` | the gateway |
| Queue/worker | `src/Application/Automation/` | `src/Automation/` (only `CronExpression` lives there) |
| MCP protocol | `src/Infrastructure/Mcp/` | `src/Delivery/` (HTTP wrapper only) |

If a change would add a dependency the layer graph forbids, invert it: port in
application, adapter in infrastructure. Do not grow the baseline.

---

## 9. Depth — open only when the recipe says so

| Document | Open it when |
|---|---|
| [`docs/roadmap/STATUS.md`](docs/roadmap/STATUS.md) | Starting any work. Always. |
| [`docs/coding-standard.md`](docs/coding-standard.md) | Writing PHP. |
| [`docs/architecture/map.md`](docs/architecture/map.md) | Unsure which folder owns the change. |
| [`docs/architecture/principles.md`](docs/architecture/principles.md) | Crossing a layer. |
| Package § in [`docs/roadmap/README.md`](docs/roadmap/README.md) | Executing that package. Not otherwise. |
| [`docs/development.md`](docs/development.md) | Need the three-engine matrix, demo profiles, or release checks. |
| [`docs/quality/contract.json`](docs/quality/contract.json) | Adding or moving a gate. |
| [`docs/architecture/delivery.md`](docs/architecture/delivery.md) | Adding a use case that must appear on more than one surface. |
| [`docs/extension-contract/`](docs/extension-contract/README.md) | Changing a public extension type. |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Human PR workflow. |
| [`docs/README.md`](docs/README.md) | Operating or extending a *site*, not this programme. |
| [`docs/qualification/gap-matrix.md`](docs/qualification/gap-matrix.md) | Never, to plan. Residual `GM-*` IDs live in `findings.json`. |
| KIS programme under `docs/interface-standard/programme/` | Changing a graphical surface's migration state. Not product sequencing. |

**Three views, one state.** `docs/roadmap/README.md` is the durable programme
specification. `STATUS.md` and `findings.json` are the live forward-work indexes.
`CHANGELOG.md` is the completed-work authority. A finding is never marked done in
place; it is removed from the live ledger and recorded in the changelog.
`composer roadmap:check` fails the build if `closed` appears or the open-work table
carries a completion marker.

Write the block a reader needs, not the block a template demands. Restating the
identifier — "Gets the name. `@return string The name.`" — is noise.
