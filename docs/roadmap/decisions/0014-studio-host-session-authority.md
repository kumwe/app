# ADR 0014 — Studio host sessions derive authority from authenticated App context

**Status** Accepted as the S-C / AP-3 implementation of decision D16
**Decided by** ADR 0007 and the published Studio host-session and mode contracts
**Findings** Completes `V2-STU-003`; ADR 0015 later completes `V2-STU-004`; `V2-STU-005` through `V2-STU-007` remain
**Gate** B foundation
**Verified against** `57541e67beaef7d1ccfc26a43d804609d35bf299`

---

## Context

Studio's host request envelope deliberately contains no authenticated actor, grant, tenant, or browser
session identity. It carries an opaque resource-context key and the generation negotiated when the
session opened. The App must resolve both against the current authenticated administrator request. A
client-provided identity or capability list cannot be evidence, and a cached grant decision cannot
survive a policy, membership, capability, or security-epoch change.

The protocol also fixes five flattened authoring modes: `model`, `blueprint`, `content`, `hybrid`, and
`read-only`. Mode authority and the distinction between host-owned Blueprints and App-owned Content are
server policy. Hidden or disabled controls are presentation only.

## Decision

1. **Session opening is an application service.** `StudioHostSessionAuthority` accepts the requested mode,
   resource family, and resource identifier only after the administrator middleware has produced an
   `ExecutionContext`. It permits one exact mode through the existing audited, deny-by-default gateway.
   Blueprint sessions accept Blueprint or read-only mode; Content sessions accept model, content, hybrid,
   or read-only mode. No host identity is decoded from Studio JSON.
2. **An opaque key stores only trusted binding coordinates.** A CSPRNG key addresses an immutable row bound
   to actor, site, organization, workspace, authenticated surface, hashed administrator-session identity,
   mode, resource family, resource identifier, and the open-time generation. The row stores no credential,
   permission snapshot, or policy reason. A later request must reproduce every trusted coordinate.
3. **Permissions are effective server decisions.** The exact mode capability gates the session. The
   permission snapshot is a sorted, closed Studio vocabulary, with Content publishing and media authority
   derived through the existing App capability gateway. `permission.explain` returns only `allowed` and an
   optional safe `MessageReference`; `permission.refresh` returns only permissions and generation.
4. **Generation is one atomic invalidation fence.** The revision digest binds the principal's ordered grants
   and security epoch, site and membership policy generation, authenticated surface and browser session,
   mode, resource family and opaque resource identity, effective Studio permissions, and implemented host
   capabilities. Every operation passes through one dispatcher. A mismatch between the request, stored
   generation, and freshly resolved generation returns category `invalid-request` with diagnostic
   `studio.host/stale-session-generation` before any later port can run.
5. **HTTP delivery is additive and closed.** The administrator composition root exposes a CSRF-protected
   session-open route and the normative `{port}/{operation}` route. Request envelopes validate against the
   exact vendored schema before dispatch. The AP-3 dispatcher implements only the two permission operations;
   live requests for later ports receive a canonical incompatible response, while stale requests still hit
   the shared generation fence first.
6. **Upgrade state is replay-safe.** Migration `20260824020000_studio_host_sessions` creates the opaque
   binding store, reconciles the five core mode capability definitions and administrator grants, records
   their normal ownership entries, and advances affected users' security epochs exactly once.

## Alternatives rejected

### Accept actor or permissions in the Studio envelope

Rejected. The browser is not the identity authority. Closed schema validation refuses those members before
dispatch, and the transport reads only the middleware-established `ExecutionContext`.

### Cache permissions in the host-session row

Rejected. A durable snapshot would continue authorizing after grant, membership, or epoch change. The row
retains the generation that was disclosed, while every call recomputes current authority.

### Give each host port its own generation check

Rejected. Later phases could omit or reorder the check. One dispatcher owns envelope, protocol, trusted
scope, and stale-generation validation for all 24 currently registered operations.

### Implement artifact or model writes in this package

Rejected. S-C establishes authority and invalidation only. Artifact concurrency, idempotency, audit, and
storage remain S-D; AP-3 neither duplicates Studio's command engine nor introduces a write session.

## Consequences

- A stolen or guessed resource-context key is neither a bearer credential nor portable to another actor,
  browser session, site, organization, workspace, or surface.
- Any grant, capability, security-epoch, membership-policy, mode, effective-permission, or host-capability
  change invalidates every later call under the old generation with one canonical diagnostic.
- The five permission/envelope vector IDs used by AP-3 come directly from the vendored host corpus, and the
  same test enumerates all 24 operations from the vendored operation registry to prove the shared stale fence.
- S-D added artifact/recovery ports under ADR 0015 without weakening or duplicating this trusted authority
  boundary; S-E through S-G retain the same obligation.
