# P7-C independent threat-led source review — 2026-09-28

Reviewer: Codex agent owning `agent/v2-browser`, independently reviewing parent-authored recovery,
authorization, Studio, extension-trust, audit and release code in the `kumwe-app` checkout. This agent's
Hebrew assertion, portal denial representation and appearance changes are excluded from independent
qualification. This record is an agent review, not human approval, a P7-I verdict, a CI waiver, or a review
of a published final artifact.

## Reviewed source and limits

The published source review target is `aa0cfbe803e41d836a0e4b67b2f42120dfefc37a`. This is a source
checkpoint, not a claim that a final release artifact has been reviewed or qualified.

The review began at `57839828314d43d728c7f4a1f4db17fa60bdf24f` with the parent's two uncommitted
credential-recovery files. Those exact bytes subsequently landed in `cdd0d826`. The release-boundary
follow-up inspected `8e63a248` and `0214023750cd413258b2408b524da072beb8228b`, followed by the delta to
the published target. The hashes below were verified against that target's Git objects, independently
of worktree files. No main-checkout source, tests or records were edited by this reviewer.

| Reviewed file | SHA-256 |
| --- | --- |
| `src/Identity/Application/Administration/AccessControlService.php` | `60caecd844d3efc461a6493e9834eee0c44afb25133514d44a37a9b12c53845f` |
| `tests/Integration/Identity/CredentialLifecycleIntegrationTest.php` | `c5668c51f28f3d094866f789bedcfbeb463f29279efca618264d72140e9c5921` |
| `src/Audit/Infrastructure/Persistence/AuditAppendOnlyGuard.php` | `920b44875c5b819493702c09a0000d57df7991972e65256e7031de0d9954be32` |
| `src/Audit/Infrastructure/Persistence/DoctrineAuditTrailVerifier.php` | `cd73b4b8d2d220992ce39a586fd0f01cd19e7ea2407dde1764b29b7d128cecc2` |
| `tests/Functional/Security/CredentialTakeoverCeilingTest.php` | `8585d0f9e621f394321afea1cfb99d1bc7788591a5df9443e2fc7c26081fce43` |
| `docs/security/qualification-matrix.json` | `e8ab190b6a0a4fa7e7e5bd8914b062575a81b0d1f25d7d7e7355518fa1157cac` |

The review combines source tracing, the matrix's structural checks, focused existing tests, and two
isolated database probes. It does not claim execution of all 239 matrix references or the complete
cross-engine/browser/release workflows. The probes used PHP 8.5.9, native 1.0.3 and MariaDB 11.8.8;
MySQL and PostgreSQL behavior discussed below is source analysis, not an executed result here.

### Published checkpoint delta

All six files above are byte-identical between `02140237` and `aa0cfbe8`. Both findings below therefore
remain present in the published target; the previous recovery and audit observations are not findings
against an abandoned local patch. The focused test and probe results below retain their original
execution scope and are not represented as a new full run at this checkpoint.

The additional source review traced the release/security workflow changes, source SBOM and license
policy generation, the expanded release manifest artifact set, and the installer helper extraction.
The SBOM generator binds native-source archive and embedded-engine identities to the recorded pins,
checks embedded license-file hashes, and rejects missing or disallowed license declarations. Release
manifests and signatures include the added SBOM, policy and evaluation artifacts. The installer helper
extraction preserves the prior behavior. No additional source-level finding was identified in this
narrow delta. This is not validation of a generated SBOM, legal compliance, an actual artifact,
attestation, workflow execution or release receipt.

Untracked stale generated assets were excluded from the committed-source review. Later agent changes
require an explicitly identified delta review; they are not qualified by this record. P7-I remains a
separate review of the assigned final candidate and its artifact coordinates.

## Findings

### P7C-20260928-01 — High: a runtime DML account can erase audit evidence while verification reports intact

**Precondition:** control of SQL executed as the documented runtime database principal, or equivalent
DML credentials. This is not a demonstrated unauthenticated HTTP attack. It does not require `SUPER`,
`TRIGGER`, `DROP`, a disabled trigger, or the managed-database privilege exception.

