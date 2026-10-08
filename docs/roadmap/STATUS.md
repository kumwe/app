# Version 2 beta and release-candidate status

## Current position — 2026-10-03

Pull request [#152](https://github.com/kumwe/app/pull/152) merged on 2026-09-30 as
[`4fb53353`](https://github.com/kumwe/app/commit/4fb533531c0c28bf4cbb902d9fa7214cdcf6986f).
Its runtime work is on `master`. The beta tag exists, but
[release run 36766403378](https://github.com/kumwe/app/actions/runs/36766403378) failed before publishing
qualified distributions. The latest published application release is `v2.0.0-alpha.23`; a tag alone does
not make Beta 1 available. A successful signed beta release is the immediate delivery objective. Gate B
and a stable Version 2 release have not been declared complete.

The current maintainer direction in [`AGENTS.md`](../../AGENTS.md) governs delivery. Historical ledger
counts below are retained for context; they are not a fresh audit of `master` or additional merge gates.
No 24-hour or 72-hour endurance run, documentation inventory, or separate acceptance-record commit is
required. Capacity evidence comes from bounded concurrent samples with their limitations stated.

### What is implemented

| Objective from the ERP runtime blueprint | Current implementation and practical boundary |
|---|---|
| Unified extension contributions | Signed, owner-aware contributions, immutable trusted runtime generations, diagnostics and executable withdrawal; [extension contracts](../extensions.md). Installed PHP is trusted in-process code, not sandboxed code. |
| Business definitions | Immutable typed entities, fields, relations, views, actions, bounded expressions and compatibility plans; [definition runtime](../business-definitions.md). CMS content and business records remain separate. |
| Transactional business runtime | Approved relational schema plans, exact values, optimistic concurrency, idempotency, revision/audit, relations and atomic header/line documents; [runtime](../business-runtime.md). |
| Security and portal | Query and field policy, scoped identities, approvals, step-up and opt-in portal surfaces; [security](../business-security.md) and [portal](../portal.md). |
| Generated delivery | Administrator, portal, REST/OpenAPI, CLI and MCP adapters use shared application services; [generated surfaces](../architecture/generated-business-surfaces.md). |
| Integration and SDK | Durable events, inbox/outbox, jobs, schedules, processes, reports, exports, scaffolding and neutral proof extensions; [integration guide](../business-integrations.md). Current Extension SDK is `0.3.6`. |
| Production qualification | Recovery, security, diagnostics and sampled capacity are implemented. The repair evidence below establishes specific source checks; qualified and published beta bytes remain pending. |
| Contextual Studio | Content create/edit, save/reopen, reusable types, values, layout, localization, keyboard authoring, accepted-revision preview and public PHP rendering use Studio beta.9 with Producer `0.6.0`. The Content editor opens Studio maximized; an item of a type without a layout opens with a default composition from its model, and its title and text, integer and yes-or-no values are editable in the inspector; the browser journey proves an inspector title edit through Save item, while integer and yes-or-no inspector edits are derived from the pinned shell and untested ([ADR 0024](decisions/0024-default-composition-from-the-content-model.md), proposed); on beta.9 the page canvas still shows host field blocks as unsupported placeholders until the next re-pin. [The host guide](../studio-composition-authoring.md) records the remaining product boundaries. |

### Latest merged-master evidence

These runs all concern source commit `4fb53353`; they establish different facts.

| Lane | Result and scope |
|---|---|
| [CI 37103075988](https://github.com/kumwe/app/actions/runs/37103075988) | PostgreSQL backup/restore failed. MariaDB was cancelled at its 90-minute job deadline during repeat/reverse checks; this was not a fail-fast cancellation or a complete three-engine pass. |
| [Nightly 37109109916](https://github.com/kumwe/app/actions/runs/37109109916) | Failed the same WebKit keyboard journey. 270 of 272 journeys passed first attempt; the canvas journey needed a retry, and all 20 critical journeys passed. A recovered retry is not first-attempt evidence. |
| [Recovery 37109960693](https://github.com/kumwe/app/actions/runs/37109960693) | Passed its recovery workflow; this does not cancel the separate CI backup/restore failure. |
| [Observability 37109995350](https://github.com/kumwe/app/actions/runs/37109995350) | Passed. |
| [Sampled capacity 37110028732](https://github.com/kumwe/app/actions/runs/37110028732) | Passed on MariaDB, MySQL and PostgreSQL, with zero failures in 7,560 measured calls on fresh and 2,000-record aged workloads. [Current measurements, host variation and storage limits](../operations/capacity-estimate.md#workflow-sample-2026-10-03) are recorded separately from forecasts. |

### Repair in pull request #154

[The repair](https://github.com/kumwe/app/pull/154) addresses seven observed causes. These branch results
are separate from the merged-master evidence above and do not qualify or publish release bytes.

| Cause | Repair and retained boundary |
|---|---|
| Release documentation used retained CLI generation 2 and rejected the implemented generation-3 `app:diagnostics` command. | Inventory, packaged index and machine-contract validation use generation 3, including the existing audit checkpoint input. Retained generation-1/2 contracts remain intact. |
| WebKit keyboard authoring exhausted its 90-second deadline in repeated viewport observer checks. | Common geometry uses a bounded synchronous check; browser-specific layout falls back to the native assertion. Visibility, clipping, accessibility, native focus scrolling and the original journey deadline remain enforced. |
| Atomic PostgreSQL restore exhausted the shared lock table after the full suite retained 7,790 public relations with a lock budget of 64 and 100 connections. | The CI PostgreSQL service starts with `max_locks_per_transaction=256`. Atomic restore, retained data, signing, restored behavior and tamper checks remain intact. [Operator sizing](../operations/backup-restore.md) is documented without a universal production value. |
| Healthy full MariaDB work exceeded the 90-minute job budget: the initial phase took about 39 minutes and one repeat took 46 minutes with 651 tests, 18,701 assertions and no failures/errors. | The canonical full job allows 180 minutes for coverage, repeat/reverse and recovery. Routine and other database jobs, individual test deadlines and assertions retain their existing budgets. |
| Downstream MySQL deployment could not create the audit guards with binary logging enabled and `log_bin_trust_function_creators` absent. | Production Compose and the clean-restore MySQL server enable `log_bin_trust_function_creators=ON` at startup. Application credentials remain scoped to the installation schema; audit guards and binary logging remain enabled. The deployment qualification below passed. |
| The PostgreSQL and MariaDB asset-inspection deployment fixture omitted the current-password proof required to approve its schema change. | The acceptance helper supplies the existing administrator credential through a protected temporary password file for approval risks that require it, then cleans up the file. Runtime authorization and schema-risk checks remain enforced. The deployment qualification below passed. |
| A successful administrator login returned a 303 with the session cookie first and an expired CSRF cookie second; the acceptance helper selected the last header and used the CSRF cookie. | The helper selects the exact named administrator-session cookie and retains its existing strict 43–512-character base64url contract. Authentication behavior is unchanged; deployment qualification passed. |

The new geometry regression fixture also needed responsive viewport metadata for mobile Chromium and
a native fallback for root/body overflow semantics that differ across engines. Those corrections preserve
the assertions. The final focused fixture passes desktop/mobile Chromium (2/2) and Firefox (1/1), and
`npm run check` passes. Existing release-tool tests and release-documentation checks also pass.

| Repair subject and lane | Verified result and scope |
|---|---|
| [Full CI 37124609098](https://github.com/kumwe/app/actions/runs/37124609098), subject [`b646494b`](https://github.com/kumwe/app/commit/b646494bd0e4278856e3d65f5ee2a813e52b7761) | All three database lanes passed ordinary suites, repeat/reverse checks, signed backup, atomic restore, restored application behavior and tamper checks. All three browser lanes, quality, frontend and artifact checks passed. The overall run failed downstream at the MySQL audit-guard and schema-approval fixture defects; the subsequent deployment qualification below passed their repairs. |
| [Corrected nightly 37127282091](https://github.com/kumwe/app/actions/runs/37127282091) and [routine CI 37127282181](https://github.com/kumwe/app/actions/runs/37127282181), subject [`a7e908c3`](https://github.com/kumwe/app/commit/a7e908c3263fac75aa6fb0f9d801522f097d3be9) | Nightly passed 274 of 274 journeys first attempt and all 20 critical journeys, with no failures, flakes, skips or retries. WebKit keyboard authoring took 48.342 seconds within its unchanged 90-second deadline. Routine CI passed all three ordinary database lanes, quality, frontend, artifact and MariaDB browser checks. [Security 37127282020](https://github.com/kumwe/app/actions/runs/37127282020) and [Compose 37127282036](https://github.com/kumwe/app/actions/runs/37127282036) passed. |
| [Deployment qualification 37134654582](https://github.com/kumwe/app/actions/runs/37134654582), subject [`cc5aff40`](https://github.com/kumwe/app/commit/cc5aff4046e252772217a8cc805c83b57a1b2168) | Passed the Composer ZIP and all three production deployments: PostgreSQL in 6 minutes 21 seconds, MariaDB in 6 minutes 49 seconds and MySQL in 7 minutes 26 seconds. Full lifecycle checks, all three deployment repairs and signed clean-target restore passed. [Recovery 37134654432](https://github.com/kumwe/app/actions/runs/37134654432), [Security 37134654450](https://github.com/kumwe/app/actions/runs/37134654450) and [Compose 37134654464](https://github.com/kumwe/app/actions/runs/37134654464) also passed. |

The full MariaDB job completed in 130 minutes 48 seconds: repeat took 40 minutes 15.526 seconds,
reverse order 57 minutes 48.789 seconds and recovery 55 seconds. PostgreSQL repeat/reverse took
34 minutes 14 seconds with a 22-second restore drill; MySQL took 27 minutes 17 seconds with a
72-second restore drill. These results verify the database repairs and the measured MariaDB job budget.

Deployment qualification passed on `cc5aff40`; the latest PR checks determine readiness of later
commits. Qualification of the tagged production release bytes and signed beta publication remain
pending. These source results do not establish a release-readiness percentage.

### Release-lane cause found after #154

[Release run 36766403378](https://github.com/kumwe/app/actions/runs/36766403378) for `v2.0.0-beta.1`
stopped in "Build immutable release candidate" at `composer test`, before any image or distribution was
built: four `AuditRetentionAuthorityIntegrationTest` cases ended with
`Access denied for user 'root'@'172.18.0.1' (using password: NO)`. Those tests create and drop their own
database principals through `tests/Support/DatabaseAdministrator`, which signs in as root without a
password and rethrows a refusal under CI. The release workflow's MariaDB service generated a random root
password while every other workflow that hosts the suite allows the empty root login.
[#156](https://github.com/kumwe/app/pull/156) grants the release service the same login. It also adopts
the Playwright, Vite and Node-types updates from Dependabot [#155](https://github.com/kumwe/app/pull/155)
without the quarantined Studio `0.1.0-rc.1` snapshot that group carried, and excludes the governed
`@kumwe/*` npm and `kumwe/*` Composer pins from Dependabot version updates. A tag cut from `master`
before #156 merges fails the release lane the same way; after the merge, the release workflow can be
dispatched from `master` against that existing tag.

### Beta.2 release run: distribution qualified, resilience and production lanes failed

[Release run 37542913404](https://github.com/kumwe/app/actions/runs/37542913404) for `v2.0.0-beta.2`
(`master` at `945be7b7`, after #156) built, signed and attested the candidate, and the Composer and ZIP
installation lane passed. All three "Artifact resilience" jobs failed in "Qualify migration, faults and
backlog in the exact ZIP and Composer installs", the lane #154 introduced. It had never run on a tag:
`v2.0.0-beta.1` stopped before any artifact existed, and pull-request acceptance runs skip it without a
release artifact. The three "Production" lanes, which since #154 pull and verify the signed candidate
images instead of building them, spent 49 minutes (MariaDB, which then passed every remaining step) and
more than 90 minutes (PostgreSQL and MySQL, cancelled by the job timeout) in that verification step.

- PostgreSQL: the ZIP form passed completely, including the four-worker drain of 1,000 aged jobs in
  2.3 seconds. The Composer form's first `database:migrate` on a fresh database stopped with "Refusing
  to replace a newer local runtime generation". Cause confirmed by reproducing the archive step locally:
  `composer archive` packs every file its exclusion list does not name, and the build cuts the archive
  after `database:migrate` and the complete test suite have run in the checkout, so the Composer
  distribution carried that checkout's materialized `storage/cache/extensions.json` and signed marker,
  logs, `vendor` and `node_modules`. The build deleted storage files only from the ZIP package, and the
  installation lane masked the leak because it signs runtime markers under a random key while the
  resilience lane uses the testing default that verifies the build's marker.
- MySQL and MariaDB: the ZIP form's drills passed and the four-worker drain lost a worker within a
  second of starting ("A backlog worker failed"), before the Composer form ran. The workers' error logs
  are in the retained diagnostics artifacts (`release-resilience-37542913404-1-mysql` and `-mariadb`),
  which the session that diagnosed the run could not download. The mechanism consistent with the
  timing, the engines and the code is the sorted `FOR UPDATE SKIP LOCKED` claim: InnoDB locks every row
  the sort examines, and the scan's next-key locks deadlock against a sibling's reservation; the queue
  fairness lane already locked by primary key for the same reason.
- Production lanes: `cosign verify-attestation --type cyclonedx` prints each image's verified envelope
  to standard output, 2.3 MB of base64 on one line for the application image and 0.56 MB for the web
  image. The job log's timestamps show the runner spending 23 minutes on the application envelope twice
  over and 55 seconds on the web envelope twice over; the verification itself is seconds. The envelope
  belongs in a file, not in the log.

The follow-up pull request [#158](https://github.com/kumwe/app/pull/158) excludes runtime state from the
Composer archive and refuses a leaking archive at build time, locks claim candidates by primary key on
every engine, prints a failed worker's error log in the drain output, and writes the verified
attestation envelope to a file instead of the job log. The next tag cut after it merges re-runs every
lane; if MySQL or MariaDB still loses a worker, the job log now carries the worker's stderr.

### Focus before an RC

1. Complete the remaining repair checks, merge the fix, then build, qualify and publish the signed beta
   from accepted source. Verify the actual image and distribution digests through the existing release
   pipeline; no extra approval dossier is needed.
2. Drive Content create, author, save, reopen, preview and publish from the packaged PHP application,
   including a successful hosted round trip beside an independent local Studio mount.
3. Freeze RC scope explicitly around the remaining Studio capabilities below, and review the exact
   candidate for authorization, integrity and recovery defects before selecting the RC.
4. Profile the costly repeated schema/bootstrap work to shorten measured CI feedback. Keep this a focused
   engineering follow-up; it adds no release gate or endurance requirement.

Studio still lacks live-draft hosted preview, extension-owned authoring targets, and complete extension
field-adapter/pattern/migration integration. The host guide identifies the owning SDK/Studio contracts
and App follow-through. Accepted-revision preview works; it is not live-draft preview. Decide the scope
of these remaining capabilities explicitly when setting the RC feature freeze instead of treating an
old ledger count as product readiness. Demo redesign and the Version 3 native client remain separate.

## Historical programme evidence

The remaining tables describe the pre-merge programme record. Their `open` states, old agent names,
package blockers and generated counts have not been re-assessed against merged `master`. The current
status and priorities above, the actual code and current workflow outcomes take precedence.

**Exact machine-evidence candidate** [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad)

**Reproducible-baseline measured source** [`a4ded133`](https://github.com/kumwe/app/commit/a4ded13341d41dfbb2b7f69ff072b077510d2338) — candidate `67cf6c02` changes only [`docs/quality/baseline.json`](../quality/baseline.json) from that source

This evidence record names the immutable workflow subject above; the documentation-only commit carrying the
record is not thereby a new machine-evidence candidate.

> **Live open work is indexed here and in [`findings.json`](findings.json). Finished work is in
> [`CHANGELOG.md`](../../CHANGELOG.md); durable package definitions remain in [`README.md`](README.md).**
> Planned work leaves this page's open-work table or the findings ledger and enters the changelog in the
> same pull request that completes it. Unplanned work goes directly to the changelog. See
> [How this document moves](README.md#how-this-document-moves). An identifier in either live index is
> outstanding: `findings.json` admits no `closed` state, the open-work table admits no completion marker,
> and `composer roadmap:check` fails if either appears.

---

## Where we are

| | |
|---|---|
| **Current phase** | Gate A passed. Pull request #152 (`platform/v2-runtime-completion`) carries the Version 2 Beta 1 runtime and release qualification, Points 1 through 5. |
| **In flight** | #152: the Studio journey and machine parity; Phase 5 scale; Phase 6 recovery and diagnostics; Phase 7 and `PL-G` security, interface, language and automation gaps. Every requirement is one entry of [`acceptance-record.json`](acceptance-record.json) with its runtime owner, tests, CI jobs, artifacts, decision, state and track; the phase board, open work, Gate B table and ledger snapshot below are generated from it and from [`findings.json`](findings.json). The 2026-09-28 reconciliation no longer assumes unpublished agent branches survive: integrated work awaiting qualification and missing runtime are explicitly distinguished in each outstanding note. |
| **Next** | Complete the remaining runtime and failing workflow cases, qualify the exact Beta 1 artifacts, and mark #152 ready only after the required checks pass. The maintainer alone merges; publication follows the qualified release pipeline. Demo redesign and the Version 3 Flutter SDK remain separate. |
| **Open decisions** | None for #152. [ADR 0021](decisions/0021-automated-acceptance-and-sampled-capacity.md) settles acceptance and capacity: automated workflow evidence plus the maintainer's merge once every required check is green is the sole acceptance record, with no human checkbox, manual browser, Safari or right-to-left review, or follow-up acceptance commit; capacity is estimated statistically from concurrent samples on workflow hardware. Commit `ada12fdb` adopts published Studio `0.1.0-beta.9`, `kumwe/producer` `0.6.0` and `kumwe/extension-sdk` `0.3.6`; its routine CI passed. Readiness after later commits depends on their current checks. |
| **Gate A** | Passed on 2026-08-22. All 13 executable criteria are met; acceptance is recorded in [ADR 0010](decisions/0010-gate-a-assessment.md). |
| **Gate B** | Not assessed. The criteria table below is generated from the acceptance record; ADR 0021 changes the acceptance method and does not declare Gate B passed. |

## Phase board

Generated by `composer acceptance:summary` from [`acceptance-record.json`](acceptance-record.json); edit the
record, never this table. A phase with any open or pending entry cannot read as delivered.

<!-- acceptance-record:phase-board:begin -->
| Phase | Gate | State | Blocked on |
|---|---|---|---|
| 0 — Truth, contracts and decisions | A | In progress — `P0-C`, `P0-D` delivered; `P0-A`, `P0-B`, `P0-E` open; 2 findings open | — |
| 1 — Correctness, security, data entry | A | Delivered — every package complete, including resident extension withdrawal and stale-generation fencing | — |
| 2 — Truthful gates | A | In progress — `P2-F`, `P2-G`, `P2-I` delivered; `P2-B`, `P2-C`, `P2-D`, `P2-E`, `P2-H` open; 4 findings open | Phase 0 decisions 1, 7 and 8 (`P0-E`) |
| 3 — Seams and the ownership model | A | Delivered — transaction proof, delivery boundaries, the two aggregate seams, business-group ownership, and the `P3-D` domain-and-application reconciliation recorded in ADR 0012 | — |
| 4 — Atomic aggregate documents | A | Delivered — `P4-A` … `P4-D` complete: the command, the bulk persistence mechanics, the numbering proof set with ADR 0011 and the bounded invariant | — |
| E — Enterprise document primitives | A | Delivered — every package and follow-up finding complete | — |
| L — Language, locale and multilingual content | A, with a B tail | In progress — `PL-A`, `PL-B`, `PL-C`, `PL-D`, `PL-E`, `PL-F` delivered; `PL-G` open; 1 finding open | — |
| **Gate A** | | **Passed — 13/13 executable criteria met** | — |
| 5 — Enterprise scale | B | In progress — `P5-A`, `P5-B`, `P5-C`, `P5-D`, `P5-E`, `P5-F`, `P5-G`, `P5-I` delivered; `P5-H` open | — |
| 6 — Continuity and introspection | B | In progress — `P6-D` delivered; `P6-A`, `P6-B`, `P6-C` open; 6 findings open | — |
| 7 — Qualification | B | In progress — `P7-A`, `P7-D` delivered; `P7-B`, `P7-C`, `P7-E`, `P7-F`, `P7-G`, `P7-H`, `P7-I` open; 3 findings open; in flight on `agent/browser` | Final Phases 5, 6, PL-G and release-artifact evidence in #152 |
| S — Studio contextual Content authoring | A, with a B integration | In progress — `S-A`, `S-B`, `S-C`, `S-D`, `S-E`, `S-F` delivered; `S-G` open; 1 finding open, 1 requirement open | Published Studio beta.9, Producer 0.6.0 and Extension SDK 0.3.6 adopted in `ada12fdb`; earlier package blocker resolved |
| **Gate B** | | **Not assessed — criteria: 3 delivered, 0 pending integration, 9 open** | Final runtime, Studio, recovery, diagnostics and release qualification evidence; Beta 1 is a prerelease, not a stable Gate B declaration |
| M — Maintainability | — | In progress — 2 findings open | Phase 3 seams settled. Blocks nothing. |
| N — Native client platform contracts | — | Not started — Version 3 seed | Nothing in Version 2; blocks nothing. Decision D17, ADR 0009. |
<!-- acceptance-record:phase-board:end -->

## Open work packages by phase

This table holds only what is outstanding, so every package and finding listed here is open or awaiting
integration. It is generated from the acceptance record: an identifier leaves it when its entry is
delivered on the pull request head, in the same change that writes it into the changelog. Its normative
definition remains in README, and the per-requirement detail is in
[`acceptance-summary.md`](acceptance-summary.md).

<!-- acceptance-record:open-work:begin -->
| Phase | Packages | Findings and requirements | Track | Pending integration from |
|---|---|---|---|---|
| 0 | `P0-A`, `P0-B`, `P0-E` | `V2-DOC-002`, `V2-ERP-007` | `maintainability`, `version-3` | — |
| 2 | `P2-B`, `P2-C`, `P2-D`, `P2-E`, `P2-H` | `V2-DEMO-001`, `V2-REL-001`, `V2-REL-002`, `GM-SUP-09` | `maintainability`, `pr-152` | — |
| L | `PL-G` | `V2-LNG-010` | `pr-152` | — |
| 5 | `P5-H` | — | `pr-152` | — |
| 6 | `P6-A`, `P6-B`, `P6-C` | `V2-DR-001`, `V2-DR-004`, `V2-DR-003`, `V2-DR-002`, `GM-BAK-04`, `GM-BAK-08` | `pr-152` | — |
| 7 | `P7-B`, `P7-C`, `P7-E`, `P7-F`, `P7-G`, `P7-H`, `P7-I` | `V2-UX-001`, `V2-QA-014`, `GM-SUP-05` | `pr-152` | — |
| S | `S-G` | `V2-STU-007`, `MACHINE-STUDIO-PARITY` | `pr-152` | — |
| M | — | `V2-ARC-002`, `V2-QA-010` | `maintainability` | — |
| N | — | `V3-NC-001`, `V3-NC-002`, `V3-NC-003`, `V3-NC-004` | `version-3` | — |
| evidence | — | `GM-AUD-02` | `pr-152` | — |
<!-- acceptance-record:open-work:end -->

## Gate B criteria

The twelve criteria are defined in [`README.md`](README.md) section 8. Each is one entry of the acceptance
record; this table is generated from it and lists, per criterion, the entries citing it that are not yet
delivered.

<!-- acceptance-record:gate-b:begin -->
| # | Criterion | State | Track | Entries not yet delivered |
|---|---|---|---|---|
| 1 | No repository-owned critical or high finding is open; every conditional and external risk has an owner, detection method, compensating control, remediation path and review date. | open | `pr-152` | `V2-ERP-007`, `V2-REL-001`, `V2-REL-002`, `V2-DR-001`, `V2-DR-003`, `V2-DR-002`, `GM-BAK-04`, `GM-BAK-08`, `P7-C`, `V2-STU-007`, `GM-AUD-02` |
| 2 | Concurrent capacity samples and explicitly labelled estimates are published from workflow hardware; an estimate is never a production guarantee. | delivered | `pr-152` | — |
| 3 | Unrelated writes do not serialize on a definition row, commits lock no installation-wide head, fan-out and queue claims scale through batched workers, hot ledgers drain at twice expiry, and monitoring runs no unbudgeted exact count. | delivered | `pr-152` | — |
| 4 | Point-in-time recovery is proven: coordinates on every engine, replay before and after a chosen transaction, the ordering rule enforced, and the drill run inside the deployed image. | open | `pr-152` | `P6-A`, `P6-B`, `P6-C`, `V2-DR-001`, `V2-DR-004`, `V2-DR-003`, `V2-DR-002`, `GM-BAK-04`, `GM-BAK-08` |
| 5 | Operational diagnostics answer where the system is struggling, within the established cardinality discipline. | delivered | `pr-152` | — |
| 6 | The exact built images, Composer package and archive pass the complete qualification contract and a signed manifest contains every published digest. | open | `pr-152` | `P2-H`, `V2-REL-001`, `V2-REL-002`, `P7-B`, `P7-G` |
| 7 | Automated interface and language evidence is complete; the maintainer's merge is the sole human acceptance, with no manual checklist. | open | `pr-152` | `PL-G`, `V2-LNG-010`, `P7-E`, `V2-UX-001`, `V2-QA-014` |
| 8 | The vertical-neutral proof portfolio installs, runs and uninstalls on all three engines with no core edit. | open | `pr-152` | `P7-F` |
| 9 | An independent review at the release candidate finds no repository-owned critical or high contradiction. | open | `pr-152` | `P7-I` |
| 10 | The published envelope states exact units, topology, hardware, versions, dataset, variance and limitations, never 'millions per day'. | open | `pr-152` | — |
| 11 | All nine languages ship and each is qualified in its own right, with zero horizontal overflow and zero inaccessible critical control. | open | `pr-152` | `PL-G`, `V2-LNG-010` |
| 12 | Studio contextual Content authoring ships and passes STUDIO-PROD-015 through PHP, with zero production Node.js or npm. | open (`agent/machine`) | `pr-152` | `S-G`, `V2-STU-007`, `MACHINE-STUDIO-PARITY` |
<!-- acceptance-record:gate-b:end -->

## Decisions

Eighteen, all recorded in [`README.md`](README.md) section 2. Ten carry a full decision record; ADR 0020 is the
product-owner correction to D16 and ADR 0021 the product-owner decision on acceptance and capacity, rather than
further numbered decisions.

| | Decision | Record |
|---|---|---|
| D1 | Scale target is 5,000,000 documents per day | [`capacity-contract.json`](capacity-contract.json) |
| D2 | Two gates, not one | README section 8 |
| D3 | Point-in-time recovery is platform-supported, operator-configured | README section 2 |
| D4 | The backup artifact is reshaped for deduplication | README section 2 |
| D5 | `BusinessRecordService` decomposition leaves the critical path | README section 2 |
| D6 | Runtime operational introspection is a distinct deliverable | README section 2 |
| D7 | A business-group installation is supported, through ownership scopes | [ADR 0001](decisions/0001-resource-ownership-scope.md) |
| D8 | The atomic aggregate contract is designed before it is built | [ADR 0005](decisions/0005-atomic-aggregate-document-contract.md) |
| D9 | Capabilities are described on their own merits | README section 2 |
| D10 | Multi-currency is core: the type and the conversion contract | [ADR 0004](decisions/0004-money-conversion-contract.md) |
| D11 | The interface is multilingual, with a decided architecture | [ADR 0002](decisions/0002-interface-translation-architecture.md) |
| D12 | Content is multilingual too, including extension-contributed content | [ADR 0002](decisions/0002-interface-translation-architecture.md) |
| D13 | The seven enterprise-primitive boundary questions are decided | README section 2; [ADR 0003](decisions/0003-immutable-correction-by-reversal.md) for D13.2 |
| D14 | Point of sale is deferred but not foreclosed | README section 2 |
| D15 | Role-specific dashboards compose the unified contribution runtime | [ADR 0006](decisions/0006-unified-dashboard-composition.md) |
| D16 | Studio is contextual Content authoring, integrated at Gate B | [ADR 0007](decisions/0007-studio-visual-composition-integration.md); product-surface correction in [ADR 0020](decisions/0020-studio-contextual-content-authoring.md); default composition for a type without a layout proposed in [ADR 0024](decisions/0024-default-composition-from-the-content-model.md), awaiting the maintainer |
| D17 | The native client platform is a Version 3 programme; its sign-in is the authentication link | [ADR 0009](decisions/0009-native-client-platform-and-the-authentication-link.md) |
| D18 | Gate A is accepted on its thirteen executable criteria | [ADR 0010](decisions/0010-gate-a-assessment.md) |
| — | Acceptance is automated workflow evidence plus the maintainer's merge; capacity is sampled | [ADR 0021](decisions/0021-automated-acceptance-and-sampled-capacity.md) |
| — | The remaining `P0-E` decisions | Not yet written |

## Ledger snapshot

Generated from [`findings.json`](findings.json) by `composer acceptance:summary`.

<!-- acceptance-record:ledger-snapshot:begin -->
**24 open findings** in [`findings.json`](findings.json). The ledger holds open work only.

| State | Count |
|---|---|
| `open` | 9 |
| `reproduced` | 1 |
| `decision_required` | 0 |
| `accepted_for_implementation` | 1 |
| `in_progress` | 12 |
| `verified` | 0 |
| `conditional` | 1 |
| `external` | 0 |
| `closed` | **not an allowed state** — see [`CHANGELOG.md`](../../CHANGELOG.md) |

| Phase | Findings |
|---|---|
| 0 | 2 |
| 1 | 0 |
| 2 | 4 |
| 3 | 0 |
| 4 | 0 |
| E | 0 |
| L | 1 |
| 5 | 0 |
| 6 | 6 |
| 7 | 3 |
| S | 1 |
| M | 2 |
| N | 4 |
| evidence | 1 |

| Gate | Findings |
|---|---|
| A | 0 |
| B | 9 |
| none | 15 |

By severity: 1 critical, 7 high, 11 medium, 5 low.
By origin: 5 review, 5 gap-matrix, 14 new.
<!-- acceptance-record:ledger-snapshot:end -->

The 56 findings that were closed when this roadmap was consolidated have left the ledger. Their substance —
the tamper-evident audit work, the record-secret key ring and rotation, the credential lifecycle, the
supply-chain controls, the contention proofs, the failure drills, the observability contract, the restore
drill and the four production-only defects — is in [`CHANGELOG.md`](../../CHANGELOG.md) with the commits
that closed it. Further findings have left since, including the machine surface's credential transport and risk
taxonomy, the MySQL/MariaDB schema-global foreign-key names, the extension trust posture, the root
locale-addressing defect, the unreachable catalogue-refusal seam, the Studio contract pin with its corpus replay
(`V2-STU-002`, package `S-B`) and PostgreSQL's schema-global non-primary-index namespace. In #152 the sign-in,
idle-expiry, self-service and session-qualification findings (`GM-IDN-04` to `GM-IDN-07`), the Phase 5 scale
findings (`V2-SCL-001`, `V2-SCL-002`, `V2-SCL-004` to `V2-SCL-008`), the audit-metadata guard (`GM-AUD-08`), the
inline-style policy (`GM-SUP-08`), the tracing decision (`GM-OBS-05`), the dashboard and read-only interface
findings (`V2-UX-002`, `V2-UX-003`) and the Studio media and preview findings (`V2-STU-005`, `V2-STU-006`) left
too. Their completed substance is recorded in the changelog.

## Gate A criteria

| # | Criterion | Met | Findings |
|---|---|---|---|
| 1 | Extension contract frozen with passing compatibility fixtures | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 2 | Atomic aggregate command exists and matches the recorded shape | Yes | Recorded in [`CHANGELOG.md`](../../CHANGELOG.md); [ADR 0005](decisions/0005-atomic-aggregate-document-contract.md) |
| 3 | Data-entry integrity holds on all three browser surfaces | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 4 | Correctness and security contradictions fixed | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 5 | Quality gates are truthful | Yes — one manifest defines local, CI, nightly and release execution; semantic dependency checking, coverage and dependency ratchets, retained-contract parity and the deployed-artifact lane all fail closed. Exact candidate [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad), whose reproducible baseline records measured source [`a4ded133`](https://github.com/kumwe/app/commit/a4ded13341d41dfbb2b7f69ff072b077510d2338), passed [CI run 32582207163](https://github.com/kumwe/app/actions/runs/32582207163) with 3,144-test ordinary suites plus 381-test repeat and reverse-order passes on MariaDB, MySQL and PostgreSQL, zero recorded idempotency failures, 66.90% canonical line coverage with every ratchet holding, bounded fixture withdrawal, 160 three-engine Chromium journeys first attempt and deployment acceptance; [Nightly run 32582207042](https://github.com/kumwe/app/actions/runs/32582207042) passed 142 Firefox/WebKit desktop/mobile journeys first attempt, including all 20 critical journeys plus keyboard/focus, touch, forced colours, 200% text zoom and reflow. [Security run 32582206983](https://github.com/kumwe/app/actions/runs/32582206983) and [Development Compose run 32582206967](https://github.com/kumwe/app/actions/runs/32582206967) passed on the same commit | — |
| 6 | Aggregate seams are clean | Yes — verified at [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad) by [CI run 32582207163](https://github.com/kumwe/app/actions/runs/32582207163). The transaction abstraction is inward with its three-engine proof, the automation adapters sit behind ports, and `P3-C`'s three leaks are closed with boundary tests enforcing each. Relationship/owned-line policy is centralized in `BusinessRecordRelationshipCoordinator`; revision/audit/event publication is centralized in `BusinessRecordMutationPublication`; the facade retains the one transaction and no duplicate policy copy. Recorded exemptions fell 115 → 99 | — |
| 7 | Business-group ownership model in place | Yes — the three-engine proof landed with the ERP-primitives wave, demand by demand against the four-business installation, and it caught and fixed a real PostgreSQL narrowing crash | — |
| 8 | Enterprise document primitives exist and are enforced | Yes — immutable correction by linked reversal, the posting-period lock, the proven counter identity with its fiscal-period reset, the aggregate invariant and the unit-conversion contract are delivered. Definition/catalogue coordinates are immutable so a non-site sequence identity cannot move, and a hard-delete set-null sweep evaluates every source record's posting period and atomically rolls back the entire delete when any source is closed | — |
| 9 | Multi-currency contract holds, with conversion provenance everywhere | Yes — contract, port, pipeline, reports, exports and the rendering half all delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md) | — |
| 10 | Language contract and machinery in place, `en-GB` extracted | Yes — every template, all 44 currently registered console commands and the user-facing error paths resolve from a 2,102-message catalogue; the hardcoded-string gate covers all three surfaces with reasoned exemptions; the direction gate scans every Vite-consumed stylesheet and enforces logical properties; corrected right-to-left baselines are committed; and extension-contributed items bind to declared translation sets. `V2-LNG-010` is the non-gating Gate B translation tail | — |
| 11 | Point of sale not foreclosed | Yes — the replay window, the client-asserted instant, late arrival, the deferrable-validation split and now the synchronisation-time numbering decision ([ADR 0008](decisions/0008-numbering-under-disconnection.md)) with its client-reference uniqueness are delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md) | — |
| 12 | Nothing regressed on three engines | Yes — exact machine-candidate regression proof is [`67cf6c02`](https://github.com/kumwe/app/commit/67cf6c02360f8af4220f8bde7c24297854d45dad): [CI run 32582207163](https://github.com/kumwe/app/actions/runs/32582207163), [Nightly run 32582207042](https://github.com/kumwe/app/actions/runs/32582207042), [Security run 32582206983](https://github.com/kumwe/app/actions/runs/32582206983) and [Development Compose run 32582206967](https://github.com/kumwe/app/actions/runs/32582206967). `regression_matrix` in [`docs/quality/contract.json`](../quality/contract.json) names the three engines, four suites and commands, and `composer quality:contract` fails when the merge workflow stops running the complete suite on any engine. Released-artifact proof remains commit [`2adb2ebe`](https://github.com/kumwe/app/commit/2adb2ebe0cfa95a1aa2953db944479aaa65c30a7), [merge run 32469278190](https://github.com/kumwe/app/actions/runs/32469278190), green Security run 32469277904 and Development Compose run 32469277903, continuous-release run 32472051532 that cut [`v2.0.0-alpha.4`](https://github.com/kumwe/app/releases/tag/v2.0.0-alpha.4), and release run 32472065990 that built, signed/attested and published its checksums, SBOMs and signed checksum bundle | — |
| 13 | Composition contribution contract frozen with a passing compatibility fixture | Yes — nine classified public types in one additive generation, validated at admission and install, with a signed fixture proving the full lifecycle | — |

## Baseline health at `7a83c295`

This is a historical snapshot, kept because it is the last full-programme measurement recorded here, and
it no longer describes the head: the ledger snapshot above is generated from the current ledger, recorded
dependency exemptions have fallen from 115 to 99, and the message catalogue has grown from 117 to 2,102. Read it as
the record of that revision and nothing else. Current programme figures are in the ledger above;
exact machine-candidate workflow evidence is `67cf6c02` / run 32582207163 above, while run 32469278190 remains
historical released-candidate evidence.

**Verified at `7a83c295bce6c23f250384ba787dd5e4595fff0e`.** CI run `31902616995`, security run
`31902616730` and Development Compose run `31902616751` all completed successfully.

- The dependency gate reported 115 recorded exemptions and no new violation. The quality contract verified 26
  checks, 16 local checks and three engines; the extension contract verified four manifest generations, two SPI
  generations, 101 public types and two withdrawn types. The interface programme reported 43 surfaces, 86
  templates, 24 navigation entries, 19 generated instances, 16 actors, 28 tasks, 13 journeys, 60 work items,
  eight findings and three verification reports. The roadmap held exactly 44 open findings. Coverage attribution
  reported nine reasoned rules and 43 tests still owing attribution.
- OpenAPI was current. One compiled catalogue contained 117 messages; 76 templates were checked, 28 enforced and
  48 remained pending, with all 117 identifiers resolving. Eight stylesheets and 96 direction-sensitive
  declarations passed. PHPStan reported no error. Coding-standard normalization inspected 1,300 files and changed
  none. Documentation verification inspected 1,263 files plus 37 immutable migrations: classes were
  1,263/1,263, constants 351/351, enum cases 466/466, methods 6,747/6,747 and properties 371/371, with zero
  violation.
- The unit suite passed 1,978 tests and 26,471 assertions with 23 notices; the architecture suite passed 193 tests
  and 23,228 assertions. The complete relational suite passed on MariaDB (2,514 tests, 54,763 assertions, 23
  notices, two skipped), MySQL (2,514, 54,755, 23, four skipped) and PostgreSQL (2,514, 54,597, 23, 28 skipped).
  Each reused-database run executed 323 integration tests. PostgreSQL reported 4,466 assertions, five expected
  errors, one expected failure and 28 skips; MariaDB 4,632, five, one and two; MySQL 4,624, five, one and four.
  Each verifier matched exactly the six recorded non-idempotent tests and found nothing new. Schema verification,
  signed backup, clean restore and the 16-refusal tamper drill passed on every engine.
- The canonical MariaDB run covered 115 of 1,011 classes (11.37%), 2,110 of 6,607 methods (31.93%) and 47,936 of
  86,459 executable lines (55.44%). The measured global baseline held exactly, and 855 of 950 changed executable
  lines were covered (90.00%) against the 90% floor. Branch coverage remains declared but unenforced because
  `pcov` does not report it.
- MariaDB, MySQL and PostgreSQL each passed 146 of 146 browser tests on the first attempt, with no retry-only pass
  and no failure. The frontend installed 38 packages, audited 39 with no vulnerability, validated 36 sound and 34
  adversarial schemas, and passed type-check and build. The six-case deployed-artifact lane passed. Reproducible
  Composer/ZIP installation and complete production deployment acceptance passed in jobs `95057813111`,
  `95057813132`, `95057813149` and `95057813188` across PostgreSQL, MariaDB and MySQL. The documented Development
  Compose install, migration, topology, readiness, asset and teardown contract passed on its custom port.
- Composer audit reported no advisory. Gitleaks scanned 455 commits and 25.86 MB with no secret. Trivy reported no
  source, lock-file, image or Dockerfile high/critical finding across the Alpine 3.24 production image, its 49 OS
  packages, Composer dependencies and Node dependencies. The source SBOM and security evidence were produced.

---

## How to update this file

The phase board, the open-work table, the Gate B criteria and the ledger snapshot are generated. To move
them, edit [`acceptance-record.json`](acceptance-record.json) (and [`findings.json`](findings.json) when a
finding changes), run `composer acceptance:summary`, and commit the record, this page and
[`acceptance-summary.md`](acceptance-summary.md) together; `composer acceptance:check` fails when a generated
block differs from the record. Everything outside the marked blocks is hand-written; keep it short, and put
narrative in [`README.md`](README.md).

When a work package or finding finishes, set its record entry to `delivered` with its tests, CI jobs and
artifacts, delete a finding from `findings.json`, write what changed into [`CHANGELOG.md`](../../CHANGELOG.md)
citing the identifier, and regenerate. Work committed on an agent branch is `pending-integration` with its
branch and branch evidence until that branch lands. Work that was never planned skips the record and goes
straight to the changelog. `composer roadmap:check` fails if a finished finding is left behind as `closed`.
Acceptance is the maintainer's merge after every required check is green (ADR 0021): no human checkbox,
manual browser, Safari or right-to-left review, or follow-up acceptance commit is ever part of it.
