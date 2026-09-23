---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-012
title: "The extension manifest interpretation, runtime loading and contribution registries compose the injected canonical encoder and the explicit surface identifier policies"
symbols:
  - Kumwe\App\Extension\Contribution\CanonicalManifestInterpreter
  - Kumwe\App\Extension\Contribution\CoreExtensionContributions
  - Kumwe\App\Extension\Contribution\ExtensionContributionSummary
  - Kumwe\App\Extension\Contribution\OwnedRuntimeContributionRegistry
  - Kumwe\App\Extension\Runtime\ExtensionRuntimeLoader
  - Kumwe\App\Extension\Runtime\ExtensionRuntimeMapCompiler
  - Kumwe\App\OpenApi\Application\OpenApiExtensionActivationAdmission
  - Kumwe\App\BusinessIntegration\Application\ProcessManagerService
  - Kumwe\App\BusinessIntegration\Application\BusinessRecordMutationEventPublisher
layer: application
capability_index_sha256: "589dd45b0a8700c4f72c073df5b7b859f85627c515d700edb565a9f0e66e9cb0"
packages_reviewed:
  - package: kumwe/extension-sdk
    version: 0.3.3
    symbols_inspected:
      - Kumwe\Extension\Manifest\ExtensionManifest
      - Kumwe\Extension\Manifest\ManifestContributions
      - Kumwe\Extension\Manifest\ManifestIdentifierPolicies
      - Kumwe\Extension\Spi\Binding\ExtensionBindingRegistrar
      - Kumwe\Extension\Toolchain\PackageInspector
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Manifest
      - vendor/kumwe/extension-sdk/docs/host-integration.md
      - vendor/kumwe/extension-sdk/docs/app-agreement.md
      - vendor/kumwe/extension-sdk/docs/release-record.md
    tests_inspected:
      - vendor/kumwe/extension-sdk/resources/public-api/v1.json
      - vendor/kumwe/extension-sdk/resources/contract/generations.json
  - package: kumwe/contribution
    version: 0.1.1
    symbols_inspected:
      - Kumwe\Contribution\ContributionOwner
      - Kumwe\Contribution\ContributionDefinition
      - Kumwe\Contribution\SurfaceIdentifierPolicy
      - Kumwe\Contribution\OwnedContributionRegistry
      - Kumwe\Contribution\ContributionRejected
    source_inspected:
      - vendor/kumwe/contribution/src
      - vendor/kumwe/contribution/MIGRATION-HANDOFF.md
    tests_inspected:
      - vendor/kumwe/contribution/resources/public-api/v1.json
  - package: kumwe/canonical-json
    version: 0.1.1
    symbols_inspected:
      - Kumwe\CanonicalJson\CanonicalEncoder
    source_inspected:
      - vendor/kumwe/canonical-json/src
    tests_inspected:
      - vendor/kumwe/canonical-json/resources/public-api/v1.json
  - package: kumwe/integration
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Integration\RecordedDomainEvent
      - Kumwe\Integration\ProcessInstance
      - Kumwe\Integration\ProcessWorkItem
      - Kumwe\Integration\ProcessManagerStore
    source_inspected:
      - vendor/kumwe/integration/src
      - vendor/kumwe/integration/docs/release-record.md
    tests_inspected:
      - vendor/kumwe/integration/resources/public-api/v1.json
search_terms:
  - "canonical encoder"
  - "manifest interpretation"
  - "manifest contributions"
  - "identifier policy"
  - "owned registry"
  - "runtime map"
  - "extension runtime loader"
  - "activation admission"
  - "contribution summary"
  - "recorded domain event"
  - "process manager"
required_capability: "Interpret admitted extension manifests, register core and extension contributions into the executable runtime registries, compile and load the trusted runtime map, admit OpenAPI activation, project the contribution summary, publish record mutations as recorded domain events and run the process manager over the canonical encoder the container binds, with every ownership check naming the surface identifier policy the host chooses."
consumers:
  - "src/Kernel/ContainerFactory.php"
  - "src/Extension/Contribution/ExtensionContributionRegistrySet.php"
  - "src/Extension/Contribution/CanonicalManifestActivator.php"
  - "src/Extension/Contribution/OwnedExtensionBindingRegistrar.php"
  - "src/Extension/Infrastructure/DoctrineExtensionManager.php"
  - "src/BusinessIntegration/Infrastructure/DoctrineProcessManagerStore.php"
  - "tests/Unit/Extension/Contribution/ExtensionContributionRegistrySetTest.php"
  - "tests/Unit/Extension/Contribution/ExtensionContributionSummaryTest.php"
  - "tests/Unit/Extension/Runtime/ExtensionRuntimeLoaderContributionShapeTest.php"
  - "tests/Unit/BusinessIntegration/Application/BusinessRecordMutationEventPublisherTest.php"
  - "tests/Unit/BusinessIntegration/ProcessCancellationWorkTest.php"
overlap_reviewed:
  - Kumwe\Contribution\OwnedContributionRegistry
