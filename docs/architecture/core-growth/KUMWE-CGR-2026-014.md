---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-014
title: "The custom business invocation scope resolves the host context a custom view or action handler was handed for the SDK record reader"
symbols:
  - Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessInvocationScope
  - Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessSurfaceDispatcher
  - Kumwe\App\BusinessRecord\Application\PolicyBusinessRecordReader
layer: application
capability_index_sha256: "a1e87485c75164f6c70d21f8106432469754d68e81fce553162a2d0d5bed67b9"
packages_reviewed:
  - package: kumwe/business-surface-contract
    version: 0.1.4
    symbols_inspected:
      - Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessViewQuery
      - Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessActionCommand
      - Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessViewHandler
      - Kumwe\BusinessSurface\Contract\Application\Custom\CustomBusinessActionHandler
    source_inspected:
      - vendor/kumwe/business-surface-contract/src/Application/Custom
      - vendor/kumwe/business-surface-contract/docs/core-contract.md
      - vendor/kumwe/business-surface-contract/docs/release-record.md
    tests_inspected:
      - vendor/kumwe/business-surface-contract/resources/public-api/v1.json
  - package: kumwe/extension-sdk
    version: 0.3.3
    symbols_inspected:
      - Kumwe\Extension\Spi\Application\ExecutionContext
      - Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReader
      - Kumwe\Extension\Spi\BusinessRecord\Application\BusinessRecordReadRequest
      - Kumwe\Extension\Spi\Http\ExtensionRequest
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Spi/Application
      - vendor/kumwe/extension-sdk/src/Spi/BusinessRecord/Application
      - vendor/kumwe/extension-sdk/docs/host-integration.md
      - vendor/kumwe/extension-sdk/docs/release-record.md
    tests_inspected:
      - vendor/kumwe/extension-sdk/resources/public-api/v1.json
  - package: kumwe/access-context
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Context\Value\ExecutionContext
    source_inspected:
      - vendor/kumwe/access-context/src/Value/ExecutionContext.php
    tests_inspected:
      - vendor/kumwe/access-context/resources/public-api/v1.json
search_terms:
  - "execution context"
  - "host-issued"
  - "custom view"
  - "custom action"
  - "business record reader"
  - "invocation scope"
required_capability: "Let a custom business view or action handler, which kumwe/business-surface-contract 0.1.4 hands the host ExecutionContext, read business records through the SDK BusinessRecordReader port under the host policy, by naming the host context of exactly the custom invocation the App dispatched so that the App reader resolves it to the context the App registered and never to a context extension code minted."
consumers:
  - "src/BusinessSurface/Application/Custom/CustomBusinessSurfaceDispatcher.php"
  - "src/BusinessRecord/Application/PolicyBusinessRecordReader.php"
  - "src/Kernel/ContainerFactory.php"
  - "examples/extensions/asset-inspection/src/Application/InspectionSummaryViewHandler.php"
  - "examples/extensions/asset-inspection/src/Application/InvocationExecutionContext.php"
  - "tests/Unit/BusinessSurface/Application/Custom/CustomBusinessInvocationScopeTest.php"
  - "tests/Unit/BusinessSurface/Application/Custom/CustomBusinessHandlerRegistryTest.php"
  - "tests/Unit/BusinessRecord/Application/PolicyBusinessRecordReaderTest.php"
  - "tests/Integration/Extension/AssetInspectionCustomViewIntegrationTest.php"
overlap_reviewed: []
decision: approved
decided_by: "eWɘyn (KUMWE-MIG-2026-038 adoption follow-up, standing maintainer mandate)"
reviewer: "eWɘyn (package-boundary review against the installed kumwe/business-surface-contract 0.1.4, kumwe/extension-sdk 0.3.3 and kumwe/access-context 0.1.2 sources, manifests and records)"
decided_on: "2026-09-23"
pull_request: null
---

## Capability required

A custom business view or action handler must be able to read business records through the SDK reader
port under the host's own policy, as the asset-inspection example and its retained integration test have
always done. `kumwe/business-surface-contract` 0.1.4 hands the handler the host
`Kumwe\Context\Value\ExecutionContext` in `CustomBusinessViewQuery` and `CustomBusinessActionCommand`,
while the SDK `BusinessRecordReadRequest` takes the SDK `Kumwe\Extension\Spi\Application\ExecutionContext`
interface, and the App reader honours no context it did not itself issue. Before the train the App minted
the SDK envelope into the query; the package type now forbids that. The scope is the App's way of issuing
the context for exactly the invocation it belongs to: the dispatcher enters it with the query's or
command's host context before extension code runs and leaves it in a `finally` block, and the reader
resolves a request whose context names the same seven coordinates to the host context the dispatcher
registered. The handler holds only package types; authority comes from the App-registered host context,
never from the object the handler presents.

## Why existing package APIs are insufficient

The SDK documents its execution contexts as host-issued provenance capabilities and offers
`ExtensionRequest::context()` for HTTP routes only; it exports no bridge from the host context to its
interface. `kumwe/access-context`'s `ExecutionContext` exposes the same seven coordinates but is a final
class that does not implement the SDK interface, and `kumwe/business-surface-contract` types its query and
command on that host class. The two released contracts disagree on the extension boundary, and the App
composes both as released; the core contract of `kumwe/business-surface-contract` places actor and site
authority with Core, which is where this decision sits.

## Why extending the owning package is inappropriate

A bridge in `kumwe/extension-sdk` would have it accept a host context from extension code as authority,
which its security model refuses; a bridge in `kumwe/business-surface-contract` would make a portable
contract depend on the SDK it was extracted from. Which context type custom handlers receive is an
upstream decision the App does not pre-empt on this branch; the scope composes the released types and
keeps the App reader the only place that turns a context into a browse.

## Why a new focused package is inappropriate

The scope is App composition between two App-owned points, the dispatcher and the reader, with no consumer
outside App and no portable algorithm.

## App-specific responsibility

The App decides which extension code runs under which host context and for how long. The scope records
that decision for custom invocations, and the reader consults it after the host envelope check it already
performed. The dispatcher's constructor gains the scope, the reader's constructor gains it optionally so a
reader without a scope honours host envelopes only, and the container shares one scope between the
dispatcher and every extension-facing reader. No persisted data, contract or manifest changes.

## Tests proving the boundary

- `tests/Unit/BusinessSurface/Application/Custom/CustomBusinessInvocationScopeTest.php` pins that nothing
  resolves outside an invocation, that only the innermost invocation's coordinates resolve, that nested
  invocations unwind, and that leaving without an invocation is refused.
- `tests/Unit/BusinessSurface/Application/Custom/CustomBusinessHandlerRegistryTest.php` pins that the
  dispatcher runs view and action handlers inside the scope and leaves it when a handler fails.
- `tests/Unit/BusinessRecord/Application/PolicyBusinessRecordReaderTest.php` pins that a foreign context
  is still refused outside any invocation and when it names another invocation's coordinates.
- `tests/Integration/Extension/AssetInspectionCustomViewIntegrationTest.php` proves the example handler
  is refused outside its invocation and reads the policy-filtered page inside it, on the three database
  engines of the hosted lanes.

## Decision

Approved on 2026-09-23 under the standing maintainer mandate as a follow-up of the KUMWE-MIG-2026-038
adoption. The review compared the three classes against the installed business-surface-contract, SDK and
access-context sources and records and found that they compose the released types without duplicating any
package symbol and without widening what an extension may read. This record is the adoption custodian's
decision and review under that mandate; it is not a human pull-request review event. Revisit when an
upstream release gives custom handlers an SDK context, or the SDK reader a host one, so that the scope can
be retired.