`AuditAppendOnlyGuard::createGuards()` trusts `@kumwe_audit_prune = 1` on MySQL/MariaDB
(`src/Audit/Infrastructure/Persistence/AuditAppendOnlyGuard.php:135`) and the caller-settable
`kumwe_audit_prune.enabled` setting on PostgreSQL (`:149`). The retention service sets that flag only
after its own authorization, export and anchor steps, but the trigger does not authenticate those steps.
A SQL caller can set the same flag directly. The guidance at `docs/operations/monitoring.md:473` gives
the runtime `SELECT, INSERT, UPDATE, DELETE` and relies on withholding DDL privileges.

The first isolated probe installed the actual App guards and granted a separate account only DML on
the probe's audit table. That account could not drop the trigger. The observed result was:

```json
{
  "initial_enforcement": "active",
  "trigger_drop_refused": true,
  "ordinary_delete_refused": true,
  "delete_after_caller_sets_retention_flag": 1,
  "enforcement_after_delete": "active",
  "remaining_rows": 0
}
```

The second probe used App migrations, the real recorder, anchor writer and verifier. It first recorded
two events and anchored them, producing a valid three-event trail with one anchor. A separate account
held DML only on `audit_events` and `audit_anchors`, and was again refused `DROP TRIGGER`. It ran the
following operations against that disposable database, without invoking the retention service:

```sql
SET @kumwe_audit_prune = 1;
DELETE FROM p7c_audit_events;
DELETE FROM p7c_audit_anchors;
SET @kumwe_audit_prune = NULL;
```

The real verifier then returned:

```json
{
  "before": {"intact": true, "events": 3, "anchors": 1},
  "trigger_drop_refused": true,
  "deleted_events": 3,
  "deleted_anchors": 1,
  "after": {"intact": true, "enforcement": "active", "events": 0, "anchors": 0, "head": 0}
}
```

This follows directly from `DoctrineAuditTrailVerifier::verify()` deriving the head and ledger from
the same writable database (`:95`), and `walk()` returning an intact report when no rows remain
(`:310`). `VerifyAuditTrailCommand::execute()` maps an intact, active report to exit 0 (`:115`);
the probe exercised the verifier, not an authenticated console invocation. The existing console tests
separately exercise that exit mapping.

The existing regression `AuditTamperEvidenceTest::testTheDatabaseRefusesUpdatesAndUnguardedDeletes`
checks a delete without the flag. `AuditTrailRuntimeIntegrationTest::testTheGuardedRetentionWindowIsTheOnlyPathThatMayDelete`
opens the public helper and restores its deleted fixture row. Individual mutation/deletion and anchor
tamper tests retain another piece of evidence to detect the change; they do not cover simultaneous
trail-and-ledger erasure by a DML-only principal. Those existing tests can pass alongside this finding.

**Impact:** the prevention boundary is narrower than the current account guidance implies, and the
`GM-AUD-02` residual's statement that tamper evidence holds unconditionally is false against this
threat. Local hash chains and anchors do not provide an external trusted checkpoint. This finding
remains open; assigning a conditional owner or retaining a clean subset of tests does not resolve it.

**Remediation and acceptance:** separate normal recording from retention authority at the database
boundary. The normal application principal must not be able to delete the trail or rewrite/delete the
anchor ledger; a session variable alone must not grant retention authority. Use a separately privileged,
bounded retention operation whose authorization and archived range cannot be supplied by the ordinary
writer. Add independently retained append-only checkpoints/archive manifests and detect disappearance
or rollback against them. Add adversarial MariaDB, MySQL and PostgreSQL regressions using a real
DML-only principal: direct flag setting cannot authorize deletion, full evidence erasure cannot produce
a clean verdict, and legitimate archived retention still works. Update the matrix and operator claims
with the actual threat boundary. Platform agents own the code/test correction; deployment operators
own installation of the required role and external-custody controls.

### P7C-20260928-02 — Medium: the recovery qualification contract still asserts the superseded refusal

