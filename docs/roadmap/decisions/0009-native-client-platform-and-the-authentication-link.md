# ADR 0009 — The native client platform is a Version 3 programme, and its sign-in is the authentication link

**Status** Accepted
**Decided by** Product owner
**Verified against** `df715e39c6269c50c6f4c73d6fb32d1570917945`
**Findings** `V3-NC-001`, `V3-NC-002`, `V3-NC-003`, `V3-NC-004`
**Gate** None — a Version 3 seed. Nothing here is a Version 2 deliverable, a Gate A or Gate B criterion, or
evidence toward either gate.

---

## Context

Kumwe intends to ship native clients — Flutter applications for desktop, Android and iOS — when Version 3
opens. Two sibling repositories already carry the preparatory work:
[`kumwe/dart-sdk`](https://github.com/kumwe/dart-sdk) holds a machine-readable, non-authoritative contract
corpus (native discovery, native authorization, client surfaces, problem-details registry, mutation and
collection semantics) audited against this repository, and
[`kumwe/client`](https://github.com/kumwe/client) holds the documentation-first product foundation. Both state
correctly that nothing becomes real until this repository adopts a descendant of those proposals through its
own roadmap. Until this decision, this repository contained no native-client entry at all, so there was
nothing for that adoption to attach to.

### What exists today, verified

- **Machine callers hold pre-issued opaque bearer tokens only.** `docs/rest-api.md` documents the contract;
  `src/Identity/Infrastructure/Authentication/DoctrineAccessTokenVerifier.php` is the single verifier every
  token-bearing surface uses, and every issuance path — the administrator UI, `POST /api/v1/tokens`, the
  `token:create` CLI — requires an already-authorized principal. There is no self-service sign-in for a
  native application, by design.
- **People authenticate on the web with passwords.** `src/Administrator/Http/Handler/AdministratorLoginHandler.php`
  and `src/Portal/Http/Handler/PortalLoginHandler.php` mint the two separate cookie-session boundaries; the
  areas are URL paths, throttled and non-enumerating, with TOTP step-up bound to those sessions.
- **There is no mailer, no email verification and no self-registration.** `docs/getting-started.md` states
  that public registration cannot claim an installation; `src/Identity/Domain/UserStatus.php` declares
  `Pending` as a state that cannot authenticate.
- **The Studio integration (decision D16, ADR 0007) is the accepted cross-repository pattern**: the sibling
  repository owns a versioned contract corpus pinned exactly while draft, this repository owns the host
  adapter and every authoritative service behind it, and contribution surfaces freeze in an additive
  generation at a gate.

## Decision

1. **The native client platform is a stated product objective for Version 3**, recorded beside the platform
   bridges in the roadmap's "Beyond Version 2" statements. It is not Version 2 scope and not a work package
   now. When the Version 3 programme opens, its roadmap adds a **native-client contract/SDK-readiness gate**
   (released, digest-pinned machine contracts; supported native authorization; adopted client-surface
   discovery; cross-repository fixtures) and a **final parity-qualification gate** (native outcome evidence
   for every advertised profile), both closed only by evidence in this repository.
2. **The end-user sign-in for native clients is the authentication link** — the pattern the industry calls a
   magic link: the person chooses the deployment URL and the area (administrator or portal), enters their
   email address, and the emailed single-use link returns them to the requesting client, which redeems the
   link code together with an S256 proof-key verifier that never left it. Unknown addresses arrive as pending
   guests: stewards are notified, an administrator positions the account, and the person is emailed when
   access is granted. Sessions persist through rotating refresh families until logout. A bearer-authenticated
   web-session handoff mints a single-use, short-lived URL so the client can open the website already signed
   in; raw tokens never enter a browser. **Password login on the web stays exactly as it is.** The reviewed
   wire design for all of this is the `kumwe/dart-sdk` contract corpus, which this repository will adopt,
   adapt or supersede through the ordinary contract lifecycle when the Version 3 programme opens.
3. **The division of labour follows the Studio pattern**: `kumwe/dart-sdk` owns the proposal corpus and the
   client-side conformance work, pinned exactly while draft; this repository owns every future endpoint,
   the token model they mint into, the mailer, the guest lifecycle and the adoption evidence. Core gains no
   obligation from the proposals' existence; the ledger entries below are the hook adoption review attaches
   to, not acceptance of any wire byte.

## Alternatives rejected

- **Password login in the native client.** Duplicates credential entry outside the reviewed session
  boundary, contradicts the sibling repositories' standing rule that a client never collects a Kumwe
  password, and adds nothing the link does not provide.
- **Pre-issued tokens as the user story.** They remain the right mechanism for controlled integrations and
  are already shipped; an administrator issuing every native credential by hand is not a sign-in experience.
- **Leaving the native client out of this roadmap until Version 3 opens.** The sibling repositories would
  keep pointing at a ledger with no entry, and contract-shaping decisions (mailer, guest lifecycle, session
  provenance) would be taken piecemeal without a recorded owner.

## Consequences

- Four findings (`V3-NC-001` … `V3-NC-004`) enter the ledger under a new lane N that blocks no Version 2
  gate, covering the authentication-link contract, native discovery, the guest arrival lifecycle and the
  web-session handoff.
- Version 2 work that touches identity, sessions, tokens or email gains a reviewer question — does this
  foreclose the native client? — the same discipline decision D14 established for point of sale.
- A mailer becomes a known Version 3 prerequisite; nothing in Version 2 acquires one.
- `UserStatus::Pending` is recognized as the natural seed of the guest state; its "cannot authenticate"
  semantics stay untouched in Version 2, and the guest carve-out is designed at adoption time.

## Non-goals

- No Version 2 endpoint, schema, mailer, or migration. No change to Gate A or Gate B. No adoption of any
  `kumwe/dart-sdk` proposal byte. No native client code in this repository, ever — clients live downstream.
- No weakening of the web login, the token issuance discipline, the security epoch or the step-up model in
  service of the future flow.
