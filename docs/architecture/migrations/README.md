# Migration records

This directory is App's evidence that a Kumwe package was adopted: which symbols left, which release
replaced them, who verified that release, and how any concurrent work was reconciled. The lifecycle, the
states and the identifiers are explained in the [governance guide](../governance/README.md) sections 4–7;
the maintainer rulings are in [decisions.md](../governance/decisions.md). Every file here is validated
against its schema by both `composer qa` gates.

## Ledger state — 2026-09-23

Every package the App has adopted, its change-set state and the App pull request that carried it. A
change set is `core-integrated` only once the merged master commit is recorded (`phase_2.merged_sha`);
`app-pr-ready` with a null merge commit means the closure record is still owed.

| Package | Release | Ledger | Change set | State | App PR | Merged |
|---|---|---|---|---|---|---|
| `kumwe/transaction` | `0.1.2` | `KUMWE-MIG-2026-001` | `KUMWE-CS-2026-001` | `core-integrated` | #142 | `c43eb482` |
| `kumwe/sequence` | `0.2.1` | `KUMWE-MIG-2026-002` | `KUMWE-CS-2026-002` | `core-integrated` | #139 | `32d6a6f3` |
| `kumwe/secret-envelope` | `0.1.1` | `KUMWE-MIG-2026-003` | `KUMWE-CS-2026-003` | `core-integrated` | #142 | `c43eb482` |
| `kumwe/access-context` | `0.1.2` | `KUMWE-MIG-2026-004` | `KUMWE-CS-2026-004` | `core-integrated` | #141 | `795583ee` |
| `kumwe/localization` | `0.1.1` | `KUMWE-MIG-2026-005` | `KUMWE-CS-2026-005` | `core-integrated` | #142 | `c43eb482` |
| `kumwe/canonical-json` | `0.1.1` | `KUMWE-MIG-2026-007` | `KUMWE-CS-2026-007` | `core-integrated` | #138 | `1e768cbb` |
| `kumwe/computation` | `0.3.3` | `KUMWE-MIG-2026-008` | `KUMWE-CS-2026-008` | `core-integrated` (provisioning only; the business cutover is Computation Phase 2) | #140 | `4774e0d5` |
| `kumwe/producer` | `0.3.0` | `KUMWE-MIG-2026-032` | `KUMWE-CS-2026-032` | `core-integrated` | #137 | `7f851278` |
| `kumwe/navigation` | `0.1.3` | `KUMWE-MIG-2026-035` | `KUMWE-CS-2026-035` | `core-integrated` | #146 | `008237a0` |
| `kumwe/audit` | `0.1.2` | `KUMWE-MIG-2026-021` | `KUMWE-CS-2026-021` | `app-pr-ready` | #151 | — |
| `kumwe/business-policy` | `0.1.1` | `KUMWE-MIG-2026-022` | `KUMWE-CS-2026-022` | `app-pr-ready` | #151 | — |
| `kumwe/conversion` | `0.1.5` | `KUMWE-MIG-2026-031` | `KUMWE-CS-2026-031` | `app-pr-ready` | #151 | — |

`kumwe/extension-sdk 0.2.4` remains the one legacy-unmanifested entry of
[`legacy-packages.json`](../governance/legacy-packages.json); its Version 2 successor `0.3.2` is published and
it leaves the registry when that is adopted, as `kumwe/conversion` did at `0.1.5` (`KUMWE-MIG-2026-031`).

### The remaining catalogue