decision: approved
decided_by: "eWɘyn (KUMWE-MIG-2026-033 adoption, standing maintainer mandate)"
reviewer: "eWɘyn (package-boundary review against the installed kumwe/extension-sdk 0.3.3 candidate, kumwe/contribution 0.1.1 and kumwe/integration 0.2.4 sources, manifests and records)"
decided_on: "2026-09-23"
pull_request: null
---

## Capability required

The App turns an admitted extension manifest into executable contributions: it interprets the manifest's
declarations into the typed definitions the registries hold, registers the core contributions beside them,
compiles the trusted runtime map that the loader materializes per generation, admits the OpenAPI
components an activation claims, and projects the summary the administrator reads. Every one of those
steps now runs over one canonical encoder, the container's native binding of
`Kumwe\CanonicalJson\CanonicalEncoder`, because extension-sdk 0.3.3 no longer carries a static canonical
JSON helper: `ExtensionManifest::fromJson`, `ManifestContributions::fromManifest`, the manifest
declaration factories and `ExtensionContributionSummary::project` take the encoder as an argument. Every
ownership check also names the surface identifier policy the host chooses, because
`Kumwe\Contribution\ContributionOwner::assertOwns` no longer infers it from a kind string. On the
integration side the mutation event publisher records domain events and the process manager service
hydrates process instances and work items through the same encoder, because `RecordedDomainEvent`,
`ProcessInstance` and `ProcessWorkItem` in integration 0.2.4 take it as their first argument.

## Why existing package APIs are insufficient

The eight classes consume the package APIs directly; what changed is their own public surface, which is
why this record exists. `CanonicalManifestInterpreter`, `ExtensionRuntimeLoader`,
`ExtensionRuntimeMapCompiler`, `OpenApiExtensionActivationAdmission` and `CoreExtensionContributions` take
the encoder as a constructor or method argument and hand it to the SDK factories; the SDK deliberately
registers no provider and documents direct construction, so the host must thread the encoder itself.
`ExtensionContributionSummary::project` takes it first for the same reason. `OwnedRuntimeContributionRegistry`
takes an optional `SurfaceIdentifierPolicy`, defaulting to the SDK `ManifestIdentifierPolicies::forKind`
projection, because the package `OwnedContributionRegistry` is a neutral snapshot registry: it holds
definitions only, while the App registry holds implementation objects, exposes `executableEntries()` and
`implementation()`, and is withdrawn on disable or uninstall, which the package record names as a host
responsibility. `ProcessManagerService` and `BusinessRecordMutationEventPublisher` take the encoder after
the clock and the execution context respectively; the package ships no publisher or service, only the
values and the store ports the App's Doctrine stores implement.

## Why extending the owning package is inappropriate

The SDK's charter and release record name admission, trust, activation, persistence and the host container as
non-responsibilities, and `kumwe/contribution` refuses to be an executable runtime registry by design. An
SDK or contribution package that resolved the encoder ambiently, kept implementation objects or decided
which policy a host surface uses would carry App composition and authority into a package every host
shares.

## Why a new focused package is inappropriate

The ten classes are the App's composition of the package contracts over its own container, generations,
trust store, OpenAPI catalog, outbox and process stores. They have no consumer outside App and no portable algorithm of their own;
a package of them would own App composition without owning anything reusable.

## App-specific responsibility

This is composition and authority: which encoder the runtime uses, which policy each surface applies, which
contributions are executable, how generations are compiled and loaded, and what an activation may claim.
The public surfaces changed only in what they receive: the encoder and the policy instead of static
helpers. Manifest digests, runtime maps, stored contribution rows, recorded events and process rows are
byte-identical to the train baseline.

## Tests proving the boundary

- `tests/Unit/Extension/Contribution/ExtensionContributionRegistrySetTest.php` and
  `tests/Unit/Extension/Contribution/ExtensionContributionSummaryTest.php` pin the registries, the
  interpreter and the summary over the deterministic test encoder.
- `tests/Unit/Extension/Runtime/ExtensionRuntimeLoaderContributionShapeTest.php` and
  `tests/Integration/Extension/GeneratedExtensionLifecycleIntegrationTest.php` pin compilation and loading of
  the trusted runtime map.
- `tests/Integration/Extension/ContributedContentTranslationIntegrationTest.php` pins that an extension
  cannot claim another owner's namespace, now refused by `ContributionRejected`.
- `tests/Unit/BusinessIntegration/Application/BusinessRecordMutationEventPublisherTest.php` and
  `tests/Unit/BusinessIntegration/ProcessCancellationWorkTest.php` pin the recorded events and the process
  work the two services produce over the deterministic test encoder.
- The package suites own manifest parsing, the identifier policies and the snapshot registry; no App test
  duplicates them.

## Decision

Approved on 2026-09-23 under the standing maintainer mandate as part of the KUMWE-MIG-2026-033 adoption.
The review compared the ten classes against the installed SDK candidate, contribution and integration
sources and records and found that they consume the package contracts without duplicating their
algorithms and keep only App composition. This record is the adoption custodian's
decision and review under that mandate; it is not a human pull-request review event. Revisit when
`kumwe/extension-sdk` next releases with an encoder-bearing provider or when `kumwe/contribution` ships an
executable registry, so that the composition can move upstream.
