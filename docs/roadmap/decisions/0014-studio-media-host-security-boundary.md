# ADR 0014 — Studio media stays App-owned behind scoped grants and a pinned external fetcher

**Status** Proposed implementation; acceptance awaits the ordinary CI, security and deployed-artifact lanes
**Decided by** ADR 0007's fixed Studio/App division of labour and the S-E acceptance contract
**Findings** `V2-STU-005`
**Gate** B implementation candidate

---

## Context

The vendored Studio protocol defines seven media operations and a canonical upload-session state machine.
Kumwe already has a media module with authorization, site ownership, audited acceptance, byte detection and
filesystem integrity, but it previously exposed no Studio host adapter. Treating the Studio request as a
normal multipart upload would bypass the declared state machine and carry large bytes through the JSON host
port. Building another store would duplicate App policy and custody.

External imports add a separate privileged boundary. Studio's URL policy is deliberately lexical; it cannot
prove what DNS will answer, where a transport connects, whether a redirect changes authority, or whether the
response bytes match their declaration. A preflight lookup followed by a normal URL client would remain
vulnerable to check/use resolution changes and automatic redirects.

## Decision

1. **The existing App Media module remains authoritative.** Studio list/get projections use authorized App
   reads. Completion and external import call `MediaService::upload()`, retaining its authorization, storage
   verification, audit and compensation behavior. The Studio adapter owns no field, publication or media
   policy and imports no BusinessRecord runtime.
2. **JSON authorization and binary custody are separate.** `authorize-upload` persists a scoped immutable
   upload snapshot and returns a short-lived HTTPS `PUT` capability. Transfer requires fresh authenticated
   App identity plus AP-3 live-session/permission re-resolution, opaque upload ID, token digest,
   resource-context key, session generation, declared type, expiry and exact byte count. Tokens and bytes are
   never persisted together or returned by host errors.
   The token is derived from the random upload identity and every trusted scope coordinate with a dedicated
   HKDF-isolated key; only its digest enters the upload row. Authorize idempotency stores a token-free grant
   projection and re-derives the capability only after a scoped row and digest check, so the live secret is
   absent from both persistence paths without weakening exact replay.
3. **The canonical state machine is durable and optimistic.** Authorized transfer advances to verifying;
   completion claims the persisted version before App admission and advances to complete with the accepted
   identity. Cancellation applies only to active states. Every lookup includes actor, site, resource context
   and generation. Supplied Studio idempotency keys use the existing durable ledger inside the shared
   write discipline, excluding request/trace correlation from the intent digest. Uniqueness arbitration runs
   before the mutation transaction so a caught PostgreSQL uniqueness conflict cannot poison it; the owned
   mutation and ledger completion then commit together, and a failed transaction releases its claim only
   after rollback.
   Authorize, transfer, completion, cancellation and external import also append platform `AuditRecorder`
   events in that transaction. Metadata is limited to policy types/counts/states, accepted asset identities
   and digests of context coordinates; it never retains a URL, filename, path, address or capability.
4. **External URLs are lexical-first and pinned per hop.** The PHP policy mirrors Studio's special-name,
   credential, scheme, IPv4/IPv6 and alternate numeric exclusions. Every DNS answer must be public. The TLS
   socket connects to a classified answer while verifying the original hostname; automatic redirects are
   impossible. Each redirect repeats policy, resolution and pinning.
5. **Response acceptance is bounded and byte-based.** Whole-operation time, redirect, header and decoded-body
   budgets fail closed. Ambiguous framing and content encoding are refused. Declared type, fileinfo detection
   and explicit magic bytes must agree before the App Media module sees the private payload.
6. **Portable vectors are executable PHP inputs.** Tests read all 11 media policy/lifecycle vectors and all
   10 media host vectors from the vendored JSON corpus. Host vectors cross the exact nested wrappers emitted
   by Studio's HTTP adapter rather than a PHP-only approximation.
7. **Phase 7 evidence is registered before it is claimed.** The Studio media security report inventories
   lexical SSRF, DNS-pinning/rebinding, redirect and response-verification controls in the programme's
   verification-entry shape. It stays explicitly not-run and candidate-free until the central P7-C runner
   executes those entries on the immutable build-once release candidate and includes them in the signed
   qualification manifest.

## Alternatives rejected

### Send bytes through the JSON host route

Rejected. It defeats the bounded direct-transfer contract, forces large bodies through envelope decoding and
makes cancellation, expiry and custody ambiguous.

### Let Studio write directly into App media storage

Rejected. Studio is a protocol client, not an App authorization, policy, audit or storage owner. Direct access
would create a second admission path and undermine site ownership.

### Validate DNS and then call a normal URL client

Rejected. A client that resolves again can connect somewhere different, and automatic redirect handling can
leave the validated policy. The connection itself must use the classified address with hostname-bound TLS.

### Trust `Content-Type` or fileinfo alone

Rejected. Declarations are attacker-controlled and detectors can be confused by polyglot or truncated input.
The closed supported set requires declaration, detection and explicit signature agreement.

## Consequences

- Studio obtains the complete media contract without acquiring authoritative App concerns.
- Grant rows and staging objects require expiry cleanup as later automation maintenance; expired grants are
  unusable immediately even before that retention sweep runs.
- Development installations without TLS cannot exercise direct grants at their plain HTTP origin; deployed
  and qualification environments must use the same HTTPS ingress the contract publishes.
- `V2-STU-005` remains in progress until immutable CI/security/deployment evidence proves this candidate across
  the supported database and artifact lanes.
