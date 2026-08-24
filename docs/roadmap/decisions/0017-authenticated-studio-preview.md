# ADR 0017 — Authenticated Studio preview is a single-use same-origin presentation

**Status** Accepted as the S-F / AP-6 implementation of decision D16
**Decided by** ADR 0007 and the published Studio preview, host-sequence and security contracts
**Findings** Completes `V2-STU-006`; the embedded browser surface and its qualification remain S-G
**Gate** B integration
**Verified against** `f10c8f1d`

---

## Context

Studio must show an unpublished canonical composition without making that composition public, treating a
browser-provided identity as authority, or creating a second presentation path that can drift from the
published site. The released preview protocol also requires deterministic markers, an origin-pinned
channel, independent monotonic sequences, cancellation, and a rendered result correlated to one exact
draft and request. The administrator CSP otherwise refuses framing and inline executable presentation.

The App already owns authenticated Studio session authority, immutable artifact revisions, Content policy
and projection, site templates, themes, and published output. Preview must compose those authorities; it
must not move any of them into Studio or accept their values from the authoring client.

## Decision

1. **Preview is a port behind the common host fence.** `preview.render` accepts exactly `{payload}` and
   `preview.cancel` accepts exactly `{draftDigest}` through the AP-3 dispatcher. The dispatcher first
   resolves the authenticated resource context and current generation. The payload's request, artifact,
   revision and digest identify an immutable AP-4 draft but confer no authority.
2. **Transport identity is server-derived and replay-protected twice.** Session opening returns the fixed
   same-origin document path plus an HMAC-derived channel and source identifier. Origin, channel, source and
   sequence travel outside the protocol payload. Separate `port` and `document` ledgers accept only their
   next sequence, so POST replay and iframe-navigation replay cannot consume each other's counters or be
   hidden by a shared counter. Origin/Referer evidence is required and compared with the configured origin.
3. **A rendered document is a bounded grant, not a URL.** Rendering creates a 60-second row bound to the
   authenticated actor, scope, browser session, resource context, live generation, transport identity,
   draft identity and request. Cancellation and a later render supersede matching work. The GET endpoint
   re-resolves authority and atomically claims a ready row once; expiration, cancellation, replay,
   cross-context use and stale generation fail closed. A generated theme stylesheet remains bound to that
   claimed row and may be read only through a same-origin authenticated no-store subresource that rechecks
   its live authority and transport coordinates. Both grant and sequence tables are portable,
   prefix-aware and created by repeatable migration `20260824040000_studio_preview_grants`.
4. **Published and unpublished presentation share one service.** `ContentPageRenderService` owns the site
   template, theme and presentation path used by the public handlers and the preview renderer. Preview
   resolves bindings through the existing authorized Content projection and supplies only presented values;
   Content remains authoritative. The registry supports the released structural types and the core-owned
   field block vocabulary. Structural renderers resolve base/default/responsive intent against the request
   viewport and expose only the fixed core layout data-attribute vocabulary with columns bounded from one
   through twelve; malformed values or arbitrary attributes fail before markup. An unknown type renders an
   inert diagnostic instead of executing or being silently dropped.
5. **The route gets an exact CSP delta.** Only `/administrator/studio/preview` permits same-origin frame
   source and ancestors. It removes style attributes and uses SAMEORIGIN/no-referrer hardening; script stays
   self-only. There is no inline script, inline style, eval, remote frame or remote connection allowance.
   Exact validated theme variables arrive through the authenticated same-origin stylesheet under the existing
   `style-src(-elem) 'self'` rule. The common policy builder and a byte comparison test prevent unrelated
   administrator directives drifting.
6. **Observability is accountable but not a false domain audit.** Preview staging changes no authoritative
   Content or Blueprint state, so it records bounded structured security activity rather than a business
   mutation. The authorization gateway retains its normal audit responsibility. Preview records contain
   subject, site, resource kind/fingerprint, request/correlation, closed action/outcome and stable reason;
   they explicitly exclude drafts, digests, HTML, tokens, channel/source identity, sequences and geometry.
7. **Qualification distinguishes code evidence from environment evidence.** PHPUnit replays both preview
   identity and both preview host-sequence vectors and proves the refusal matrix, single-use grants, exact
   wrappers, binding/rendering and policy delta. The Security workflow publishes those proofs as a distinct
   P7-C JUnit artifact. Three-engine migration/replay, the built Studio browser binding and independent
   security/human acceptance remain external Gate B evidence and are not inferred from local SQLite.

## Alternatives rejected

### Render a public preview URL from an unpublished slug

Rejected. A stable address is shareable, enumerable and cacheable in ways the authenticated Studio resource
context is not. It also cannot express cancellation, supersession or one-use delivery.

### Return complete HTML from `preview.render`

Rejected. It bypasses the released rendered payload, expands sensitive draft material through JSON and
does not give the iframe endpoint an independent origin, sequence, live-authority or CSP check.

### Maintain a separate preview template

Rejected. A preview-only template would inevitably diverge from published output. The shared service keeps
one presentation path while allowing preview to suppress the public theme-variable style attribute and emit
the identical validated values through its authenticated same-origin stylesheet.

### Treat the context key or channel identifier as a bearer token

Rejected. Both are browser-visible routing coordinates. Authority comes from the authenticated request,
current session generation and persisted binding; every coordinate is checked, and the completed document
is still claimed atomically once.

### Log full preview envelopes for forensics

Rejected. Draft bytes, digests, grants and high-cardinality marker geometry add disclosure and log-amplification
risk without making an ephemeral presentation an authoritative mutation. Stable refusal codes and bounded
correlation fields are sufficient to investigate the boundary.

## Consequences

- The browser can stage an exact unpublished draft, receive canonical markers, and load one same-origin
  frame without publishing it or granting a reusable preview address.
- Published pages and preview use one site rendering service and exact theme values, while the preview route
  enforces a stricter no-inline-style policy without weakening ordinary administrator responses.
- Themes receive deterministic, viewport-resolved core layout data attributes rather than stored CSS,
  arbitrary attributes, or a parallel preview-only layout interpretation.
- Cancellation, supersession and single claim are persisted and race-safe rather than process-local.
- New core or extension block renderers join through the registry seam; they do not obtain Content authority.
- AP-7/S-G can bind the released `PreviewBinding`, `PreviewClient` and `PreviewHost` to the stable session
  metadata, dispatcher and document endpoint without copying App internals.
- S-F is complete in code, but Gate B remains open until the repository records the stated three-engine,
  built-browser and independent qualification evidence.
