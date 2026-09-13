---
schema: "kumwe-migration-handoff/v2"
artifact_kind: "framework_php"
migration_id: "KUMWE-MIG-2026-035"
change_set: "KUMWE-CS-2026-034"
state: "draft_pr_open"
source:
  app:
    repository: "https://github.com/kumwe/app"
    baseline_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
    examined_paths:
      - "src/Navigation/Domain/InvalidMenuTree.php"
      - "src/Navigation/Domain/MenuItem.php"
      - "src/Navigation/Domain/MenuTree.php"
      - "src/Navigation/Application/MenuItemRecord.php"
      - "src/Navigation/Application/MenuRecord.php"
      - "src/Navigation/Application/NavigationNotFound.php"
      - "src/Navigation/Application/NavigationRepository.php"
      - "src/Navigation/Application/NavigationVersionConflict.php"
    old_namespace_roots:
      - "Kumwe\\App\\Navigation\\Application\\"
      - "Kumwe\\App\\Navigation\\Domain\\"
    capability_index_sha256: null
  semantic_inputs:
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Domain/InvalidMenuTree.php"
      sha256: "45299fe6c09fb4b09b2ffb1425e0f71c8316cfcbb22ec876b30abc74c9f5ec42"
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Domain/MenuItem.php"
      sha256: "651edaa7eac4884895d9e3d79031512266c5dbee1252b3fc1deb90a8e89a7ead"
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Domain/MenuTree.php"
      sha256: "f7e1b340e097ae06d84f7211f72570c1a0cd8638f3a1b346ee0e0df2e6da9025"
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Application/MenuItemRecord.php"
      sha256: "0733b5246a4ed1c1849d66be9cb63f6d44dbf5f0f297f8fe349a0b3f278bf89f"
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Application/MenuRecord.php"
      sha256: "ccd1abc2ff882ae1abaebf35bfba6e354f79ff4a0f8c7aed9bc7a4739b4475fe"
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Application/NavigationNotFound.php"
      sha256: "4b4a9813ac6fe552d2fc4601bb7a21fec284cb9136b53afd32220c60163db09e"
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Application/NavigationRepository.php"
      sha256: "45d2c9508e61c9be8b9ae78c4974ad32447583e761b24a82dc0e44d4e9c48800"
    -
      owner: "https://github.com/kumwe/app"
      version_or_commit: "24ecf956423c18933e824b43cea1bfb9127a79a9"
      manifest_or_corpus: "src/Navigation/Application/NavigationVersionConflict.php"
      sha256: "caf2b1f1cbefb4b06489df26c395104d23ab2702079aa39321ff5cd11d68dda6"
  examined_dependencies:
    - "php ^8.5"
    - "ext-mbstring *"
  active_related_pull_requests: []
target:
  repository: "https://github.com/kumwe/navigation"
  artifact_identity: "kumwe/navigation"
  canonical_namespace_or_abi: "Kumwe\\Navigation\\"
  branch: "agent/complete-repository-conformance"
  pull_request: "https://github.com/kumwe/navigation/pull/5"
ownership:
  responsibility: "Bounded deterministic navigation trees, records and persistence contracts."
  non_responsibilities:
    - "authorization"
    - "transactions"
    - "persistence adapters"
    - "active registries"
    - "trust and lifecycle"
    - "HTTP and rendering"
  allowed_dependency_ceiling:
    - "php"
    - "ext-mbstring"
  implementation_owner: "kumwe/navigation"
  next_consumer: "kumwe/app"
  public_manifests:
    -
      path: "resources/public-api/v1.json"
      sha256: "fc1caa1c9b859e091d0cda6de0b5283458110ffaa6c4a8ea8e90060287dc7484"
    -
      path: "resources/capabilities/v1.json"
      sha256: "84fb94a6c8d5f988b5a492c3aa2dbba6f6f4ba18c482480acbd07f86eeae922b"
    -
      path: "resources/service-map/v1.json"
      sha256: "4a93bf6835f4b896af14756c6f542f50d68c7e26ce5cc28b637a65f275ff8020"
    -
      path: "resources/public-api/signature-details-v1.json"
      sha256: "c21a9252fb7f5bf07f3900d95a74834227a57f9a080660ede1818dc5f0a350d7"
  intentionally_excluded:
    - "NavigationService.php"
    - "PublicNavigation.php"
