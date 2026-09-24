---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-031
title: "Machine-surface Studio authoring binding and gateway"
symbols:
  - Kumwe\App\Studio\Application\Host\StudioSessionSurfaceBinding
  - Kumwe\App\Studio\Application\Host\StudioProducerRequestAuthority
  - Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway
  - Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringOperation
  - Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused
  - Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringResult
  - Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringSession
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
      - Kumwe\Producer\Wire\Port\AuthorizationInterface
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
  - package: kumwe/idempotency
    version: v0.1.3
    symbols_inspected:
      - Kumwe\Idempotency\IdempotencyKey
    source_inspected:
      - vendor/kumwe/idempotency/src/IdempotencyKey.php
    tests_inspected:
      - vendor/kumwe/idempotency/tests
search_terms:
  - studio host session binding
  - machine credential binding
  - authoring gateway
  - producer dispatcher envelope
  - idempotent replay evidence
  - host error refusal mapping
required_capability: "Let REST, CLI and MCP callers open a Studio Content authoring context bound to their credential and run the seven authoring operations through the browser's own Producer host, authorization, replay, transaction and audit boundary."
consumers:
  - src/Delivery/Http/Api/Studio/StudioAuthoringApiHandler.php
  - src/Delivery/Console/Command/StudioAuthoringCommand.php
  - src/Infrastructure/Mcp/KumweMcpHandlers.php
overlap_reviewed: []
decision: approved
decided_by: "Machine-contract parity implementation agent under standing maintainer mandate"
reviewer: "Machine-contract parity implementation agent (source ownership review; not human approval)"
decided_on: "2026-09-24"
pull_request: "https://github.com/kumwe/app/pull/152"
---

## Capability required

A machine actor must perform Studio's durable Content authoring journey with the permissions, revisions,
refusals, replay and audit a browser author meets. The authority binding must be opened server-side from the
machine credential and site and must never be asserted by the client. The machine inventory must be derived
from the same operation registry the browser port dispatches, so it cannot drift.

## Why existing package APIs are insufficient

Producer supplies the canonical dispatcher, operation registry, envelope grammar and host-error taxonomy. It
deliberately contains no identity, credential, session or persistence authority, and exports no host
implementation; `AuthorizationInterface` and the ports are implemented by hosts. access-context supplies the
authenticated surface and execution context but no Studio binding policy. idempotency supplies the REST key
value only. The existing public APIs are reused unchanged: the gateway builds a canonical envelope and hands
it to Producer's own `Dispatcher` over the App's existing request-scoped host.

## Why extending the owning package is inappropriate

Which App surfaces may hold a Studio binding, what each binds to (browser session or credential), and how a
refusal is carried to App delivery adapters are App authority and delivery decisions. Producer's charter
keeps host identity, persistence and transport outside the package; moving credential binding or App
surface policy into it would give a portable library knowledge of App tokens and surfaces.

## Why a new focused package is inappropriate

There is no portable bounded context. The symbols compose App identity (`AuthenticatedPrincipal`), App
Studio host authority (`StudioHostSessionAuthority`, `ContentStudioAuthoringContextAuthority`) and App
Content services. A separate package would need all of them.

## App-specific responsibility

This is host authority and orchestration. `StudioSessionSurfaceBinding` is the App policy for which
authenticated surfaces may hold a Studio binding and what the binding digests. The gateway orchestrates
existing App authorities and Producer's dispatcher for machine delivery adapters. The operation enum names
the registry rows through the machine contracts; the refusal, result and session values carry Producer's
canonical documents to REST, CLI and MCP. `StudioProducerRequestAuthority` gains replay evidence so the
adapters can report a replay the wire does not mark. Outside App none of these can reach the host session,
context and credential stores they depend on.

## Tests proving the boundary

- `StudioMachineAuthoringEquivalenceIntegrationTest` drives one journey and the refusal cases through the
  gateway, REST, CLI and MCP on MariaDB and PostgreSQL and asserts identical outcomes and refusals.
- `StudioAuthoringMachineParityTest` enumerates the browser authoring operations and requires a REST route,
  CLI action and MCP tool for each.
- `StudioSessionSurfaceBindingTest` pins surface admission, credential binding and cross-surface refusal.
- `StudioMachineAuthoringGatewayTest` pins the registry-derived vocabulary, canonical refusals and every
  refusal made before a store is touched.

## Decision

Approved as App host authority and delivery orchestration under the standing maintainer mandate. The
ownership review is by the implementing agent, not an independent review or human GitHub approval. Revisit
if Producer publishes a host-neutral machine envelope builder or a portable binding policy.