At published `aa0cfbe8`, `tests/Functional/Security/CredentialTakeoverCeilingTest.php:191` still declares
`testBreakGlassRecoveryCurrentlyStopsAtTheCeilingOfAnAccountHoldingGrants`. It expects exit 1 and
an authorization error for recovery of a grant-holding account (`:209–224`). The reviewed exception
intentionally makes that authorized host recovery succeed. `docs/security/qualification-matrix.json:140`
still references the old test and says the exemption awaits a maintainer decision.

This is a source-confirmed contradiction, not an observed full-CI result. The parent's reported
8 tests / 72 assertions do not cover that incompatible functional expectation. The matrix structure
test passes because the obsolete method still exists and is scheduled in a valid job; it cannot prove
that the described security contract matches runtime behavior.

**Required correction:** update that functional scenario to the authorized host-recovery contract,
retain the stronger-account ceiling checks for ordinary principals and the machine-surface refusals,
and update the matrix reference and claim together. Run the functional test and applicable CI lanes.
Do not skip the test or reinterpret the matrix's structural pass as behavioral qualification.

## Credential-recovery boundary assessment

No additional provenance bypass was found in the inspected recovery path:

- Both password reset and second-factor revocation call `assertHumanStepUp()` and `authorize()` before
  reaching the private delegation helper (`AccessControlService.php:463,534`). The exception is an exact
  enum comparison with `SystemIdentity::CredentialRecovery` (`:1386`), not an actor-id string, scope,
  parameter, grant or generic `isSystem()` check. Its only callers are those two operations.
- `DenyByDefaultAuthorizationGateway::evaluate()` first checks object-identity provenance (`:250`).
  The core installation user policy names only the recovery identity among system actors
  (`CoreExtensionContributions.php:648`). A forged issuer fails provenance; an authentic worker,
  bootstrap, scheduler or other system actor fails the policy. The new integration test explicitly
  checks forged recovery and a real worker for password reset; the other-system conclusion is source
  analysis of the closed policy, not a claim that each identity was newly integration-tested.
- `ExecutionContext` in locked `kumwe/access-context` enforces one principal or system actor, system
  strength and background surface. Human contexts have no system actor. A bearer principal without
  consumed human step-up proof is refused before credential mutation. A stepped-up ordinary manager
  still traverses `userAuthorityGrants()` and the delegation ceiling, including membership grants.
- `IdentityRecoveryMachineEquivalenceIntegrationTest::testStepUpGatedRecoveryActsAreRefusedOnEveryMachineSurface`
  retains the REST/MCP absence and CLI bearer refusal contract. The new positive integration case
  exercises all three host actions, changed-password authentication, token invalidation and three
  recovery-attributed audit events. Existing lifecycle tests cover real credential/session revocation;
  the new case alone does not seed and prove every type of session and second factor.
- `bootstrap/console.php` selects `createRecovery()` for `user:recover-credentials`.
  `ContainerFactory::createRecovery()` disables extension runtime loading, while console registration
  injects a private recovery principal into that command. Its action dispatch admits only password
  reset, second-factor revocation and session termination. The patch does not broaden the system
  policy or introduce a role/grant mutation path. Host/process compromise is outside the provenance
  object boundary; admitted in-process PHP is explicitly trusted code, not sandboxed code.

## Other inspected production boundaries