framework_php:
  composer_package: "kumwe/navigation"
  canonical_namespace: "Kumwe\\Navigation\\"
  public_api_manifest: "resources/public-api/v1.json"
  capability_manifest: "resources/capabilities/v1.json"
  service_map: "resources/service-map/v1.json"
  extracted_symbols:
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Domain\\InvalidMenuTree"
      new_fqcn: "Kumwe\\Navigation\\Domain\\InvalidMenuTree"
      source_path: "src/Navigation/Domain/InvalidMenuTree.php"
      target_path: "src/Domain/InvalidMenuTree.php"
      kind: "class"
      public_methods: []
      public_properties: []
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions: []
      serialization_contract: null
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Domain\\MenuItem"
      new_fqcn: "Kumwe\\Navigation\\Domain\\MenuItem"
      source_path: "src/Navigation/Domain/MenuItem.php"
      target_path: "src/Domain/MenuItem.php"
      kind: "class"
      public_methods:
        - "create"
        - "id"
        - "title"
        - "slug"
        - "parentId"
        - "path"
        - "placedAt"
      public_properties: []
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions:
        - "InvalidArgumentException"
      serialization_contract: null
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Domain\\MenuTree"
      new_fqcn: "Kumwe\\Navigation\\Domain\\MenuTree"
      source_path: "src/Navigation/Domain/MenuTree.php"
      target_path: "src/Domain/MenuTree.php"
      kind: "class"
      public_methods:
        - "create"
        - "id"
        - "item"
        - "items"
        - "move"
      public_properties: []
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions:
        - "Kumwe\\Navigation\\Domain\\InvalidMenuTree"
        - "InvalidArgumentException"
      serialization_contract: null
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Application\\MenuItemRecord"
      new_fqcn: "Kumwe\\Navigation\\Application\\MenuItemRecord"
      source_path: "src/Navigation/Application/MenuItemRecord.php"
      target_path: "src/Application/MenuItemRecord.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "toArray"
      public_properties:
        - "id"
        - "menuId"
        - "parentId"
        - "title"
        - "slug"
        - "path"
        - "position"
        - "version"
        - "createdAt"
        - "updatedAt"
        - "targetType"
        - "contentId"
        - "targetUrl"
        - "template"
        - "colorScheme"
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions: []
      serialization_contract: "Public toArray shape is documented in docs/public-api.md and covered by package-owned tests."
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Application\\MenuRecord"
      new_fqcn: "Kumwe\\Navigation\\Application\\MenuRecord"
      source_path: "src/Navigation/Application/MenuRecord.php"
      target_path: "src/Application/MenuRecord.php"
      kind: "class"
      public_methods:
        - "__construct"
        - "toArray"
      public_properties:
        - "id"
        - "handle"
        - "title"
        - "version"
        - "createdAt"
        - "updatedAt"
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions: []
      serialization_contract: "Public toArray shape is documented in docs/public-api.md and covered by package-owned tests."
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Application\\NavigationNotFound"
      new_fqcn: "Kumwe\\Navigation\\Application\\NavigationNotFound"
      source_path: "src/Navigation/Application/NavigationNotFound.php"
      target_path: "src/Application/NavigationNotFound.php"
      kind: "class"
      public_methods: []
      public_properties: []
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions: []
      serialization_contract: null
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Application\\NavigationRepository"
      new_fqcn: "Kumwe\\Navigation\\Application\\NavigationRepository"
      source_path: "src/Navigation/Application/NavigationRepository.php"
      target_path: "src/Application/NavigationRepository.php"
      kind: "interface"
      public_methods:
        - "menus"
        - "menu"
        - "items"
        - "item"
        - "insertMenu"
        - "updateMenu"
        - "itemIdsForMenuDeletion"
        - "deleteMenu"
        - "insertItem"
        - "updateItem"
        - "deleteItem"
        - "pathForParent"
        - "assertMoveIsAcyclic"
        - "moveDescendantPaths"
      public_properties: []
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions: []
      serialization_contract: null
    -
      old_fqcn: "Kumwe\\App\\Navigation\\Application\\NavigationVersionConflict"
      new_fqcn: "Kumwe\\Navigation\\Application\\NavigationVersionConflict"
      source_path: "src/Navigation/Application/NavigationVersionConflict.php"
      target_path: "src/Application/NavigationVersionConflict.php"
      kind: "class"
      public_methods: []
      public_properties: []
      public_constants: []
      compatibility: "Canonical namespace ownership move; see COMPATIBILITY.md for validation changes and docs/public-api.md for exact signatures."
      exceptions: []
      serialization_contract: null
  consumers:
    app_code:
      - "src/Administrator/Http/Handler/AdministratorNavigationHandler.php"
      - "src/Administrator/Http/Handler/AdministratorSettingsHandler.php"
      - "src/Delivery/Console/Command/ManageNavigationCommand.php"
      - "src/Delivery/Http/Api/Navigation/MenuCollectionHandler.php"
      - "src/Delivery/Http/Api/Navigation/MenuItemCollectionHandler.php"
      - "src/Delivery/Http/Api/Navigation/NavigationApiResponder.php"
      - "src/Demo/Infrastructure/DemoContentProfileInstaller.php"
      - "src/Demo/Infrastructure/DemoProfileExporter.php"
      - "src/Infrastructure/Mcp/KumweMcpHandlers.php"
      - "src/Infrastructure/Mcp/McpToolErrorVocabulary.php"
      - "src/Kernel/ContainerFactory.php"
      - "src/Navigation/Infrastructure/Persistence/DoctrineNavigationRepository.php"
    configuration_and_di: []
    reflection_and_string_references: []
    fixtures_and_examples: []
    external: []
  dependency_injection:
    mode: "direct"
    provider: null
    factories: []
    aliases: []
    service_lifetimes: []
    configuration_keys: []
    provider_absence_reason: "Values, ports and deterministic stateless algorithms capture no collaborator or ambient state."
