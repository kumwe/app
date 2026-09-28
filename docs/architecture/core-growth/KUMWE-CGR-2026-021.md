---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-021
title: "Authenticated preview of contextual Content authoring sessions"
symbols:
  - Kumwe\App\Studio\Application\Preview\ContentStudioPreviewBindingSource
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/producer
    version: 0.3.0
    symbols_inspected:
      - Kumwe\Producer\Wire\Port\PreviewPortInterface
      - Kumwe\Producer\Render\CompositionRenderer
      - Kumwe\Producer\Render\RenderContext
      - Kumwe\Producer\Schema\StudioDocumentSchemaRegistry
    source_inspected:
      - vendor/kumwe/producer/src/Wire
      - vendor/kumwe/producer/src/Render
    tests_inspected:
      - vendor/kumwe/producer/tests
  - package: kumwe/content-model
    version: 0.2.0
    symbols_inspected:
      - Kumwe\Content\Application\ContentRecord
      - Kumwe\Content\Domain\ContentTypeDefinition
    source_inspected:
      - vendor/kumwe/content-model/src
    tests_inspected:
      - vendor/kumwe/content-model/tests
search_terms:
  - "preview binding"
  - "preview values"
  - "authoring context"
  - "content entry projection"
  - "preview render"
required_capability: "Resolve the authorized Content values an authenticated Studio preview renders for a contextual Content authoring session whose host session names an opaque authoring context rather than a resource."
consumers:
  - src/Kernel/ContainerFactory.php
  - src/Studio/Application/Preview/StudioPreviewHostPort.php
overlap_reviewed: []
decision: approved
decided_by: "Studio integration agent under standing maintainer mandate"
reviewer: "Studio integration agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

The contextual Content editor must preview the item it is authoring through the existing authenticated,
origin-pinned preview port. A contextual session's host session carries an opaque authoring-context key
instead of a Content resource identifier, so the preview binding source must resolve and re-authorize the
exact create or edit target behind that key on every render and project only that item's own values.

## Why existing package APIs are insufficient

`kumwe/producer` owns the preview wire port and the renderer, but binding values are host data: it cannot
know which Content entry an App authoring context names or which disclosure policy applies.
`kumwe/content-model` owns records and definitions, not Studio sessions or authoring contexts.

## Why extending the owning package is inappropriate

The change widens one existing App class's constructor with App's own context authority. The behaviour is
authorization and projection of App state, which both packages' charters exclude.

## Why a new focused package is inappropriate

There is no portable bounded context: the class composes App's context authority with App's Content
projection service.

## App-specific responsibility

Authority: re-resolve the opaque authoring context under the live administrator session, follow a stale
approval generation only to the target the authority already re-authorized, refuse a refused context and a
blank canvas whose type does not exist yet, and project the stored entry's disclosed values.

## Tests proving the boundary

- `tests/Unit/Studio/Application/Preview/ContentStudioPreviewBindingSourceTest.php` refuses an unknown
  context and a blank canvas.
- `tests/Integration/Studio/ContentStudioAuthoringJourneyIntegrationTest.php` renders a saved contextual
  item through the preview port on both engines and proves the foreign-origin, wrong-channel and replayed
  sequence refusals.

## Decision

Approved on 2026-09-24 by the Studio integration agent under the standing maintainer mandate (agent
source-ownership review, not a human approval); the maintainer's merge of the pull request is the human
acceptance record.