| Boundary and threat | Source-grounded observation | Evidence limit / follow-up |
| --- | --- | --- |
| Authentication/session substitution | Administrator and portal middleware issue contexts from resolved sessions and recheck surface access. `DoctrineAccessTokenVerifier` binds audience, purpose, site, active identity and security epoch; bearer middleware rejects ambiguous credentials/site selection. | Preserve the matrix's cookie, expiry, rotation and machine-adapter CI cases. No new production credential was exercised. |
| Forgery, content policy and input exhaustion | Login/form CSRF coverage is mapped separately; `BodyLimitMiddleware` counts streamed bytes even without a trustworthy Content-Length. `SecurityHeaders` gives preview only the same-origin framing delta, with no inline script/style allowance. | No blanket XSS or CSRF absence claim. Browser and upload/traversal regressions remain required. |
| Query and row/field disclosure | `DoctrineBusinessRecordQueryCompiler` verifies access-plan resource identity and includes policy predicates before paging; related/revision paths carry policy and typed bound parameters. | The matrix's row, field, action, report, export and secret-channel tests are distinct requirements, not interchangeable evidence. |
| Idempotency and confused deputy | `PersistentIdempotencyMiddleware` binds reservation/replay to subject, operation, request digest and authorization fingerprint; the request's current authority is checked before ledger use. | Preserve concurrency, changed-credential and wrong-resource refusal tests. Local review did not replay the entire concurrency suite. |
| Studio session/preview substitution | `StudioHostSessionAuthority` binds actor, site, organization, workspace, surface and session/credential identity, and rederives authority generation. Preview delivery checks both current and stored generation, transport origin/channel/source/sequence, and single-use/expiry grant state. | Reviewed host/security paths, not this reviewer's CSS or the final Studio candidate. Existing persistence and browser proof remains necessary. |
| Studio external-media SSRF | `StudioExternalMediaFetcher` validates URL policy, all DNS answers, a pinned-address transport, every redirect, timeout/byte bounds, encoding and declared-versus-detected media type. Refusals use fixed diagnostics. | `StudioExternalUrlPolicy` supplies lexical/numeric-address exclusions. No outbound attack was sent by this review. |
| Extension admission/revocation | Production configuration rejects unsigned admission and conformance-off. `TrustStore` checks usable namespace-bound keys, signatures, installed artifact/tree identity and generation under the trust-generation lock; stale runtime/publication and indeterminate trust fail closed. | Signed admission does not sandbox admitted PHP. Keep rotation/revocation/concurrent-install and cross-replica CI evidence. |
| Ambient authority | `RestrictedExtensionContainer` and `ContainerFactory` explicitly acknowledge full process authority for admitted PHP. Recovery composition avoids loading it. | The matrix's `GM-SUP-05` out-of-process gap is still a declared gap in this source snapshot, not an isolation proof or a waiver of Point 5 delivery. |
| Secrets/audit/logs | The recorder redacts credential-shaped metadata before digesting and inserting; protected secret-source and channel-specific redaction tests are separately mapped. | Redaction is not tamper resistance. Finding 01 limits the audit integrity claim. |
| Tenant/noisy-neighbour controls | The matrix names separate resource-ownership, concurrent-site writers, scoped-lock and fair-queue tests; the deny-by-default gateway checks resource ownership before principal scope. | Those runtime concurrency and capacity checks were not rerun as part of this source review. |
| Release authority and artifact substitution | The follow-up at `02140237` requires the requested tag, checkout and workflow SHA to agree, master ancestry, a merged PR and successful exact-commit push CI. Candidate receipts bind four lanes to manifest digest, run and attempt; publication depends on those lanes and promotes qualified digests without rebuilding. | This supersedes the earlier independently rebuilt acceptance artifacts seen at review start. Only source wiring was inspected: no release, receipt, artifact signature or maintainer acceptance is claimed as observed. P7-I candidate/digest review remains separate. |

## GM-AUD-02 conditional deployment record

The following ownership and controls are proposed for the parent to record; this document does not
silently change `findings.json` or accept the residual on behalf of an operator.