native_cpp: null
php_extension: null
tests:
  moved_or_added:
    - "tests/NavigationRepositoryConformanceTest.php"
    - "tests/Conformance/"
    - "tests/Fixture/"
    - "tests/ownership.json"
    - "tests/Domain/MenuItemTest.php"
    - "tests/Domain/MenuTreeTest.php"
    - "tests/Domain/PlacementBoundaryTest.php"
  remain_in_app_or_consumer:
    - "tests/Support/customize-demo-profile.php"
    - "tests/Unit/Content/Presentation/TranslationGroupPresenterTest.php"
    - "tests/Unit/Http/Handler/HomePageHandlerTest.php"
    - "tests/Unit/Http/Handler/PublishedContentHandlerTest.php"
    - "tests/Unit/Navigation/Application/NavigationServiceTest.php"
    - "tests/Unit/Navigation/Application/PublicNavigationTest.php"
    - "tests/Unit/Site/Application/PublicPageLocatorTest.php"
  split_tests: []
  prohibited_duplicates:
    - "tests/Unit/Navigation/Domain/MenuItemTest.php"
    - "tests/Unit/Navigation/Domain/MenuTreeTest.php"
  corpora:
    - "tests/Domain/MenuItemTest.php"
    - "tests/Domain/MenuTreeTest.php"
    - "tests/Domain/PlacementBoundaryTest.php"
documentation:
  charter: "CHARTER.md"
  readme: "README.md"
  public_api: "docs/public-api.md"
  architecture: "docs/architecture.md"
  integration_or_consumer: "docs/integration.md"
  examples:
    - "examples/standalone.php"
  changelog_record: "CHANGELOG.md#0.1.1"
release_expectations:
  version_policy: "SemVer; 0.1.1 candidate source release record, published baseline 0.1.0. Exact consumer pins follow independent artifact verification."
  expected_artifact_types:
    - "Composer ZIP"
  required_checks:
    - "@composer:validate"
    - "@lint"
    - "@api"
    - "@architecture"
    - "@analyse"
    - "@cs"
    - "@test"
    - "@examples"
    - "@security"
    - "@clean-consumer"
    - "@manifests"
  required_registry_or_installer: "Composer"
  required_external_attestation: true