The Version 2 catalogue has thirty targets ([audit of 2026-09-07](audits/2026-09-07/requirements.md)); twelve
are adopted above (the Engine and its binding are provisioned as `ext-kumwe_engine 1.0.3`). Every remaining
PHP package is published on Packagist with Version 2 manifests and a release record, and each record
pre-allocates the ledger and change-set identifiers the App must use (the capability index refuses a ledger
record whose id differs from the installed record's `migration_id`, or whose `change_set` differs from the
record's). Those identifiers are listed here because several collide; a collision is escalated, never
renumbered in the App (D-GOV-3), and is resolved by a successor release of the package that carries a free
identifier.

| Package | Release | Record ids | Kumwe requirements | Identifier status |
|---|---|---|---|---|
| `kumwe/contribution` | `0.1.1` | `MIG-006` / `CS-006` | — | free |
| `kumwe/access-control` | `0.1.2` | `MIG-009` / `CS-009` | access-context | free |
| `kumwe/business-definition` | `0.1.2` | `MIG-010` / `CS-010` | localization, sequence | free |
| `kumwe/idempotency` | `0.1.2` | `MIG-020` / `CS-020` | canonical-json | free |
| `kumwe/approval` | `0.1.2` | `MIG-023` / `CS-023` | access-context, access-control, audit, transaction | free |
| `kumwe/interface-standard` | `0.1.2` | `MIG-025` / `CS-025` | contribution, access-control | free |
| `kumwe/automation` | `0.2.2` | `MIG-026` / `CS-026` | canonical-json, contribution, access-context | free |
| `kumwe/integration` | `0.2.3` | `MIG-027` / `CS-035` | canonical-json, contribution, access-context, automation | **conflict** — `CS-035` is `kumwe/navigation`'s, and a change set must share its ledger record's sequence (D-GOV-2) |
| `kumwe/conversion-extension` | `0.1.4` | `MIG-028` / `CS-028` | contribution, conversion | free |
| `kumwe/record-values` | `0.1.4` | `MIG-029` / `CS-029` | conversion | free |
| `kumwe/business-schema` | `0.1.3` | `MIG-030` / `CS-030` | business-definition, sequence | free |
| `kumwe/record-query` | `0.1.4` | `MIG-039` / `CS-039` | record-values, business-definition, conversion | free — `0.1.3` claimed `MIG-031` / `CS-031`, which `kumwe/conversion 0.1.5` holds in this ledger |
| `kumwe/record-model` | `0.1.3` | `MIG-032` / `CS-032` | access-context, business-definition, record-values | **conflict** — `MIG-032` is `kumwe/producer`'s, already in this ledger |
| `kumwe/extension-sdk` | `0.3.2` | `MIG-033` / `CS-033` | the sixteen-package train | **conflict** — `kumwe/reporting 0.1.4` claims the same pair |
| `kumwe/reporting` | `0.1.4` | `MIG-033` / `CS-033` | business-definition, contribution, integration, access-context, conversion, access-control | **conflict** — see `kumwe/extension-sdk` |
| `kumwe/content-model` | `0.2.0` | `MIG-034` / `CS-034` | access-context, access-control, localization | free, but three other records name `CS-034` |
| `kumwe/administrator-contract` | `0.2.1` | `MIG-036` / `CS-034` | access-control, contribution | **conflict** — `CS-034` is not its sequence (D-GOV-2) |
| `kumwe/portal-contract` | `0.2.1` | `MIG-037` / `CS-034` | access-control, contribution | **conflict** — as above |
| `kumwe/business-surface-contract` | `0.1.3` | `MIG-038` / `CS-034` | access-context, contribution, conversion, canonical-json, idempotency, record-model, record-query, record-values | **conflict** — as above |

The extension-sdk `0.3.2` train selects, at exact versions, access-control `0.1.2`, administrator-contract
`0.2.1`, automation `0.2.2`, business-policy `0.1.1`, business-surface-contract `0.1.3`, canonical-json `0.1.1`,
contribution `0.1.1`, conversion `0.1.5`, idempotency `0.1.2`, integration `0.2.3`, portal-contract `0.2.1`,
producer `0.3.0`, record-model `0.1.3`, record-query `0.1.3`, record-values `0.1.4` and reporting `0.1.4`.
Composer resolves that whole set together with approval, business-definition, business-schema,
content-model, conversion-extension and interface-standard against the current lock with no removal.

## Layout

```
docs/architecture/migrations/
  README.md
  KUMWE-MIG-YYYY-NNN.yaml                              migration ledger record, one per adoption
  change-sets/
    KUMWE-CS-YYYY-NNN.yaml                             cross-repository state of the same migration
  conflicts/
    KUMWE-CONFLICT-YYYY-NNN.yaml                       semantic three-way resolutions
  trains/
    <id>.yaml                                          integration train, one per Phase 2 PR
  evidence/
    <MIG>/
      RELEASE-ATTESTATION.yaml                         status: verified
      RELEASE-VERIFICATION-FAILED.yaml                 status: failed, known_gaps non-empty
      ENGINE-CANDIDATE-ATTESTATION.yaml                where a native Engine candidate is involved
    legacy/
      <package>/
        VERIFIED-LEGACY-RELEASE.yaml                   pre-Version-2 upstream dependency, human approved
```

`<MIG>` is the ledger identifier `KUMWE-MIG-YYYY-NNN`. The same `NNN` names the change set of one migration
(D-GOV-2). A file name equals the record's id.

Schemas and examples live under `docs/architecture/governance/`:

- Ledger — [schema](../governance/schemas/migration-ledger.v1.schema.json),
  [example](../governance/examples/migration-ledger.v1.example.yaml)
- Change set — [schema](../governance/schemas/change-set.v2.schema.json),
  [example](../governance/examples/change-set.v2.example.yaml)
- Conflict — [schema](../governance/schemas/conflict-ledger.v1.schema.json),
  [example](../governance/examples/conflict-ledger.v1.example.yaml)
- Train — [schema](../governance/schemas/integration-train.v1.schema.json),
  [example](../governance/examples/integration-train.v1.example.yaml)
- Release attestation — [schema](../governance/schemas/release-attestation.v2.schema.json),
  [example](../governance/examples/release-attestation.v2.example.yaml)
- Engine candidate attestation — [schema](../governance/schemas/engine-candidate-attestation.v1.schema.json),
  [example](../governance/examples/engine-candidate-attestation.v1.example.yaml)
- Verified legacy release — [schema](../governance/schemas/verified-legacy-release.v1.schema.json),
  [example](../governance/examples/verified-legacy-release.v1.example.yaml)

## What Phase 2 writes

A Phase 2 pull request adopts one verified package release and adds, in the same pull request:

1. `KUMWE-MIG-YYYY-NNN.yaml` — the ledger record: package and exact version, artifact digest, the installed
   handoff path and its sha256, the attestation path, retired namespace roots, every old-to-new FQCN,
   removed paths, removed and retained tests, DI changes (each intentional host binding of a package
   service, with a note), capability-index entries, release evidence, the App PR, roadmap and non-roadmap
   references and any conflict ids.
2. `change-sets/KUMWE-CS-YYYY-NNN.yaml` — the change set with the Phase 1, release and Phase 2 coordinates
   filled from observed facts, `state: app-pr-ready`, and `completion_claim: false`.
3. `trains/<id>.yaml` — the integration train, even for a single PR (D-GOV-8).
4. `evidence/<MIG>/RELEASE-ATTESTATION.yaml` — the fresh session's attestation, copied unchanged
   (D-GOV-11). A failed verification is `RELEASE-VERIFICATION-FAILED.yaml` instead, and blocks the PR.
5. `conflicts/KUMWE-CONFLICT-YYYY-NNN.yaml` — one per nontrivial semantic resolution, when any occurred.
6. The regenerated `docs/architecture/capability-index.md`, the re-recorded
   `docs/architecture/governance/core-growth-baseline.json`, `docs/quality/baseline.json`, and the
   `CHANGELOG.md` entry citing `(#PR)` and the `NRM-YYYY-NNN` or roadmap reference.

After the human merge, a follow-up record change sets the change set's `phase_2.merged_sha` and
`state: core-integrated` from the green merged target. Nothing in this directory ever claims
`objective-verified` or `gate-accepted` for an extraction alone.

## Merge rules

These records are additive. When two branches both add or change records:

- Merge by stable identifier — package, capability, change set, evidence path — never by line position.
  Keep every valid entry with its exact release, version and namespace metadata.
- Never resolve a conflicted record wholesale with `ours` or `theirs`. Inspect the base, state both
  objectives, design the combined result, and record a nontrivial resolution in `conflicts/`.
- Never renumber another migration to clear a conflict (Kumwe-v2-04). Two records with the same identifier
  and different meanings are escalated, not renumbered.
- Never hand-edit `composer.lock` to reconcile two adoptions: resolve `composer.json` semantically, then let
  Composer regenerate the lock from the latest target, then regenerate the capability index.
- Never lose a non-roadmap entry, and never claim objective completion because an integration landed.

## What must not appear here

- A ledger record naming a `legacy-unmanifested` package: a legacy entry cannot satisfy a migration release
  gate (governance guide section 1).
- A predicted tag, version, URL or hash. Pending events belong in the handoff's expectations.
- A `RELEASE-ATTESTATION.yaml` whose `status` is not `verified` (D-GOV-4).
- A record that disagrees with the installed `MIGRATION-HANDOFF.md`: the ledger's `handoff_sha256` must
  equal the installed file's digest, and the index refuses a mismatch.
