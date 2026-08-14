# Programme status

Read this first. Then read [`README.md`](README.md) for the phase you are in.

**Updated at** `26a7b3963c255064754f541dc8286e75dd566b1f`

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
| **In flight** | Phase 3's business-group ownership model has landed ahead of its phase; `P3-F` is reduced to its three-engine proof. Phase 0 work packages remain unassigned. |
| **Next** | `P0-A` reproducible baseline, `P0-B` claim ledger, `P0-C` public contract classification and compatibility fixtures. These three are independent and may run in parallel. `P0-E` architecture and security decisions follows them, because several decisions depend on what the inventories find. |
| **The one open decision** | `V2-POS-002` — whether a disconnected terminal receives its document number at synchronisation time or from a per-terminal reserved block. It trades against the shipped gapless guarantee, so it is the product owner's to make, in `P0-E` decision 12. |
| **Gate A** | Not started. 12 exit criteria, 1 met; criterion 7's ownership model is built and owes only its three-engine proof. |
| **Gate B** | Not started. Blocked on Gate A. |

## Phase board

| Phase | Gate | State | Blocked on |
|---|---|---|---|
| 0 — Truth, contracts and decisions | A | Not started | — |
| 1 — Correctness, security, data entry | A | Not started | Phase 0 decisions 3, 5, 6 |
| 2 — Truthful gates | A | Not started | Phase 0 decisions 1, 7, 8 |
| 3 — Seams and the ownership model | A | In progress — the business-group ownership model has landed; `P3-F` now only owes its three-engine proof | Phases 1 and 2 |
| 4 — Atomic aggregate documents | A | Not started | Phase 3; phase 0 decision 2 |
| E — Enterprise document primitives | A | Not started | Phase 3; phase 0 decisions 9 and 12, including the `V2-POS-002` choice. `PE-F` cannot run beside `P4-C` — both own numbering. |
| L — Language, locale and multilingual content | A, with a B tail | In progress — `PL-A` delivered, `PL-B`, `PL-C`, `PL-E` and `PL-F` part delivered | `PL-F`'s remaining half and `PL-G` need phase 2's `P2-E` locale axis. Otherwise parallel to 3, 4 and E. |
| **Gate A** | | **Not assessed** | **Phases 4, E and L** |
| 5 — Enterprise scale | B | Not started | Gate A |
| 6 — Continuity and introspection | B | Not started | Phase 2 gates (may run parallel to 3–5) |
| 7 — Qualification | B | Not started | Phases 5 and 6, and phase L's `PL-G` |
| **Gate B** | | **Not assessed** | **Phase 7** |
| M — Maintainability | — | Not started | Phase 3 seams settled. Blocks nothing. |

## Open work packages by phase

The roadmap holds only what is outstanding, so every package listed here is open. A package leaves this
table when it completes, in the same change that writes it into the changelog.

| Phase | Packages | Findings |
|---|---|---|
| 0 | `P0-A` … `P0-E` | `V2-DOC-001`, `V2-EXT-001`, `V2-ERP-006`, `V2-ERP-007`, `V2-POS-002` |
| 1 | `P1-B`, `P1-C`, `P1-E`, `P1-F` | `V2-SEC-001`, `V2-SEC-002`, `V2-SEC-003`, `V2-DB-003` |
| 2 | `P2-A` … `P2-I` | `V2-ARC-001`, `V2-QA-001` – `V2-QA-005`, `V2-DB-001`, `V2-REL-001`, `GM-SUP-09` |
| 3 | `P3-A` … `P3-F` | `V2-ARC-003` |
| 4 | `P4-A` … `P4-D` | `V2-SCL-003`, `V2-ERP-001` |
| E | `PE-B` … `PE-G` (`PE-A` complete) | `V2-ERP-002` – `V2-ERP-005`, `V2-CUR-005`, `V2-POS-001`, `V2-POS-003`, `V2-POS-004` |
| L | `PL-A` … `PL-G` | `V2-LNG-001` – `V2-LNG-010`, `V2-MLC-001` – `V2-MLC-004` |
| 5 | `P5-A` … `P5-I` | `V2-SCL-001`, `V2-SCL-002`, `V2-SCL-004` – `V2-SCL-008` |
| 6 | `P6-A` … `P6-D` | `V2-DR-001` – `V2-DR-004`, `V2-OPS-001`, `GM-BAK-04`, `GM-BAK-08` |
| 7 | `P7-A` … `P7-I` | `V2-UX-001`, `GM-AUD-08`, `GM-IDN-04` – `GM-IDN-07`, `GM-SUP-05`, `GM-SUP-08`, `GM-OBS-05` |
| M | Lane M, no packages assigned yet | `V2-ARC-002` |

## Decisions

Fourteen, all recorded in [`README.md`](README.md) section 2. Five carry a full decision record.

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
| — | The remaining `P0-E` decisions | Not yet written |

## Ledger snapshot

**62 open findings** in [`findings.json`](findings.json). The ledger holds open work only.

