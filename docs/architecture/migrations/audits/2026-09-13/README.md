# Preserved investigation and implementation evidence

This directory is a recovery checkpoint for the unfinished package-adoption task. Read [the branch status](../../../../../../TRANSITION-CHECKPOINT.md) before using it.

- `selected-graph.json`, `archive-identities.json` and `release-ancestry.json`: exact inspected coordinates, source/archive identities and observed merged release CI.
- `consumer-result.json` and `consumer-runtime.json`: passing independent 29-package consumer checks; these are not App acceptance results.
- `hosted-receipts.json` and `evidence-digests.json`: observed hosted evidence and checksums.
- `policy-conflicts.json`: exact released handoff requirements, source links and conflicting release observations.
- `RELEASE-VERIFICATION-FAILED.yaml`: failed full-graph verification, preserving passing technical findings and unresolved requirements.
- `individual-admission-review.json`: package-level release admission. Navigation's passing release admission does not resolve its separate App governance identifier collision.
- `navigation-release/`: unchanged Navigation handoff and hosted attestation, with independent review.
- `prepared/`: existing migration inventories, source/API drift handoffs and unintegrated native code drafts. These are working notes, not tested App implementation. PHP drafts are stored as text to avoid loading or counting them as App source.

The native build attempt validated embedded source identity but failed during local build-tool setup. There is no native success claim. Original released evidence is not rewritten. Full App tests, lock regeneration and adoption records remain outstanding.
