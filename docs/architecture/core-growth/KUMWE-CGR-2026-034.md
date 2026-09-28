---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-034
title: "Machine-surface Studio Blueprint composition gateway"
symbols:
  - Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway
  - Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionOperation
  - Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionResult
  - Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionSession
layer: application
capability_index_sha256: "ea1e5091c8c846ec8434e6e45cc04a384e43187b1f5aae9d147b9c0814783826"
packages_reviewed:
  - package: kumwe/producer
    version: v0.3.0
    symbols_inspected:
      - Kumwe\Producer\Wire\Dispatcher
      - Kumwe\Producer\Wire\OperationRegistry
      - Kumwe\Producer\Wire\RequestEnvelope
      - Kumwe\Producer\Error\HostError
      - Kumwe\Producer\Error\ContractGrammar
    source_inspected:
      - vendor/kumwe/producer/src/Wire/Dispatcher.php
      - vendor/kumwe/producer/src/Wire/OperationRegistry.php
      - vendor/kumwe/producer/src/Wire/RequestEnvelope.php
      - vendor/kumwe/producer/CHARTER.md
    tests_inspected:
      - vendor/kumwe/producer/tests
  - package: kumwe/access-context
    version: v0.1.2
    symbols_inspected:
      - Kumwe\Context\Value\ExecutionContext
      - Kumwe\Context\Value\AuthenticatedSurface
    source_inspected:
      - vendor/kumwe/access-context/src/Value/ExecutionContext.php
      - vendor/kumwe/access-context/src/Value/AuthenticatedSurface.php
    tests_inspected:
      - vendor/kumwe/access-context/tests
search_terms:
  - blueprint composition session
  - studio artifact port
  - artifact save publish unpublish
  - machine composition gateway
  - producer dispatcher envelope expected revision
required_capability: "Let REST, CLI and MCP callers open a Blueprint composition session for one provisioned Content type version, bound to their credential, and run the five artifact operations the composition screen's Studio shell dispatches (load, dependencies, save, publish, unpublish) through the browser's own Producer host, authorization, lifecycle, replay and optimistic-concurrency boundary."
consumers:
  - src/Delivery/Http/Api/Studio/StudioCompositionSessionApiHandler.php
  - src/Delivery/Console/Command/StudioBlueprintCommand.php
  - src/Infrastructure/Mcp/KumweMcpHandlers.php
overlap_reviewed:
  - Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway
decision: approved
decided_by: "Browser-to-machine parity implementation agent under standing maintainer mandate"
reviewer: "Browser-to-machine parity implementation agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

The administrator composition screen finds a Content type version's bound Blueprint, opens a Blueprint host
session for its artifact, and edits it through Producer's `artifact` port: load, dependencies, save, publish and
unpublish, each save and lifecycle move carrying the expected revision and an idempotency key. A machine actor
must be able to perform the same work with the same authority: `studio.mode.blueprint` for the session,
`content.publish` and `content.unpublish` decided separately for the lifecycle moves, the schema, Blueprint lock
and draft-continuity checks, keyed replay and the stale-revision conflict.

## Why existing package APIs are insufficient

`kumwe/producer` owns the wire contract, the operation registry and the dispatcher, and is reused unchanged: the
gateway builds the canonical envelope and dispatches it through the same request-scoped host the browser route
uses. Producer deliberately owns no host authentication, session binding or persistence. The existing
`StudioMachineAuthoringGateway` (KUMWE-CGR-2026-031) covers only the `authoring` port and Content authoring
sessions; its operation enum, session and result are typed to that port's seven operations and its envelope
carries no expected revision, which every artifact mutation requires.

## Why extending the owning package is inappropriate

Finding the bound Blueprint of a Content type version, the theme-lock check and binding the session to a
machine credential are App state and App policy (`StudioContentCompositionService`, `StudioHostSessionAuthority`).
Moving them into `kumwe/producer` would make the portable wire package depend on App persistence and identity.

## Why a new focused package is inappropriate

There is no portable bounded context: the gateway composes App services with the Producer dispatcher exactly as
the authoring gateway does. Widening the authoring gateway instead would mix two resource families behind one
type, so the composition gateway is a sibling that reuses the authoring gateway's refusal type
(`StudioMachineAuthoringRefused`) and problem mapping, keeping one refusal vocabulary for the machine Studio
surfaces.

## App-specific responsibility

`StudioHostSessionAuthority` now admits machine surfaces for Blueprint sessions as well as Content authoring
sessions; the generic Content family stays browser-only. Everything else is enforced by the unchanged host: the
Blueprint mode decision, the separate publish and unpublish decisions, the artifact admission and Blueprint lock
checks, the Producer mutation boundary's keyed replay, and the canonical `host-error` refusals.

## Tests proving the boundary

- `StudioMachineCompositionGatewayTest` pins the operation set to the pinned registry, the session and result
  documents, and every refusal made before a session or host is reached.
- `StudioCompositionSessionApiHandlerTest`, `StudioBlueprintCommandTest` and `McpStudioBlueprintToolsTest` pin the
  REST, console and MCP grammars and refusal vocabularies.
- `StudioBlueprintMachineEquivalenceIntegrationTest` drives open, load, dependencies, save, replay, stale save,
  publish and unpublish through REST, the console and MCP on MariaDB and PostgreSQL, and proves the missing
  Blueprint mode and missing publication grant are refused identically.

## Decision

Approved as App delivery composition and security enforcement under the standing maintainer mandate. The
ownership review is by the implementing agent, not an independent review or human GitHub approval. Revisit if
`kumwe/producer` gains a host-neutral machine session port.
