# Programme status

Read this first. Then read [`README.md`](README.md) for the phase you are in.

**Updated at** `7a83c295bce6c23f250384ba787dd5e4595fff0e`

> **Open work is here. Finished work is in [`CHANGELOG.md`](../../CHANGELOG.md).** Two paths, and both end in
> the changelog. **Planned work** lives here while it is open and its entry is deleted from this directory
> and written into the changelog in the pull request that completes it. **Unplanned work** — the things that
> come up, get fixed, and never had a roadmap entry — goes straight into the changelog, with nothing to
> remove because it was never here. Either way, **the changelog is the single record of what has been done
> and this directory is the single record of what has not.** See
> [How this document moves](README.md#how-this-document-moves). Nothing here is ticked off: an item that is
> still written down is still outstanding. `findings.json` does not admit the `closed` state and
> `composer roadmap:check` fails if one appears.

---

## Where we are

| | |
|---|---|
| **Current phase** | Phase 0 — Truth, contracts and decisions |
| **In flight** | Phase 3's business-group ownership model has landed ahead of its phase; `P3-F` is reduced to its three-engine proof. `P3-B` is complete and `P3-A` is reduced to its three-engine proof, so the aggregate command's persistence seams now point inward. Phase 2's gate work is part delivered: `P2-A` complete, `P2-C`'s semantic checker complete, `P2-G`'s deployed-artifact lane complete. Phase 0 work packages remain unassigned. |
| **Next** | `P0-A` reproducible baseline, `P0-B` claim ledger, `P0-C` public contract classification and compatibility fixtures. These three are independent and may run in parallel. `P0-E` architecture and security decisions follows them, because several decisions depend on what the inventories find. |
| **Open decisions** | None in `decision_required`. The offline-numbering question was decided — allocation at synchronisation time, [ADR 0008](decisions/0008-numbering-under-disconnection.md) — and implemented, which met Gate A criterion 11. |
| **Gate A** | Not started. 13 exit criteria, 9 met; criterion 13 is the composition contribution contract added by decision D16. The enterprise document primitives, the offline-numbering decision, the three-engine seam and ownership proofs and the PostgreSQL index isolation all landed in the ERP-primitives wave. |
| **Gate B** | Not started. Blocked on Gate A. 12 exit criteria; criterion 12 is the Studio visual composition integration added by decision D16. |

## Phase board

| Phase | Gate | State | Blocked on |
|---|---|---|---|
| 0 — Truth, contracts and decisions | A | Not started | — |
| 1 — Correctness, security, data entry | A | Not started | Phase 0 decisions 3, 5, 6 |
| 2 — Truthful gates | A | In progress — one quality contract, semantic dependency checking and the deployed-artifact lane are delivered | Phase 0 decisions 1, 7, 8 |
| 3 — Seams and the ownership model | A | In progress — the business-group ownership model and the inward persistence seams have landed; `P3-A` and `P3-F` now only owe their three-engine proofs | Phases 1 and 2 |
| 4 — Atomic aggregate documents | A | Not started | Phase 3; phase 0 decision 2 |
| E — Enterprise document primitives | A | Delivered — every package complete; two follow-up findings remain | Phase 3. The offline-numbering choice is decided and implemented (ADR 0008). |
| L — Language, locale and multilingual content | A, with a B tail | In progress — `PL-A` and `PL-B` delivered, `PL-C`, `PL-E` and `PL-F` part delivered | `PL-F`'s screenshots and `PL-G` need phase 2's `P2-E` matrix; the language axis they run on is built. Otherwise parallel to 3, 4 and E. |
| **Gate A** | | **Not assessed** | **Phases 4, E and L, and phase S's Gate A half** |
| 5 — Enterprise scale | B | Not started | Gate A |
| 6 — Continuity and introspection | B | Not started | Phase 2 gates (may run parallel to 3–5) |
| 7 — Qualification | B | Not started | Phases 5 and 6, and phase L's `PL-G` |
| S — Studio visual composition | A, with a B integration | Not started | The Gate A half needs `P0-C`'s classification machinery; the Gate B half needs Gate A. Decision D16 and ADR 0007 accepted |
| **Gate B** | | **Not assessed** | **Phases 7 and S** |
| M — Maintainability | — | Not started | Phase 3 seams settled. Blocks nothing. |

## Open work packages by phase

The roadmap holds only what is outstanding, so every package listed here is open. A package leaves this
table when it completes, in the same change that writes it into the changelog.

| Phase | Packages | Findings |
|---|---|---|
| 0 | `P0-A` … `P0-E` | `V2-DOC-001`, `V2-ERP-007` |
| 1 | `P1-B`, `P1-C`, `P1-E`, `P1-F` | — |
| 2 | `P2-B` … `P2-I` (`P2-A` complete; `P2-C` and `P2-G` part delivered) | `V2-QA-001`, `V2-QA-004`, `V2-QA-007`, `V2-QA-008`, `V2-QA-009`, `V2-DB-001`, `V2-REL-001`, `V2-REL-002`, `GM-SUP-09` |
| 3 | `P3-A`, `P3-C` … `P3-F` (`P3-B` complete) | — |
| 4 | `P4-A` … `P4-D` | — |
| E | All packages complete (`PE-A` … `PE-G`) | `V2-ERP-008`, `V2-ERP-009` |
| L | `PL-C`, `PL-D`, `PL-E`, `PL-F`, `PL-G` (`PL-A` and `PL-B` complete) | `V2-LNG-001`, `V2-LNG-007` – `V2-LNG-010`, `V2-LNG-012` |
| 5 | `P5-A` … `P5-I` | `V2-SCL-001`, `V2-SCL-002`, `V2-SCL-004` – `V2-SCL-008` |
| 6 | `P6-A` … `P6-D` | `V2-DR-001` – `V2-DR-004`, `V2-OPS-001`, `GM-BAK-04`, `GM-BAK-08` |
| S | `S-A` (Gate A), `S-B` … `S-G` | `V2-STU-001` – `V2-STU-007` |
| 7 | `P7-A` … `P7-I` | `V2-UX-001`, `V2-UX-002`, `GM-AUD-08`, `GM-IDN-04` – `GM-IDN-07`, `GM-SUP-05`, `GM-SUP-08`, `GM-OBS-05`, `V2-UX-003` |
| M | Lane M, no packages assigned yet | `V2-ARC-002` |

## Decisions

Sixteen, all recorded in [`README.md`](README.md) section 2. Seven carry a full decision record.

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
| D16 | Studio visual composition is the Version 2 authoring surface, integrated at Gate B | [ADR 0007](decisions/0007-studio-visual-composition-integration.md) |
| — | The remaining `P0-E` decisions | Not yet written |

## Ledger snapshot

**53 open findings** in [`findings.json`](findings.json). The ledger holds open work only.

| State | Count |
|---|---|
| `accepted_for_implementation` | 16 |
| `reproduced` | 9 |
| `open` | 19 |
| `conditional` | 6 |
| `decision_required` | 0 |
| `in_progress` | 3 |
| `verified` | 0 |
| `external` | 0 |
| `closed` | **not an allowed state** — see [`CHANGELOG.md`](../../CHANGELOG.md) |

| Phase | Findings |
|---|---|
| 0 | 2 |
| 1 | 0 |
| 2 | 9 |
| 3 | 0 |
| 4 | 0 |
| E | 2 |
| L | 6 |
| 5 | 7 |
| 6 | 7 |
| 7 | 11 |
| S | 7 |
| M | 1 |
| evidence (`GM-AUD-02`, conditional residual) | 1 |

| Gate | Findings |
|---|---|
| A | 10 |
| B | 22 |
| none | 20 |

By severity: 0 critical, 25 high, 20 medium, 8 low.
By origin: 15 from the independent review, 12 still-open entries from the executed gap matrix, 26 discovered
while verifying this roadmap, during the qualification programme, or from decisions D7, D10 through D14
and D16.

The 56 findings that were closed when this roadmap was consolidated have left the ledger. Their substance —
the tamper-evident audit work, the record-secret key ring and rotation, the credential lifecycle, the
supply-chain controls, the contention proofs, the failure drills, the observability contract, the restore
drill and the four production-only defects — is in [`CHANGELOG.md`](../../CHANGELOG.md) with the commits
that closed it. Further findings have left since, including the machine surface's credential transport and risk
taxonomy,
the MySQL/MariaDB schema-global foreign-key names, the extension trust posture, the root locale-addressing
defect and the unreachable catalogue-refusal seam. Their completed substance is recorded in the changelog.
PostgreSQL's separate schema-global non-primary-index namespace is closed: migration `20260823010000` renames every literal non-primary index to the digest derivation, proven by installing two complete prefixed core plans into one PostgreSQL schema.

## Gate A criteria

| # | Criterion | Met | Findings |
|---|---|---|---|
| 1 | Extension contract frozen with passing compatibility fixtures | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 2 | Atomic aggregate command exists and matches the recorded shape | Yes | Recorded in [`CHANGELOG.md`](../../CHANGELOG.md); [ADR 0005](decisions/0005-atomic-aggregate-document-contract.md) |
| 3 | Data-entry integrity holds on all three browser surfaces | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 4 | Correctness and security contradictions fixed | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 5 | Quality gates are truthful | Partly — one manifest defines what local, CI, nightly and release execute and delegates specially provisioned checks to their named jobs; semantic dependency checking handles mixed grouped imports against a shrinking baseline; supplied idempotency evidence must cover every declared pass and agree with independent collection and runner status; changelog citations must resolve in current history; the deployed-artifact lane reproduces all four production-only defects; and the browser matrix runs its locale projects only where their locale contract is defined. Outstanding: behavioural coverage attribution, the reverse-order pass enforced in CI beside the repeat pass (its first corrected run is green and the record is empty), the remaining nightly browser dimensions, the pdo_pgsql stale-result anomaly and the fixture-accumulation ceiling it exposed, and the getenv configuration sweep | `V2-QA-001`, `V2-QA-004`, `V2-QA-007`, `V2-QA-008`, `V2-QA-009`, `V2-DB-001` |
| 6 | Aggregate seams are clean | Partly — the transaction abstraction is inward and the automation Doctrine adapters sit in Infrastructure behind ports, both now enforced by the architecture gate and recorded in [`CHANGELOG.md`](../../CHANGELOG.md); `P3-A`'s three-engine transaction proof is delivered — commit ordering, rollback residue, exception translation, retryable contention, nested semantics and audit-and-outbox atomicity proven on the engine matrix; `P3-C`'s delivery and presentation leakage remains | — |
| 7 | Business-group ownership model in place | Yes — the three-engine proof landed with the ERP-primitives wave, demand by demand against the four-business installation, and it caught and fixed a real PostgreSQL narrowing crash | — |
| 8 | Enterprise document primitives exist and are enforced | Yes — immutable correction by linked reversal, the posting-period lock, the proven counter identity with its fiscal-period reset, the aggregate invariant and the unit-conversion contract are all delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md); two follow-up findings cover the owning-site counter coordinate and the set-null sweep | — |
| 9 | Multi-currency contract holds, with conversion provenance everywhere | Yes — contract, port, pipeline, reports, exports and the rendering half all delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md) | — |
| 10 | Language contract and machinery in place, `en-GB` extracted | Partly — locale negotiation, the identifier grammar, the compiled catalogue, ICU validation and formatting, transactional and markup-safe administered overrides, organization-aware scope, locale-bearing public delivery, localized generated definition surfaces and the extension catalogue path are delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md). Extension translation-set declarations still need a frozen item-association contract | `V2-LNG-001`, `V2-LNG-007`, `V2-LNG-008`, `V2-LNG-009`, `V2-LNG-012` |
| 11 | Point of sale not foreclosed | Yes — the replay window, the client-asserted instant, late arrival, the deferrable-validation split and now the synchronisation-time numbering decision ([ADR 0008](decisions/0008-numbering-under-disconnection.md)) with its client-reference uniqueness are delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md) | — |
| 12 | Nothing regressed on three engines | Assessable, and assessed on every merge run. `regression_matrix` in [`docs/quality/contract.json`](../quality/contract.json) names the three engines, the four suites and the commands, and `composer quality:contract` fails when the merge workflow stops running the complete suite on any of them. Assessing the criterion is now reading a run rather than inspecting a workflow; it is asserted at a commit when that run is green and the commit is recorded here | — |
| 13 | Composition contribution contract frozen with a passing compatibility fixture | No | `V2-STU-001` |

## Gate B criteria that moved

Gate B's ten original criteria are unchanged and are listed in [`README.md`](README.md) section 8. Two
were added:

| # | Criterion | Met | Findings |
|---|---|---|---|
| 11 | All nine languages ship and each is qualified in its own right | No | `V2-LNG-010` |
| 12 | The visual composition integration ships and is qualified | No | `V2-STU-002` – `V2-STU-007` |

## Baseline health at this revision

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

It is derivable from [`findings.json`](findings.json) plus the phase board. When a phase or gate moves,
change the tables at the top, the affected phase row, the open-package table, and the ledger snapshot
counts. Do not add narrative here — narrative belongs in [`README.md`](README.md).

When a work package finishes, delete its findings from `findings.json`, remove its row from the open-package
table, write what changed into [`CHANGELOG.md`](../../CHANGELOG.md), and lower the counts here in the same
change. Work that was never planned skips the first two steps and goes straight to the changelog.
`composer roadmap:check` fails if a finished finding is left behind as `closed`.