next_task:
  phase_name: "Independent release verification, followed by separately authorized App Phase 2"
  permitted_only_when:
    - "Human review and merge"
    - "All dependencies and this release independently attested"
    - "Current App drift reconciled upstream"
  consumer_repository: "https://github.com/kumwe/app"
  dependency_or_native_change: "After publication and independent verification, adopt exact kumwe/navigation 0.1.2 with its verified stable dependency graph; no floating latest or dev aliases."
  namespace_or_api_replacements:
    - "Kumwe\\App\\Navigation\\Domain\\InvalidMenuTree -> Kumwe\\Navigation\\Domain\\InvalidMenuTree"
    - "Kumwe\\App\\Navigation\\Domain\\MenuItem -> Kumwe\\Navigation\\Domain\\MenuItem"
    - "Kumwe\\App\\Navigation\\Domain\\MenuTree -> Kumwe\\Navigation\\Domain\\MenuTree"
    - "Kumwe\\App\\Navigation\\Application\\MenuItemRecord -> Kumwe\\Navigation\\Application\\MenuItemRecord"
    - "Kumwe\\App\\Navigation\\Application\\MenuRecord -> Kumwe\\Navigation\\Application\\MenuRecord"
    - "Kumwe\\App\\Navigation\\Application\\NavigationNotFound -> Kumwe\\Navigation\\Application\\NavigationNotFound"
    - "Kumwe\\App\\Navigation\\Application\\NavigationRepository -> Kumwe\\Navigation\\Application\\NavigationRepository"
    - "Kumwe\\App\\Navigation\\Application\\NavigationVersionConflict -> Kumwe\\Navigation\\Application\\NavigationVersionConflict"
  files_to_update:
    - "src/Administrator/Http/Handler/AdministratorNavigationHandler.php"
    - "src/Administrator/Http/Handler/AdministratorSettingsHandler.php"
    - "src/Delivery/Console/Command/ManageNavigationCommand.php"
    - "src/Delivery/Http/Api/Navigation/MenuCollectionHandler.php"
    - "src/Delivery/Http/Api/Navigation/MenuItemCollectionHandler.php"
    - "src/Delivery/Http/Api/Navigation/NavigationApiResponder.php"
    - "src/Demo/Infrastructure/DemoContentProfileInstaller.php"
    - "src/Demo/Infrastructure/DemoProfileExporter.php"
    - "src/Infrastructure/Mcp/KumweMcpHandlers.php"
    - "src/Infrastructure/Mcp/McpToolErrorVocabulary.php"
    - "src/Kernel/ContainerFactory.php"
    - "src/Navigation/Infrastructure/Persistence/DoctrineNavigationRepository.php"
    - "tests/Support/customize-demo-profile.php"
    - "tests/Unit/Content/Presentation/TranslationGroupPresenterTest.php"
    - "tests/Unit/Http/Handler/HomePageHandlerTest.php"
    - "tests/Unit/Http/Handler/PublishedContentHandlerTest.php"
    - "tests/Unit/Navigation/Application/NavigationServiceTest.php"
    - "tests/Unit/Navigation/Application/PublicNavigationTest.php"
    - "tests/Unit/Site/Application/PublicPageLocatorTest.php"
    - "composer.json"
    - "composer.lock"
  files_to_remove:
    - "src/Navigation/Domain/InvalidMenuTree.php"
    - "src/Navigation/Domain/MenuItem.php"
    - "src/Navigation/Domain/MenuTree.php"
    - "src/Navigation/Application/MenuItemRecord.php"
    - "src/Navigation/Application/MenuRecord.php"
    - "src/Navigation/Application/NavigationNotFound.php"
    - "src/Navigation/Application/NavigationRepository.php"
    - "src/Navigation/Application/NavigationVersionConflict.php"
  tests_to_remove:
    - "tests/Unit/Navigation/Domain/MenuItemTest.php"
    - "tests/Unit/Navigation/Domain/MenuTreeTest.php"
  tests_to_retain_or_add:
    - "tests/Support/customize-demo-profile.php"
    - "tests/Unit/Content/Presentation/TranslationGroupPresenterTest.php"
    - "tests/Unit/Http/Handler/HomePageHandlerTest.php"
    - "tests/Unit/Http/Handler/PublishedContentHandlerTest.php"
    - "tests/Unit/Navigation/Application/NavigationServiceTest.php"
    - "tests/Unit/Navigation/Application/PublicNavigationTest.php"
    - "tests/Unit/Site/Application/PublicPageLocatorTest.php"
  di_or_provisioning_changes:
    - "No provider or factories; retain host services and bind host persistence ports explicitly."
  capability_index_changes:
    - "Replace implementation owner with exact verified package manifest"
  changelog_and_evidence_changes:
    - "Record enabling-refactor; completion_claim false"
  verification_commands:
    - "composer validate --strict"
    - "composer check"
    - "Applicable App integration, database, authority and delivery tests"
concurrency:
  likely_conflict_files:
    - "composer.json"
    - "composer.lock"
  related_migrations:
    - "KUMWE-MIG-2026-004"
    - "KUMWE-MIG-2026-005"
    - "KUMWE-MIG-2026-006"
    - "KUMWE-MIG-2026-009"
  ownership_conflicts: []
  integration_train: null
  resolution_rule: "semantic-preservation"
