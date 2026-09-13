# Package adoption and native transition — unfinished checkpoint

This branch preserves the work requested by the maintainer. It is a draft recovery checkpoint, not a completed or merge-ready implementation of steps 1–3.

Base: `fcdcf72202de975e05b25e1500998b089249946a`.
Branch: `codex/package-adoption-native-transition`.

## Implemented locally

The Navigation adoption changes 21 PHP consumer files to the canonical `Kumwe\Navigation` types, including the existing Doctrine repository binding. It removes eight extracted App types and two package-owned unit test files. Host authorization, persistence, transactions and rendering remain in App. `composer.json` requests exact `kumwe/navigation` 0.1.2.

This patch is incomplete: **composer.lock has not been regenerated**, the capability index and governance baseline have not been regenerated, adoption records are not complete, and App PHPUnit/PHPStan/full `composer qa` have not run. Do not merge or deploy this checkpoint.

## Verification already performed

The separate 29-package PHP 8.5.10 consumer installed the original exact archives without development dependencies, passed Composer audit and platform checks, reinstalled the same lock with Composer networking disabled and a read-only cache, loaded 630 runtime types, and executed 34 unchanged package examples. OS network namespace isolation was unavailable. Eight original hosted receipts were independently revalidated. These results do not establish App integration or native runtime completion.

See [the preserved evidence](docs/architecture/migrations/audits/2026-09-13/README.md).

## Outstanding blockers and implementation

1. Five released handoffs require GitHub immutable publication, while their observed releases report `immutable: false`: Access Context 0.1.2, Access Control 0.1.2, Canonical JSON 0.1.1, Contribution 0.1.1 and Localization 0.1.1. The full graph verification is recorded as failed. No requirement was waived and no release attestation was rewritten.
2. Navigation 0.1.2 independently passed release admission, but its published handoff and receipt identify `KUMWE-MIG-2026-035` / `KUMWE-CS-2026-034`. App requires matching numeric identifiers; Content Model also publishes CS-034. That collision needs resolution before Navigation adoption can be declared ready. The published identifiers have not been changed.
3. SDK 0.3.2, its coordinated package train, the remaining six additional adoptions, and their App API/DI changes are still pending.
4. Native provisioning verified all 190 embedded Engine source digests, then failed during scratch build-tool setup (`config.sub`, `config.guess`, and autoconf macro paths). No native runtime was successfully built or admitted in this session. The actual record/report/schema consumers still use the existing PHP implementations.
5. After requirements are resolved, finish the dependency lock, consumer migrations, obsolete-code/test removal, native execution, required records and complete App checks. Keep the branch unmerged; the maintainer reserved final acceptance/closure for a separate session.

## Validation of this checkpoint

Changed PHP files passed syntax checks and `git diff --check`. No full QA success, completed adoption, roadmap completion or production readiness is claimed. This publication preserves unfinished work at the maintainer's explicit request.