| Field | Concrete record |
| --- | --- |
| Platform owner | Kumwe platform agents: correct Finding 01, retain tests, maintain verification and operator documentation, and update the security matrix. Replace `UNASSIGNED` with this accountable group when the parent records the disposition. |
| Deployment owner | The deployment operator owns the database privilege contract, separate runtime/migration/retention credentials, independent checkpoint/archive custody, monitoring and incident response. Record the actual deployment owner in deployment evidence. |
| Conditional case | The server refuses the exact trigger-creation privilege/capability. Migration accepts only the recognized refusal; other DDL errors propagate. This condition does not explain or resolve the installed-trigger bypass above. |
| Fresh state | `AuditAppendOnlyGuard::state()` queries the catalogue on every verification. It does not cache migration success. The implementation checks trigger existence, not complete trigger semantics or PostgreSQL enabled state; maintenance validation must not equate a matching name with an exercised prevention boundary. |
| CLI detection | `VerifyAuditTrailCommand` returns 2 for an intact trail with guards absent, 1 for divergence/failure, and 0 only for intact plus reported active. Alert or block deployment on 1; treat 2 as an explicit unresolved control condition, not green qualification. |
| Scheduled detection | `VerifyAuditTrailHandler` fails on divergence but deliberately does not fail for absent guards. Therefore the nightly job alone does not detect or alert on the managed-database enforcement condition. Monitor the CLI enforcement result separately, after migration/restore/privilege changes and periodically. |
| Anchoring | `DoctrineAuditAnchorWriter` seals settled ranges; the verifier checks chain, range counts and digests. Those checks detect partial alteration while evidence survives. They do not detect the demonstrated simultaneous loss of the database trail and ledger. |
| Archive custody | `DoctrineAuditTrailExporter` writes redacted checksummed archives; `FilesystemAuditArchiveStorage` uses confined names, exclusive temporary creation, 0600 permissions and atomic publication. `DoctrineAuditRetentionService` exports and records an anchored prune before deleting. Default storage is local; off-host immutable retention is an operator action, not a demonstrated automatic control. Preserve trusted manifests/checkpoints outside the runtime writer's authority and verify continuity against them. |
| Remediation | Where supported, supply the trigger privileges and rerun the repeatable migration, then verify fresh state and actual DML behavior with the runtime role. Separately implement Finding 01's privilege split and external checkpoint detection. Preserve evidence and investigate any divergence or unexplained head regression; do not repair by recreating a clean ledger over missing evidence. |
| Review point | Recheck at the Beta 1 candidate before publication and during the next quarterly review, 2026 Q4; also after database-provider, role, restore, retention or archive-custody changes. The candidate commit/artifact coordinates must be recorded when they exist. |

The existing `GM-AUD-02` phrase “tamper evidence ... holds unconditionally” must be narrowed. Retained,
independently protected evidence and the threat model are conditions, not implied deployment facts.

## Checks actually performed

- `SecurityQualificationMatrixTest`: 4 tests, 2,251 assertions passed. It checked 12 areas, 44 sub-areas
  and references to 239 evidence entries structurally; this is not execution of those referenced tests.
- `AuditConsoleCommandTest`, `AuditTamperEvidenceTest`, and `ApplicationAuthorizationTest`: 46 tests,
  214 assertions passed. The command used the deprecated PHPUnit `--do-not-cache-result` option,
  producing one runner deprecation; it did not produce an application/test assertion failure.
- Two isolated MariaDB probes against the actual reviewed classes reproduced Finding 01. They used
  disposable schemas and a separate restricted SQL principal, not application production data.
  The full-trail probe used the existing test authorization stub solely to invoke the real verifier;
  it is not evidence that a forged principal passes the production authorization gateway.
- The parent's 8-test/72-assertion recovery result was reported during review and is not counted as an
  independently executed result here. Full CI, all required engines, browser qualification and exact
  release-artifact checks remain required. Neither finding is waived by these focused passes.

P7-C has an independently recorded review, with an open high-severity finding. This record does not
justify marking security qualification or final release acceptance complete.

## Implementation follow-up — Finding 02

The coordinating implementation agent corrected Finding 02 in PR #152 after this review. The functional
scenario is now `CredentialTakeoverCeilingTest::testHostRecoveryRestoresAnAccountHoldingGrants`: all
three authorized host recovery actions succeed for a grant-holding account, the replacement password
authenticates and the previous password does not. Ordinary administrator and organization-membership
delegation-ceiling refusals remain in the same suite. The security matrix now names this behavior under
ADR 0023 and correctly describes the ordinary actor test as a stepped application-service call.

The three functional scenarios and four security-matrix checks passed together on MariaDB: 7 tests,
2,272 assertions. This is an implementation follow-up, not an independent re-review, full CI result or
waiver of Finding 01. The original source finding above remains an accurate record of the reviewed head.
