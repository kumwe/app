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

1. Studio's public name is **Studio Inline Content Editor**. The Studio package owns the pinned Editor.js
   runtime behind an editor-neutral factory. App code, host-port envelopes, extension declarations, canonical
   fixtures, saved Blueprints, and rendered pages never expose or persist Editor.js `OutputData`, tool objects,
   DOM, or configuration.
2. Each Studio block port declares its authoring control and optional governed profile. Profiles may enable
   paragraphs, headings, quotations, dividers, nested lists and checklists, tables, callouts, code presentation,
   Markdown interchange, policy-sanitized HTML, media, LaTeX, Mermaid, charts, drawings, embeds, attachments,
   audio, and carousels only where canonical Studio values and trusted renderers exist. Unknown or disabled
   tools fail closed.
3. HTML is useful content, not executable authority. An exact named policy sanitizes imported or pasted HTML
   into a structured canonical fragment. Renderers contextually escape and revalidate it. Script elements,
   event handlers, `javascript:` URLs, `srcdoc`, document metadata, and authored JavaScript remain forbidden.
4. CSS overrides are admitted only through a versioned scoped-style policy. Studio stores canonical bounded
   selectors and declarations, not a global stylesheet. The trusted renderer generates a deterministic
   block-root scope and emits it under App's nonce/hash CSP path. Theme recipes and semantic design controls
   remain the default; scoped CSS is an explicit override.
5. App owns media custody, resource/query authorization, model projection, workflow, persistence, preview,
   publication, Twig delivery, localization, and audit. Studio receives those capabilities only through its
   typed ports. Media references remain opaque and dynamic resource/query bindings remain host-authoritative;
   an author may position them but cannot turn them into a client-side database query.
6. App pins one coordinated Studio release only after that exact Studio candidate publishes the required
   contracts, runtime, notices, fixtures, and conformance vectors. Core-specific implementations do not move
   into Studio, and Studio functionality is not copied into App.
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
migration. App remains authoritative and never executes author-supplied JavaScript or database expressions.
Rich HTML and scoped CSS are available without weakening the page structure, media, workflow, preview, or CSP
boundaries. The exact Studio release must land before App removes its fallback or consumes the new profile.

## Alternatives rejected

- Persist Editor.js JSON in Content or Blueprint documents — rejected because it creates editor lock-in.
- Rebuild the page builder or advanced inline tools in App — rejected because it duplicates Studio and makes
  portability fictional.
- Ban HTML and CSS entirely — rejected because it makes the CMS unnecessarily restrictive.
- Permit authored JavaScript — rejected because typed Studio blocks and trusted renderers can provide the
  required behavior without granting executable authority to content.
