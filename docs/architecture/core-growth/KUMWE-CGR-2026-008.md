---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-008
title: "Extension contribution registry set requires the host field-configuration admission"
symbols:
  - Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet
layer: application
capability_index_sha256: "d3c452a5f5be03cbf845bc93e202faa19ab0da0fae79da7afab315fe64bb5cb7"
packages_reviewed:
  - package: kumwe/business-definition
    version: 0.1.2
    symbols_inspected:
      - "Kumwe\\BusinessDefinition\\Application\\FieldConfigurationAdmission"
      - "Kumwe\\BusinessDefinition\\Application\\BusinessDefinitionValidator"
      - "Kumwe\\BusinessDefinition\\Application\\BusinessDefinitionContributionRegistry"
      - "Kumwe\\BusinessDefinition\\Application\\FieldTypeRegistry"
      - "Kumwe\\BusinessDefinition\\ConfigProvider"
      - "Kumwe\\BusinessDefinition\\Container\\BusinessDefinitionContributionRegistryFactory"
      - "Kumwe\\BusinessDefinition\\Container\\FieldTypeRegistryFactory"
    source_inspected:
      - vendor/kumwe/business-definition/src
    tests_inspected:
      - vendor/kumwe/business-definition/docs/test-ownership.md
  - package: kumwe/extension-sdk
    version: 0.2.4
    symbols_inspected:
      - "Kumwe\\Extension\\Spi\\BusinessSurface\\Presentation\\Field\\FieldPresentationConfiguration"
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Spi/BusinessSurface/Presentation/Field
    tests_inspected:
      - vendor/kumwe/extension-sdk/src/Spi/BusinessSurface/Presentation/Field
search_terms:
  - field configuration admission
  - presentation configuration profile
  - business definition contribution registry
  - contribution registry set
  - field type registry seeding
required_capability: "Compose the package business-definition contribution registry around a validator that admits field configuration through the host's SDK-backed adapter, which the package requires from the host and refuses to default."
consumers:
  - src/Kernel/ContainerFactory.php
  - src/Administrator/Navigation/AdministratorNavigationRegistry.php
  - tests/Support/AuthorizationContext.php
overlap_reviewed: []
decision: approved
decided_by: "eWɘyn"
reviewer: "eWɘyn"
decided_on: "2026-09-23"
pull_request: null
---

## Capability required

`ExtensionContributionRegistrySet` is the App's composition of every contribution registry an extension can
reach, including the business-definition contribution registry it builds around a `BusinessDefinitionValidator`
over its own contribution-fed `FieldTypeRegistry`. `kumwe/business-definition` 0.1.2 makes field-configuration
admission a host port, `FieldConfigurationAdmission`, and ships no default implementation (its decision
BUSDEF-002), so the set now requires that port as its first constructor argument and hands it to the validator.
Nothing else about the set changes; the record covers this one signature.

## Why existing package APIs are insufficient

The package registers `BusinessDefinitionContributionRegistryFactory` and `FieldTypeRegistryFactory` through
its `ConfigProvider`, but those factories resolve `BusinessDefinitionValidator` and `FieldTypeRegistry` from the
container and the registry factory seeds `BuiltInFieldTypes`, whereas the App's registry is created empty with
`new FieldTypeRegistry(false)` and receives the core built-ins as contributions through
`CoreContributionRegistrar`. The set constructs its registries directly, before the container can resolve them,
so the port has to arrive through the set's constructor.

## Why extending the owning package is inappropriate

The package deliberately owns no presentation contract: which field configuration is admissible depends on the
presentation profile the host composes, which is `kumwe/extension-sdk`'s `FieldPresentationConfiguration` in
this App. Giving the package a default would recreate the SDK coupling the extraction removed and would decide
the host's profile on its behalf.

## Why a new focused package is inappropriate

There is no portable bounded context in threading one host port through one host composition object. The
adapter that implements the port, `SdkFieldConfigurationAdmission`, is a presentation-layer host adapter
recorded as host growth; this record is only about the composition that consumes it.

## App-specific responsibility

Composition and authority: the set decides which registries an extension may contribute to, in which order they
are reconciled and removed, and which validator judges a contributed business definition. The port is supplied
by `ContainerFactory` from its single shared `SdkFieldConfigurationAdmission`; the administrator navigation
fallback and the test support helpers construct the set with the same adapter. No permissive or null admission
exists anywhere, so a contributed definition can never publish with configuration the presentation profile
would refuse.

## Tests proving the boundary

`tests/Unit/Extension/Contribution/ExtensionContributionRegistrySetTest.php` and the contribution tests under
`tests/Unit/Extension` construct the set with the adapter and exercise the business-definition registry;
`tests/Unit/BusinessDefinition/Domain/EntityTypeDefinitionTest.php` proves the adapter enforces the SDK budgets
at definition admission; `tests/Architecture/ExtensionContributionBoundaryTest.php` pins the single production
construction in `ContainerFactory`; `tests/Architecture/TruthfulQualityGateTest.php` proves the set adds no
dependency edge on the presentation layer.

## Decision

Approved on 2026-09-23 under the standing maintainer mandate as part of the kumwe/business-definition 0.1.2
adoption (`KUMWE-MIG-2026-010`). The approval covers the constructor signature that now requires the package
port. It grants no new reusable behaviour, no default admission and no further public method; revisit this
record when the set gains behaviour beyond composing the package registries or when the presentation profile
moves to another owner.
