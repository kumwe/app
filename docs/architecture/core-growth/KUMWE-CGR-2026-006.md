---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-006
title: "The host access-policy tables kumwe/access-control refuses to default"
symbols:
  - Kumwe\App\Application\Authorization\HostAccessPolicy
layer: application
capability_index_sha256: "dd6380d5f3cbe868c69de22a87808bca7f552d1560fb5d9e411dd4d1bc6bbef2"
packages_reviewed:
  - package: kumwe/access-control
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Access\MembershipRequirement
      - Kumwe\Access\AuthorizationPolicyRegistry
      - Kumwe\Access\ResourceOwnershipScopePolicy
      - Kumwe\Access\OwnershipScopeRule
      - Kumwe\Access\ConfigProvider
      - Kumwe\Access\Container\AuthorizationPolicyRegistryFactory
      - Kumwe\Access\Container\ResourceOwnershipScopePolicyFactory
      - Kumwe\Access\Container\CompositeResourceOwnershipReferencesFactory
    source_inspected:
      - vendor/kumwe/access-control/src
      - vendor/kumwe/access-control/docs/integration.md
      - vendor/kumwe/access-control/MIGRATION-HANDOFF.md
    tests_inspected:
      - vendor/kumwe/access-control/resources/service-map/v1.json
      - vendor/kumwe/access-control/resources/conformance/decisions-v1.json
  - package: kumwe/access-context
    version: 0.1.2
    symbols_inspected:
      - Kumwe\Context\Value\SiteContext
      - Kumwe\Context\Value\MembershipContext
    source_inspected:
      - vendor/kumwe/access-context/src
    tests_inspected:
      - vendor/kumwe/access-context/docs/test-ownership.md
  - package: kumwe/extension-sdk
    version: 0.2.4
    symbols_inspected:
      - Kumwe\Extension\Spi\Identity\Domain\Capability
    source_inspected:
      - vendor/kumwe/extension-sdk/src/Spi
    tests_inspected:
      - vendor/kumwe/extension-sdk/resources/PIN.json
search_terms:
  - "membership resource types"
  - "membership-sensitive"
  - "reserved ownership rules"
  - "reserved category"
  - "ownership scope rule"
  - "site only"
  - "site or group"
  - "business group"
  - "accounting isolation"
  - "kumwe.access"
  - "MembershipRequirement"
  - "ResourceOwnershipScopePolicy"
required_capability: "State, in one App-owned place, the seven membership-sensitive resource types and the forty-four reserved ownership categories the package factories require and refuse to default."
consumers:
  - "src/Kernel/ContainerFactory.php"
  - "src/Extension/Contribution/ExtensionContributionRegistrySet.php"
  - "src/Extension/Contribution/CapabilityDefinitionRegistry.php"
  - "tests/Unit/Application/Authorization/AuthorizationPolicyRegistryTest.php"
  - "tests/Unit/Application/Authorization/OwnershipScopeModelTest.php"
  - "tests/Unit/Application/Authorization/ResourceOwnershipScopeServiceTest.php"
  - "tests/Unit/Application/Authorization/DoctrineResourceSiteOwnershipWriterTest.php"
  - "tests/Unit/Portal/Contribution/PortalContributionRegistryTest.php"
overlap_reviewed: []
decision: approved
decided_by: "eWɘyn (KUMWE-MIG-2026-009 adoption, standing maintainer mandate)"
reviewer: "eWɘyn (package-boundary review against the installed kumwe/access-control 0.1.2 sources, manifests and record)"
decided_on: "2026-09-23"
pull_request: null
---

## Capability required

Two tables decide how far authority reaches in this installation and are, by the package's own charter,
the host's to state. The first names the resource types a delegated credential may only reach while it
carries a live organization membership: `approval_request`, `business_record`, `organization`,
`organization_membership`, `resource_policy`, `separation_duty_rule` and `workspace`. The second fixes,
for forty-four resource categories, the ownership levels a resource may be held at, so that a legal
entity's books, ledgers and pay runs are owned by one site only, the master data businesses share may be
widened to a declared group, and the categories core itself carries cannot be reclassified by anything
loaded later. Both tables must be stated once, as source rather than configuration, be handed to the
package factories in the wire form they validate, seed the isolated registries the contribution surfaces
build without a container, and be pinned by tests so that a typo cannot weaken enforcement silently.

