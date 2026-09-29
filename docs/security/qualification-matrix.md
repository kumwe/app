# Security qualification matrix (P7-C)

[`qualification-matrix.json`](qualification-matrix.json) preserves the automated-evidence map used for the
historical `P7-C` review ([roadmap](../roadmap/README.md#phase-7--production-qualification-and-vertical-neutral-proof)).
Its `file::method` references and CI job assignments describe the reviewed revision. The independent
threat-led agent review is recorded separately.

Scanners in [`security.yml`](../../.github/workflows/security.yml) supplement these application authorization
tests. They never substitute for them: gitleaks, Trivy and the dependency audit cannot see whether one tenant
can read another's row.

## Current verification

The application test suites verify security behavior directly. This historical map is retained for review
provenance and is not a merge gate; test names and workflow assignments can evolve without maintaining a
second inventory. See [`development.md`](../development.md) for the current commands and CI lanes.

## Areas

| Area | What it covers |
|---|---|
| Authentication and session handling | Credential checks, cookie posture (`GM-IDN-05`, `GM-IDN-06`), expiry and rotation, revocation on credential change, bearer binding, self-service reach (`GM-IDN-07`) |
| Request-forgery, content-policy, upload, traversal and server-side-request boundaries | Login and form forgery (`GM-IDN-04`), CSP (`GM-SUP-08`), upload type confusion, path and archive traversal, SSRF through every outbound fetcher |
| Query, identifier, pagination and exhaustion inputs | The closed query grammar, uniform identifier refusals, cursor integrity and page bounds, body and work limits |
| Row, field, action, report, export, event and log non-disclosure | Policy precedence on every read shape, field disclosure, action exposure, report scope, export ownership, event payloads and log channels |
| Maker-checker, separation of duty, step-up, human proof and confused-deputy cases | Approval rules, step-up, human-only actions and caller provenance across REST, CLI, MCP and workers |
| Idempotency conflict and operation ownership | Key reuse, concurrent first claims, and keys owned by actor, operation and resource |
| Extension signature, trust rotation, revocation and generation fencing | Signed admission, rotation overlap, emergency and feed revocation, stale-runtime fencing |
| Ambient authority and out-of-process controls | The ambient-authority posture of admitted code. Out-of-process execution is owned by `GM-SUP-05` on the Point 5 track and recorded as out of scope |
| Secret sources, rotation and redaction across every channel | Protected secret files, key rotation, redaction in log, problem, audit (`GM-AUD-08`), replay, CLI and MCP channels |
| Audit tamper evidence and archival | Chain, anchors, database guards, redacted archives and retention |
| Tenant isolation under concurrency and noisy-neighbour load | Site and organization isolation under concurrent writers, and fair turns under backlog |
| Studio preview and media boundaries | The authenticated preview and the media host (`V2-STU-005`, `V2-STU-006`), which the roadmap assigns to `P7-C` |
