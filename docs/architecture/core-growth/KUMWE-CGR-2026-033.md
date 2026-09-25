---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-033
title: "Machine document of a Content-model Blueprint composition"
symbols:
  - Kumwe\App\Studio\Application\Composition\StudioContentComposition
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/producer
    version: v0.3.0
    symbols_inspected:
      - Kumwe\Producer\Canonical\CanonicalJson
      - Kumwe\Producer\Schema\StudioDocumentSchemaRegistry
      - Kumwe\Producer\Wire\HostResult
    source_inspected:
      - vendor/kumwe/producer/docs/host-agreement.md
      - vendor/kumwe/producer/docs/public-api.md
      - vendor/kumwe/producer/src/Canonical
    tests_inspected:
      - vendor/kumwe/producer/tests
  - package: kumwe/content-model
    version: v0.2.0
    symbols_inspected:
      - Kumwe\Content\Domain\ContentTypeDefinition
      - Kumwe\Content\Application\ContentModelRepository
    source_inspected:
      - vendor/kumwe/content-model/src/Domain
      - vendor/kumwe/content-model/CHARTER.md
    tests_inspected:
      - vendor/kumwe/content-model/tests
search_terms:
  - composition toArray
  - blueprint machine document
  - content blueprint binding projection
  - studio artifact json
required_capability: "Let REST, CLI and MCP callers read and provision the Blueprint composition of one Content type version and receive the one document the administrator composition screen is built from: the authorized model, the host binding and the exact Blueprint head with its canonical document and locked dependencies."
consumers:
  - src/Delivery/Http/Api/Studio/StudioCompositionApiHandler.php
  - src/Delivery/Console/Command/StudioCompositionCommand.php
  - src/Infrastructure/Mcp/KumweMcpHandlers.php
overlap_reviewed: []
decision: approved
decided_by: "Browser-to-machine parity implementation agent under standing maintainer mandate"
reviewer: "Browser-to-machine parity implementation agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

A machine actor must be able to read, and provision, the Blueprint composition of one exact Content type
version with the same authority and outcome as the administrator composition screen: `content.read` and
`studio.mode.blueprint`, the service's model authorization, the empty schema-valid draft admitted against the
App's renderer set, one `studio.composition.provision` audit event, and the screen's refusal when the Blueprint
is locked to another published theme. All three surfaces must answer one document, so an agent never has to
reconcile three shapes of the same composition.

## Why existing package APIs are insufficient

`kumwe/producer` implements the portable Studio wire contract, canonical JSON and the vendored document
schemas; it does not own persistence, host bindings or revisions (its charter lists them as non-responsibilities).
`kumwe/content-model` owns Content type definitions but knows nothing of Studio Blueprints or their binding to
a type version. The composition itself — the authorized model projection, the `ContentBlueprintBinding` and the
admitted `StoredStudioArtifact` — is already App state returned by the App-owned
`StudioContentCompositionService` (KUMWE-CGR-2026-001).

## Why extending the owning package is inappropriate

The growth is one projection method on an existing App value: `StudioContentComposition::toArray()` names the
binding coordinates and copies the stored artifact's decoded canonical document and dependency list. Moving it
into `kumwe/producer` would make the portable wire package depend on App persistence values it deliberately
does not know.

## Why a new focused package is inappropriate

There is no portable bounded context: the method only serializes an App value for App delivery adapters.
`StudioContentCompositionService::RENDERERS` names the one renderer set the screen already passed, so every
surface provisions against the same renderers.

## App-specific responsibility

This is delivery composition. The REST handler, the console command and the MCP tools each call the unchanged
service and render `toArray()`, keeping documents as decoded objects so an empty object stays distinct from an
empty list. Provisioning now answers the model projected after the binding is stored, which is the same model
every later read answers, so a first provision and its replay are byte-identical.

## Tests proving the boundary

- `StudioCompositionApiHandlerTest`, `StudioCompositionCommandTest` and `McpStudioCompositionToolsTest` run the
  real service over in-memory stores and pin the document, the 404/not-found before provisioning, the stale
  theme refusal and the capability refusals.
- `StudioCompositionMachineEquivalenceIntegrationTest` drives read and provision through REST, the console and
  MCP on MariaDB and PostgreSQL and asserts each answers the service's own document and one audit event.

## Decision

Approved as App delivery composition under the standing maintainer mandate. The ownership review is by the
implementing agent, not an independent review or human GitHub approval. Revisit if `kumwe/producer` gains a
host-neutral artifact envelope.