| State | Count |
|---|---|
| `accepted_for_implementation` | 21 |
| `reproduced` | 14 |
| `open` | 17 |
| `conditional` | 7 |
| `decision_required` | 2 |
| `in_progress` | 1 |
| `verified` | 0 |
| `external` | 0 |
| `closed` | **not an allowed state** — see [`CHANGELOG.md`](../../CHANGELOG.md) |

| Phase | Findings |
|---|---|
| 0 | 5 |
| 1 | 4 |
| 2 | 9 |
| 3 | 1 |
| 4 | 0 |
| E | 8 |
| L | 10 |
| 5 | 7 |
| 6 | 7 |
| 7 | 9 |
| M | 1 |
| evidence (`GM-AUD-02`, conditional residual) | 1 |

| Gate | Findings |
|---|---|
| A | 24 |
| B | 23 |
| none | 15 |

By severity: 0 critical, 29 high, 24 medium, 9 low.
By origin: 23 from the independent review, 12 still-open entries from the executed gap matrix, 27 discovered
while verifying this roadmap, during the qualification programme, or from decisions D7 and D10 through D14.

The 56 findings that were closed when this roadmap was consolidated have left the ledger. Their substance —
the tamper-evident audit work, the record-secret key ring and rotation, the credential lifecycle, the
supply-chain controls, the contention proofs, the failure drills, the observability contract, the restore
drill and the four production-only defects — is in [`CHANGELOG.md`](../../CHANGELOG.md) with the commits
that closed it.

## Gate A criteria

| # | Criterion | Met | Findings |
|---|---|---|---|
| 1 | Extension contract frozen with passing compatibility fixtures | No | `V2-EXT-001` |
| 2 | Atomic aggregate command exists and matches the recorded shape | Yes | Recorded in [`CHANGELOG.md`](../../CHANGELOG.md); [ADR 0005](decisions/0005-atomic-aggregate-document-contract.md) |
| 3 | Data-entry integrity holds on all three browser surfaces | Yes | — (recorded in [`CHANGELOG.md`](../../CHANGELOG.md)) |
| 4 | Correctness and security contradictions fixed | No | `V2-SEC-001`, `V2-SEC-002`, `V2-SEC-003`, `V2-DB-003` |
| 5 | Quality gates are truthful | No | `V2-QA-001`, `V2-QA-002`, `V2-QA-003`, `V2-QA-004`, `V2-QA-005`, `V2-DB-001` |
| 6 | Aggregate seams are clean | No | `V2-ARC-003` |
| 7 | Business-group ownership model in place | Built; owes the three-engine proof in `P3-F` | — |
| 8 | Enterprise document primitives exist and are enforced | Partly — the aggregate invariant is delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md); correction, period close, sequence scoping and unit conversion remain | `V2-ERP-002` – `V2-ERP-005` |
| 9 | Multi-currency contract holds, with conversion provenance everywhere | Partly — contract, port, pipeline, reports and exports delivered; rendering half open | `V2-CUR-005` |
| 10 | Language contract and machinery in place, `en-GB` extracted | Partly — locale negotiation, the identifier grammar, the compiled catalogue and ICU formatting are delivered and recorded in [`CHANGELOG.md`](../../CHANGELOG.md) | `V2-LNG-001`, `V2-LNG-006` – `V2-LNG-009`, `V2-MLC-001` – `V2-MLC-004` |
| 11 | Point of sale not foreclosed | No | `V2-POS-001`, `V2-POS-003`, `V2-POS-004`; `V2-POS-002` decided |
| 12 | Nothing regressed on three engines | Not assessed | — |

## Gate B criteria that moved

Gate B's ten criteria are unchanged and are listed in [`README.md`](README.md) section 8. One was added:

| # | Criterion | Met | Findings |
|---|---|---|---|
| 11 | All nine languages ship and each is qualified in its own right | No | `V2-LNG-010` |

## Baseline health at this revision

Green: `composer docs:api` (100% across 1,172 classes and 6,365 methods), `composer architecture:policy`,
`composer interface:programme` (42 surfaces, 13 journeys, 60 work items), `composer roadmap:check` (62 open
findings), `composer openapi:check`, `composer cs`, `composer analyse` (PHPStan level `max`, no errors),
unit suite (1,555 tests, 22,316 assertions), architecture suite (114 tests, 9,386 assertions).

Not executed here: the integration, functional and browser suites, which need the live database, cache and
browser services.

---

## How to update this file

It is derivable from [`findings.json`](findings.json) plus the phase board. When a phase or gate moves,
change the tables at the top, the affected phase row, the open-package table, and the ledger snapshot
counts. Do not add narrative here — narrative belongs in [`README.md`](README.md).

When a work package finishes, delete its findings from `findings.json`, remove its row from the open-package
table, write what changed into [`CHANGELOG.md`](../../CHANGELOG.md), and lower the counts here in the same
change. Work that was never planned skips the first two steps and goes straight to the changelog.
`composer roadmap:check` fails if a finished finding is left behind as `closed`.
