# Governance rulings

The Version 2 documents were written for parallel execution across many repositories, and two of them
describe migration progress with different state vocabularies. These rulings are the maintainer's strict
reading where those documents differ, are silent, or leave a choice open; each names what it reconciles and
what an agent must do. Where a ruling and a Version 2 document differ in wording, the ruling governs.

## D-GOV-1 — Canonical migration state

**Ruling.** A change-set record's `state` uses the eight Kumwe-v2-07 states: `enabling-refactor`,
`package-implemented`, `package-released`, `release-verified`, `app-pr-ready`, `core-integrated`,
`objective-verified`, `gate-accepted`. The Kumwe-v2-04 ten-state machine maps onto them: `planned` and
`phase-1-active` → `enabling-refactor`; `package-pr-ready` → `package-implemented`; `package-merged` →
`package-released`; `phase-2-active` → `release-verified`; `app-integrated` → `core-integrated`. The
handoff's own `state: draft_pr_open` is a handoff field, not a migration state.

**Reconciles.** Kumwe-v2-04 "Migration state machine" with Kumwe-v2-07 "Separate migration and roadmap
state machines" and the Kumwe-v2-08 handoff schema.

**For agents.** Write only canonical names into a record; the schema rejects the others. Translate a
Kumwe-v2-04 name when you read one in prose.

## D-GOV-2 — One migration, two identifiers, two records

**Ruling.** `KUMWE-CS-YYYY-NNN` names the central change-set record in
`docs/architecture/migrations/change-sets/`; `KUMWE-MIG-YYYY-NNN` names the App migration ledger record in
`docs/architecture/migrations/`. One migration allocates the same `NNN` to both. The ledger record is the App
adoption evidence; the change set is the cross-repository state. Both are validated.

**Reconciles.** Kumwe-v2-02 "Migration ledger" with Kumwe-v2-07 "Central change-set record", which each
carry a `migration_id` and a `change_set` without saying which file is which.

**For agents.** Phase 2 writes both files. A ledger record whose `change_set` does not match its own
sequence, or that names a legacy package, fails validation.

## D-GOV-3 — Identifier allocation

**Ruling.** Identifiers are sequential per record type per calendar year; the next is the highest existing
sequence in that record's directory plus one. The bootstrap is `NRM-2026-001`. There is no registry file.

**Reconciles.** Kumwe-v2-07, which defines the `NRM-YYYY-NNN` and `KUMWE-CS-YYYY-NNN` forms but not how a
number is chosen.

**For agents.** Read the directory, take the next number, never reuse or renumber one. Under serial
execution a number allocated at Phase 1 start cannot collide.

## D-GOV-4 — Failed verification output

**Ruling.** A failed release verification writes
`docs/architecture/migrations/evidence/<MIG>/RELEASE-VERIFICATION-FAILED.yaml` — the release-attestation
schema with `status: failed` and non-empty `known_gaps`. `RELEASE-ATTESTATION.yaml` exists only with
`status: verified`.

**Reconciles.** Kumwe-v2-08 "External release attestation", whose example shows only `status: verified`,
with the Kumwe-v2-07 rule that a partly green step stays open.

**For agents.** Never write a failed result into `RELEASE-ATTESTATION.yaml`. A failed file blocks Phase 2
until a successor release is verified.

## D-GOV-5 — Branches

**Ruling.** Package repositories release from `main`; `kumwe/app` merges to `master`. Every "main" in the
Version 2 documents is read per repository. App PRs target `master` and are rebase-merged; CHANGELOG entries
cite `(#PR)`.

**Reconciles.** Kumwe-v2-07 "Release protocol" step 2 ("protected `main`") with App's `master` branch and
its rebase-merge changelog rule in `AGENTS.md` section 5.

**For agents.** Target the right branch in each repository. Cite pull requests, not branch commit hashes,
in App's changelog; a rebase rewrites every branch hash.

## D-GOV-6 — Release version

