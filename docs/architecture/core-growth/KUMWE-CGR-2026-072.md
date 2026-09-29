---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-072
title: "Exact authored field identities in Content projection and publication"
symbols:
  - Kumwe\App\Studio\Application\Projection\ContentStudioProjector
  - Kumwe\App\Studio\Application\Composition\StudioPublishedCompositionGuard
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/producer
    version: v0.3.0
    symbols_inspected:
      - Kumwe\Producer\Wire\Port\AuthoringPortInterface
      - Kumwe\Producer\Schema\StudioDocumentSchemaRegistry
    source_inspected:
      - vendor/kumwe/producer/CHARTER.md
      - vendor/kumwe/producer/src/Wire/Port/AuthoringPortInterface.php
      - vendor/kumwe/producer/resources/studio-contract/protocol/schemas
    tests_inspected:
      - ../producer-source/tests/Case/WireDispatcherTest.php
  - package: kumwe/content-model
    version: v0.2.0
    symbols_inspected:
      - Kumwe\Content\Domain\FieldDefinition
      - Kumwe\Content\Domain\ContentTypeDefinition
    source_inspected:
      - vendor/kumwe/content-model/CHARTER.md
      - vendor/kumwe/content-model/src/Domain/FieldDefinition.php
      - vendor/kumwe/content-model/src/Domain/ContentTypeDefinition.php
    tests_inspected: []
search_terms:
  - authored field identity
  - content storage key
  - blueprint binding revision
  - exact field path
  - initial presentation
required_capability: "Preserve authored Studio field IDs across an exact site, Content type version and Blueprint binding without widening the Content key grammar or confusing a literal dotted ID with a nested path."
consumers:
  - src/Studio/Application/Authoring/ContentStudioAuthoringService.php
  - src/Studio/Application/Composition/CanonicalStudioPublishedContentRenderer.php
  - src/Studio/Infrastructure/Persistence/DoctrineContentProjectionBindingRepository.php
overlap_reviewed: []
decision: approved
decided_by: "Studio implementation agent under standing maintainer mandate"
reviewer: "Studio implementation agent (package ownership review; parent source review reported in session)"
decided_on: "2026-09-28"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

An authored field such as `summary` or the literal ID `summary.detail` must keep that identity after
saving a reusable type, saving an item, reopening it and rendering its published composition. The host
must retain a separate Content storage key where the two released grammars differ, scoped to the exact
site, Content type and version. An explicit incomplete, foreign or ambiguous map must refuse.

## Why existing package APIs are insufficient

Producer 0.3.0 owns canonical Studio schemas and the seven-operation `AuthoringPortInterface`; its
charter excludes storage and authority. Content-model 0.2.0 owns `FieldDefinition` and
`ContentTypeDefinition`, with lowercase underscore storage keys of at most 63 characters. Neither
package owns App's persisted binding between a Content type version and a Studio Blueprint. Their
released public APIs are used unchanged. Producer's source-tag dispatcher tests were inspected in the
separate `v0.3.0` checkout. The Content-model release archive excludes tests; this review does not claim
to have inspected absent package tests.

## Why extending the owning package is inappropriate

Changing Content's portable key grammar would not preserve historical host bindings or identify which
App site and type version owns a field. Adding App's binding table to Producer would contradict its
no-storage charter. The identity association belongs beside App's existing immutable Blueprint
binding, not in either package's schema or wire implementation.

## Why a new focused package is inappropriate

There is no separate portable bounded context. The mapping relates two installed packages through
App's own site, definition, binding revision and persistence authority. Its only consumers are App's
contextual authoring, projection and publication services.

## App-specific responsibility

`ContentStudioProjector::entry()` and `publishedValues()` accept the optional persisted binding;
`contentModel()` uses the same mapping. The projector verifies site, type, version and complete schema
coverage before disclosing values. `StudioPublishedCompositionGuard` receives the binding repository
through its constructor and validates publication against the same exact IDs. A literal dotted ID
matches one path segment only. Existing records with no authored map retain the established `data_`
projection; explicit maps never fall back on missing keys.

## Tests proving the boundary

- `ContentBlueprintBindingTest` proves bijective IDs, reserved-name refusal and exact coordinates.
- `ContentStudioProjectorTest::testAuthoredFieldIdentitySurvivesEveryProjection` proves model, Entry
  and public values share the exact map, including scope and schema mismatch refusal.
- `StudioPublishedContentRendererTest::testPublicationUsesThePersistedAuthoredFieldIdentity` proves
  the guard and renderer agree and refuses a nested path masquerading as a literal dotted ID.
- `ContentStudioAuthoringJourneyIntegrationTest` uses the production composition root and MariaDB to
  save, reopen and publish authored fields without changing their identity. The complete journey is
  green: 10 tests, 686 assertions. The complete Studio unit suite is green: 458 tests, 3,830 assertions.
  PHPStan at maximum level passes. These are scoped checks, not a full-PR acceptance claim.

## Decision

Approved as App binding persistence and authority under the standing maintainer mandate. The Studio
agent performed the package ownership review. The parent reported source review of `06356426` and
`12824b4e` in this session; this is not a human GitHub approval. The production registration is isolated
in `7829732a`. The parent owns recording the global growth and quality baselines after integration.
Revisit if a released package explicitly assumes the cross-package Content/Studio binding authority;
a different browser release alone does not transfer ownership. STUDIO-PROD-015 remains open for its
remaining packaged and extension obligations.
