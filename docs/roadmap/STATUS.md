# Programme status

Read this first. Then read [`README.md`](README.md) for the phase you are in.

**Updated at** `26a7b3963c255064754f541dc8286e75dd566b1f`

> **Open work is here. Finished work is in [`CHANGELOG.md`](../../CHANGELOG.md).** A work package leaves this
> directory and lands in the changelog in the pull request that completes it — see
> [How this document moves](README.md#how-this-document-moves). `findings.json` no longer admits the `closed`
> state and `composer roadmap:check` fails if one appears.

---

## Where we are

| | |
|---|---|
| **Current phase** | Phase 0 — Truth, contracts and decisions |
| **In flight** | Nothing. Phase 0 work packages are unassigned. |
| **Next** | `P0-A` reproducible baseline, `P0-B` claim ledger, `P0-C` public contract classification and compatibility fixtures. These three are independent and may run in parallel. `P0-E` architecture and security decisions follows them, because several decisions depend on what the inventories find. |
| **Gate A** | Not started. 8 exit criteria, 0 met. |
| **Gate B** | Not started. Blocked on Gate A. |

## Phase board

| Phase | State | Blocked on |
|---|---|---|
| 0 — Truth, contracts and decisions | Not started | — |
| 1 — Correctness, security, data entry | Not started | Phase 0 decisions 3, 5, 6 |
| 2 — Truthful gates | Not started | Phase 0 decisions 1, 7, 8 |
| 3 — Seams and the ownership model | Not started | Phases 1 and 2. `P3-F` also needs decision 10's per-category scope table from `P0-C`. |
| 4 — Atomic aggregate documents | Not started | Phase 3; phase 0 decision 2 |
| **Gate A** | **Not assessed** | **Phase 4** |
| 5 — Enterprise scale | Not started | Gate A |
| 6 — Continuity and introspection | Not started | Phase 2 gates (may run parallel to 3–5) |
| 7 — Qualification | Not started | Phases 5 and 6 |
| **Gate B** | **Not assessed** | **Phase 7** |
| M — Maintainability | Not started | Phase 3 seams settled. Blocks nothing. |

## Decisions

Ten, all recorded in [`README.md`](README.md) section 2. Nine are settled there; one has a full decision
record.

| | Decision | Record |
|---|---|---|
| D1 | Scale target is 5,000,000 documents per day | [`capacity-contract.json`](capacity-contract.json) |
| D2 | Two gates, not one | README section 8 |
| D3 | Point-in-time recovery is platform-supported, operator-configured | README section 2 |
| D4 | The backup artifact is reshaped for deduplication | README section 2 |
| D5 | `BusinessRecordService` decomposition leaves the critical path | README section 2 |
| D6 | Runtime operational introspection is a distinct deliverable | README section 2 |
| D7 | A business-group installation is supported, through ownership scopes | [ADR 0001](decisions/0001-resource-ownership-scope.md) |
| D8 | The atomic aggregate contract is designed before it is built | README section 2; ADR due in `P0-E` |
| D9 | Competing products are never named in repository documentation | README section 2 |
| — | The remaining eight `P0-E` decisions | Not yet written |

## Ledger snapshot

**58 open findings** in [`findings.json`](findings.json). The ledger holds open work only.

| State | Count |
|---|---|
| `reproduced` | 18 |
| `open` | 17 |
| `accepted_for_implementation` | 9 |
| `decision_required` | 7 |
| `conditional` | 6 |
| `in_progress` | 1 |
| `verified` | 0 |
| `external` | 0 |
| `closed` | **not an allowed state** — see [`CHANGELOG.md`](../../CHANGELOG.md) |

| Phase | Findings |
|---|---|
| 0 | 8 |
| 1 | 7 |
| 2 | 9 |
| 3 | 7 |
| 4 | 2 |
| 5 | 7 |
| 6 | 7 |
| 7 | 9 |
| M | 1 |
| evidence (`GM-AUD-02`, conditional residual) | 1 |

By severity: 2 critical, 30 high, 18 medium, 8 low.
By origin: 25 from the independent review, 12 still-open entries from the executed gap matrix, 21 discovered
while verifying this roadmap or during the qualification programme.

The 56 findings that were closed when this roadmap was consolidated have left the ledger. Their substance —
the tamper-evident audit work, the record-secret key ring and rotation, the credential lifecycle, the
supply-chain controls, the contention proofs, the failure drills, the observability contract, the restore
drill and the four production-only defects — is in [`CHANGELOG.md`](../../CHANGELOG.md) with the commits
that closed it.

## Gate A criteria

| # | Criterion | Met | Findings |
|---|---|---|---|
| 1 | Extension contract frozen with passing compatibility fixtures | No | `V2-EXT-001` |
| 2 | Atomic aggregate command exists and matches the recorded shape | No | `V2-SCL-003` |
| 3 | Data-entry integrity holds on all three browser surfaces | No | `V2-COR-002` |
| 4 | Correctness and security contradictions fixed | No | `V2-COR-001`, `V2-SEC-001`, `V2-SEC-002`, `V2-SEC-003`, `V2-DB-002`, `V2-DB-003` |
| 5 | Quality gates are truthful | No | `V2-QA-001`, `V2-QA-002`, `V2-QA-003`, `V2-QA-004`, `V2-QA-005`, `V2-DB-001` |
| 6 | Aggregate seams are clean | No | `V2-ARC-003` |
| 7 | Business-group ownership model in place | No | `V2-GRP-001` – `V2-GRP-006` |
| 8 | Nothing regressed on three engines | Not assessed | — |

## Baseline health at this revision

Green: `composer docs:api` (100% across 1,158 classes and 6,315 methods), `composer architecture:policy`,
`composer interface:programme` (42 surfaces, 13 journeys, 60 work items), `composer cs`, `composer analyse`
(PHPStan level `max`, no errors), unit suite (1,534 tests, 22,160 assertions), architecture suite (106
tests, 6,918 assertions).

Not executed here: integration, functional and browser suites, which need live database and browser
services.

---

## How to update this file

It is derivable from [`findings.json`](findings.json) plus the phase board. When a phase or gate moves,
change the two tables at the top, the affected phase row, and the ledger snapshot counts. Do not add
narrative here — narrative belongs in [`README.md`](README.md).

When a work package finishes, delete its findings from `findings.json`, write them into
[`CHANGELOG.md`](../../CHANGELOG.md), and lower the counts here in the same change. `composer roadmap:check`
fails if a finished finding is left behind as `closed`.