**Ruling.** The Phase 1 agent chooses the release version by writing the newest `## X.Y.Z` heading in the
package CHANGELOG — the release-on-record pattern `kumwe/producer` and `kumwe/conversion` already use. The
handoff records the version policy and cites that heading, not a predicted tag.

**Reconciles.** Kumwe-v2-08 "never guess future … tags, versions" with Kumwe-v2-07 step 3
("release-on-record automation builds from that merged SHA").

**For agents.** Write the heading; do not write a tag, a URL or a hash that does not yet exist.

## D-GOV-7 — Drift scope

**Ruling.** Kumwe-v2-08 rule 10 — stop when current source differs from the baseline — applies to the
extracted symbols, paths and their tests, not to unrelated App changes.

**Reconciles.** That rule with Kumwe-v2-04 "Drift between extraction and adoption", which expects App to
keep moving while a package is in flight.

**For agents.** At Phase 2 start, diff the extracted symbols and tests against the Phase 1 baseline commit.
Port portable changes upstream through a successor release; keep App-specific changes; ignore the rest.

## D-GOV-8 — Integration train

**Ruling.** Under serial execution a train record is still written per Phase 2 PR in
`docs/architecture/migrations/trains/`, degenerate single-PR trains included.

**Reconciles.** Kumwe-v2-04 "Integration train", written for several concurrent App PRs, with the single
serialised lane this programme runs.

**For agents.** Write the train record before declaring an App PR merge-ready, even when it lists one
release and one PR.

## D-GOV-9 — Human authority

**Ruling.** Every package PR, the bootstrap PR and every App PR are merged by the maintainer; agents never
merge, tag, enable auto-merge or publish. The first Packagist submission is a maintainer action.

**Reconciles.** The "human maintainers retain protected-branch merge and release authority" clauses of
Kumwe-v2-04, -07, -08 and -10 with the release-on-record automation, which runs after a human merge.

**For agents.** Prepare, update and describe; then stop. A merge, a tag or a publication you performed is a
protocol violation, not a shortcut.

## D-GOV-10 — Legacy packages

**Ruling.** `kumwe/conversion 0.1.2`, `kumwe/extension-sdk 0.2.4` and `kumwe/producer 0.2.0` are approved
legacy-unmanifested transitional entries in `docs/architecture/governance/legacy-packages.json` until they
adopt Version 2 manifests.

**Reconciles.** Kumwe-v2-10 ("legacy installed packages … may appear only as explicitly marked
legacy-unmanifested transitional entries") and Kumwe-v2-08 "Verified legacy dependency exception" with the
three packages App already installs.

**For agents.** A legacy entry cannot be the `package` of a migration ledger record. Re-pinning one
re-approves it: update the registry in the same pull request. Do not extend the registry to a new package
without maintainer approval.

## D-GOV-11 — Verification session

**Ruling.** The "fresh session" of Kumwe-v2-11 may be an agent session with no prior context; its
attestation is committed into App by the Phase 2 PR at the evidence path.

**Reconciles.** Kumwe-v2-07 step 5 and Kumwe-v2-08 "External release attestation" (an external record) with
the App evidence paths, which need the record in the tree.

**For agents.** The verifying session must not be the Phase 1 session and must not read its chat history.
Phase 2 copies the attestation into `docs/architecture/migrations/evidence/<MIG>/` unchanged.

## D-GOV-12 — Governance identifiers and the roadmap

**Ruling.** Extraction and governance work is non-roadmap work under `NRM-YYYY-NNN`; it goes straight to
`CHANGELOG.md` (roadmap README section 10.1) and never claims a roadmap objective. The ERP-Extensibility
roadmap (sha256 `a202155e…`) is referenced by digest in change-set records; it is not copied into App.

**Reconciles.** Kumwe-v2-07 "Roadmap authority" and "Non-roadmap work" with App's own roadmap governance,
where `findings.json` and `STATUS.md` hold open work and the changelog holds completed work.

**For agents.** Do not add a finding, a STATUS row or a roadmap objective for a migration. Cite the
`NRM-YYYY-NNN` in the changelog entry and the PR; put roadmap references, with `enables`, `verifies` or
`accepts`, only in the change-set record.