governance:
  roadmap_source_sha256: "a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8"
  roadmap_refs: []
  non_roadmap_refs:
    - "NRM-2026-035"
  completion_claim: false
decisions:
  - "Canonical namespace move; no aliases or dual production ownership after adoption"
  - "See COMPATIBILITY.md for initial bounded-input decision"
  - "Full original source digests remain in docs/source-map.json; governed extracted-symbol inventory uses the exact v2 schema."
  - "Owner package contracts are implemented; App adoption and legacy deletion remain a separate consumer phase."
blockers:
  - "Review and merge the 0.1.1 source release record after required CI passes."
  - "Verify default-branch publication, exact artifact digest and independent release verification before App adoption."
---
# Migration handoff

## Migration/implementation summary

Menu placement normalizes UUID parents and bounds path depth and bytes. Titles reject invalid UTF-8. Tree construction, structural mutation and repository ports remain package-owned. This branch records candidate 0.1.1; the published baseline remains 0.1.0.

## Public API and responsibility

Bounded deterministic navigation trees, records and persistence contracts. The governed API, capability and service manifests above enumerate the complete public surface. resources/public-api/signature-details-v1.json and docs/public-api.md preserve parameter defaults and constant values outside the closed governed schema. See docs/readiness-review.md for closure and host exclusions. No ConfigProvider is needed for directly constructed values, pure algorithms and ports.

## Capability reuse/semantic input review

The examined source files and semantic input digests above preserve extraction provenance. docs/dependency-decision.md records actual stable tag identities. No implementation source is relabeled as a stable release, and no App/SDK runtime dependency is introduced. The current App baseline was inspected read-only; no App source or tests were changed.

## Consumer inventory

The framework consumer inventory covers App production references, configuration/DI, string/reflection use, fixtures and external SDK bindings. The next-task file lists identify namespace replacements and legacy removals. Refresh those lists against the consumer current commit before integration. HTTP handlers, template rendering, active registries, authorization and infrastructure remain host concerns.

## Test ownership

The sequential port contract is now executable through `tests/NavigationRepositoryConformanceTest.php`, the reusable `tests/Conformance/` suite and explicitly test-only `tests/Fixture/` adapters. `tests/ownership.json` and `composer ownership` enforce the complete source/test inventory. See [test ownership](docs/test-ownership.md) for reuse and the retained host responsibilities.

All portable behavior and new boundary regression tests are owned by this repository. The machine-readable test inventory lists package tests, consumer tests to retain, split tests and prohibited duplicates. App acceptance and integration tests are retained for the later adoption phase. They were not run or claimed by this review.

## Next-task execution notes

Merge only after required package checks pass. Publish through the existing default-branch release workflow, independently verify the actual archive, then advance the exact dependency pins as a coherent consumer train. The next-task block provides the concrete App changes for that later phase. Completed extraction implementation is documented as present behavior; publication and App acceptance remain open gates.

## Drift check

API JSON, signature details and Markdown are generated from source reflection and checked for byte drift. Capability and service maps use the actual App v2 governance schemas. Handoff manifest hashes describe this source tree. This is a candidate record and keeps completion_claim false; no release-verification attestation has been fabricated.

## Validation recipe and observed local results

Current conformance follow-up on PHP 8.5.10: 23 tests / 100 assertions; the ownership inventory gate also passes. Published baseline 0.1.1 is observed at 9ab8019cc7fbc32b2cd81da74d021bc294ece058. The 0.1.2 heading proposes a successor, not an observed release. Final-head full CI and independent artifact verification remain required.

Run composer validate --strict and composer check on PHP 8.5 with real stable dependencies. Local PHP 8.5.10 source validation passed 12 tests, 31 assertions, PHPStan at the configured maximum level, coding standards, syntax, architecture and API drift checks. Where registry access was unavailable, local source validation used dependencies archived from exact published Git tags. The complete Package CI passed at source commit f1a95abd91c6479a93f6384dd7b759b5bc5936e5 ([run 34162173074](https://github.com/kumwe/navigation/actions/runs/34162173074)), including real Composer installation, security audit, package tests, release automation and the clean built-archive consumer. The same-branch handoff/schema-gate follow-up must also pass required checks before merge. The actual App PackageManifests::read parser was also used read-only to check this package governed manifests and handoff.
