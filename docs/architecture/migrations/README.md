# Migration records

This directory is App's evidence that a Kumwe package was adopted: which symbols left, which release
replaced them, who verified that release, and how any concurrent work was reconciled. The lifecycle, the
states and the identifiers are explained in the [governance guide](../governance/README.md) sections 4–7;
the maintainer rulings are in [decisions.md](../governance/decisions.md). Every file here is validated
against its schema by both `composer qa` gates.

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
