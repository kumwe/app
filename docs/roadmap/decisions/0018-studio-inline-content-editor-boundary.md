# ADR 0018 — Studio owns inline content editing; App owns authority and delivery

**Status** Accepted
**Decided by** Product owner
**Findings** `V2-STU-002`, `V2-STU-005`, `V2-STU-006`, `V2-STU-007`
**Gate** B

---

## Context

ADR 0007 fixed Studio as the owner of visual composition and Kumwe App as the authoritative host. The
production editor choice and the boundary for useful HTML, CSS, media, and dynamic data now need the same
precision. A host must not learn an editor library's document shape, while Studio must remain independently
installable in hosts that do not use Kumwe App.

Editor.js is fully open source and produces transient block data suitable for a bounded adapter. It is not a
page model, renderer contract, persistence format, media store, authorization system, or template engine.
Treating its native output as any of those would couple every host and stored document to one browser library.

## Decision

1. Studio remains the standalone **page builder**. Within a prose-capable block, its public editing surface is
   the **Studio Inline Content Editor**. The Studio package owns the pinned Editor.js runtime behind an
   editor-neutral factory. App code, host-port envelopes, extension declarations, canonical fixtures, saved
   Blueprints, and rendered pages never expose or persist Editor.js `OutputData`, tool objects, DOM, or
   configuration.
2. Studio, not App, ships the host-neutral 45-block production catalog, ten starter patterns and the trusted
   progressive behavior for tabs, dialog, notice, popover, countdown, lightbox, navigation, slideshow and
   motion. Each block port
   declares its authoring control and optional governed profile. Profiles may enable
   paragraphs, headings, quotations, dividers, nested lists and checklists, tables, callouts, code presentation,
   Markdown interchange, policy-sanitized HTML, media, LaTeX, Mermaid, charts, drawings, embeds, attachments,
   audio, and carousels only where canonical Studio values and trusted renderers exist. Unknown or disabled
   tools fail closed.
3. HTML is useful content, not executable authority. An exact named policy sanitizes imported or pasted HTML
   into a structured canonical fragment. Renderers contextually escape and revalidate it. Script elements,
   event handlers, `javascript:` URLs, `srcdoc`, document metadata, and authored JavaScript remain forbidden.
4. CSS overrides are admitted only through a versioned scoped-style policy. Studio emits a canonical bounded
   style intent to the host; a Blueprint does not carry a global stylesheet or an executable style string. App
   authorizes and stores any accepted scoped-style artifact separately, then supplies the structured sheet to
   its renderer. The renderer generates a deterministic block-root scope and emits it under App's nonce/hash
   CSP path. Theme recipes and semantic design controls remain the default; scoped CSS is an explicit override.
5. App owns media custody, resource/query authorization, model projection, workflow, persistence, preview,
   publication, Twig delivery, localization, and audit. Studio receives those capabilities only through typed
   ports. A media control receives a provider and persists only an opaque canonical media reference. A resource
   search receives a qualified resource family, optional search text of at most 160 characters, a limit from
   1 through 100 and an opaque host cursor, and returns only authorized stable IDs, labels, types and another
   opaque cursor. Delivery-time resource/query binding resolution receives no SQL, table,
   repository, service name, client URL or executable expression. An author may position dynamic content but
   cannot create a client-side database query or widen the host's field policy.
6. App pins one coordinated Studio release only after that exact Studio candidate publishes the required
   contracts, runtime, notices, fixtures, renderer and conformance vectors as one eight-package release record.
   All eight packages advance to the same exact published coordinate; App then updates its lock, vendored tarballs,
   checksums, notices and corpus together. Core-specific implementations do not move into Studio, and Studio
   functionality is not copied into App. Beta/RC promotion remains disabled until Studio M1-04 and evidence
   acceptance; App does not infer a release coordinate from a branch or roadmap label.
7. The current server-rendered `kumwe-rich-text` field remains the non-Studio fallback until the coordinated
   Studio candidate is pinned and its migration is qualified. It is not a second Studio page model and is not
   evidence for the Studio authoring profile.

## Security and licensing release condition

Editor.js 2.31.6 is Apache-2.0 while App currently declares `GPL-2.0-only`. The technical boundary above does
not decide whether a particular browser bundle is a combined work or whether its distribution is compatible.
The Editor.js-bearing Studio artifact must not be described as cleared for App distribution until the
copyright holders or qualified legal review select and record a compatible route. No automated change may
silently relicense App, add a linking exception, or call separate packaging a legal conclusion.

## Consequences

Studio remains portable and replaceable: another inline editor can implement the same factory without a data
migration, and another host can use the page builder without importing App. App remains authoritative and never
executes author-supplied JavaScript or database expressions. Rich HTML and scoped CSS are available without
weakening page structure, media, workflow, preview, or CSP boundaries. The exact Studio release must land before
App removes its fallback or consumes the new profile.

## Alternatives rejected

- Persist Editor.js JSON in Content or Blueprint documents — rejected because it creates editor lock-in.
- Rebuild the page builder or advanced inline tools in App — rejected because it duplicates Studio and makes
  portability fictional.
- Ban HTML and CSS entirely — rejected because it makes the CMS unnecessarily restrictive.
- Permit authored JavaScript — rejected because typed Studio blocks and trusted renderers can provide the
  required behavior without granting executable authority to content.