## Why existing package APIs are insufficient

`kumwe/access-control` 0.1.2 owns the mechanism and deliberately not the tables. `MembershipRequirement`
snapshots whatever list it is given and `AuthorizationPolicyRegistry` consults it; `ResourceOwnershipScopePolicy`
snapshots whatever reserved map it is given and answers `SiteOnly` for anything else; the three factories
under `Kumwe\Access\Container` read `kumwe.access.membership_resource_types`, `reserved_ownership_rules`
and `reference_inspectors` from the host `config` service and throw when any is absent. The handoff's
`non_responsibilities` names "App sensitive resource categories and reserved ownership table", and
`docs/integration.md` instructs the host to "supply the exact existing seven membership-sensitive targets
and 44 reserved ownership categories in App code during adoption; package defaults must never substitute
for that configuration". `kumwe/access-context` 0.1.2 owns the site and membership values the tables are
applied to and carries no category vocabulary. `kumwe/extension-sdk` 0.2.4 declares its own `Capability`
value for route and navigation definitions and no policy table.

## Why extending the owning package is inappropriate

A default table in the package would be exactly the fallback its charter forbids: a second host could not
adopt the package without inheriting this installation's accounting categories, and this installation
could no longer prove that its tables are the ones it states. The package already exposes the right seam,
explicit constructor arguments and explicit configuration keys; what remains is host data.

## Why a new focused package is inappropriate

The tables are this product's vocabulary, ADR 0001's business-group decision and the categories core
happens to carry. They have no consumer outside App and no behaviour of their own; a package of two arrays
would own nothing portable.

## App-specific responsibility

This is security enforcement composition. `HostAccessPolicy` holds the two tables as private constants
and exposes them four ways: the raw list and map for the composition root, which converts the rules to
their wire values for the `kumwe.access` configuration the package factories build the shared services
from; and a `MembershipRequirement` and a fresh `ResourceOwnershipScopePolicy` for the contribution
registries' isolated fallback registry and for the tests that construct the policy without a container.
Nothing in it decides anything: decisions stay with the package registry and policy and with the App
gateway. If it lived outside App, the installation's isolation guarantees would be a dependency's
defaults rather than this build's statement.

## Tests proving the boundary

- `tests/Unit/Application/Authorization/AuthorizationPolicyRegistryTest.php` pins the exact seven
  membership-sensitive types, that every one of them makes an extension capability membership-sensitive
  through the package registry built from `HostAccessPolicy::membershipRequirement()`, that a capability
  name alone does not, and that a disabled policy fails closed.
- `tests/Unit/Application/Authorization/OwnershipScopeModelTest.php` pins that the reserved table has
  exactly forty-four categories, that the policy built from it answers the same table, that accounting
  categories refuse group scope, that shared master data admits it, that every category admits a site
  owner, that an unknown category stays isolated, and that a reserved category cannot be redeclared.
- `tests/Unit/Extension/Contribution/ExtensionContributionRegistrySetTest.php` and
  `tests/Unit/Portal/Contribution/PortalContributionRegistryTest.php` exercise the isolated fallback
  registry the contribution surfaces build from the same table.
- The package's own `tests/Case/RegistryTest.php` and `tests/Case/OwnershipTest.php` own the registry
  and rule mechanics; no App test duplicates them.

## Decision

Approved on 2026-09-23 under the standing maintainer mandate as part of the KUMWE-MIG-2026-009 adoption.
The review compared the class against the installed 0.1.2 sources, the service map, the handoff's
`intentionally_excluded` and `non_responsibilities` lists and `docs/integration.md`, and found that the
package exposes the tables as explicit host inputs with no default of its own; the class states them and
adds no decision logic. This record is the adoption custodian's decision and review under that mandate;
it is not a human pull-request review event. Revisit when `kumwe/access-control` next releases with a
host-policy value of its own, or when ADR 0001's business-group table changes.
