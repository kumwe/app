---
# Example MIGRATION-HANDOFF.md front matter (schema 4.10, Kumwe-v2-08) for a fictitious kumwe/example
# package. Only the framework_php block is populated; native_cpp and php_extension stay null.
schema: kumwe-migration-handoff/v2
artifact_kind: framework_php
migration_id: KUMWE-MIG-2026-001
change_set: KUMWE-CS-2026-001
state: draft_pr_open
source:
  app:
    repository: https://github.com/kumwe/app
    baseline_commit: "d5f5bef50cff74915bfdbcf8793b447d98fb6029"
    examined_paths:
      - src/Example/Describing
      - tests/Unit/Example/Describing
    old_namespace_roots:
      - Kumwe\App\Example\Describing\
    capability_index_sha256: null
  semantic_inputs: []
  examined_dependencies:
    - "psr/container ^2.0"
  active_related_pull_requests: []
target:
  repository: https://github.com/kumwe/example
  artifact_identity: "kumwe/example (Composer library)"
  canonical_namespace_or_abi: Kumwe\Example
  branch: extract/describing
  pull_request: "https://github.com/kumwe/example/pull/1"
ownership:
  responsibility: "Describe a subject with a configured prefix through one stable contract."
  non_responsibilities:
    - "Site settings, actor context and authorization."
  allowed_dependency_ceiling:
    - "psr/container ^2.0"
  implementation_owner: kumwe/example
  next_consumer: kumwe/app
  public_manifests:
    - path: resources/public-api/v1.json
      sha256: "816e970640c81cde6f37c6723386c5101ead7c9a2a49ccc2b94747de289573b9"
    - path: resources/capabilities/v1.json
      sha256: "41e71c5b6f5f6f1524a35e9a2fda86afd89b01cc23d749b322e761e6b6d225d5"
    - path: resources/service-map/v1.json
      sha256: "f4ed617bde18ad896f86c4780b2ee5cc441b249c8a101e25a9b6dac70390b24d"
  intentionally_excluded:
    - "Kumwe\\App\\Example\\Application\\DescribeSubject stays in App: it composes settings and authorization."
framework_php:
  composer_package: kumwe/example
  canonical_namespace: Kumwe\Example
  public_api_manifest: resources/public-api/v1.json
  capability_manifest: resources/capabilities/v1.json
  service_map: resources/service-map/v1.json
  extracted_symbols:
    - old_fqcn: Kumwe\App\Example\Describing\Describer
      new_fqcn: Kumwe\Example\ExampleService
      source_path: src/Example/Describing/Describer.php
      target_path: src/ExampleService.php
      kind: class
      public_methods:
        - describe
      public_properties:
        - prefix
      public_constants: []
      exceptions: []
      serialization_contract: null
      compatibility: preserved
    - old_fqcn: Kumwe\App\Example\Describing\DescriberInterface
      new_fqcn: Kumwe\Example\Contract\ExampleServiceInterface
      source_path: src/Example/Describing/DescriberInterface.php
      target_path: src/Contract/ExampleServiceInterface.php
      kind: interface
      public_methods:
        - describe
      public_properties: []
      public_constants: []
      exceptions: []
      serialization_contract: null
      compatibility: preserved
  consumers:
    app_code:
      - src/Example/Application/DescribeSubject.php
    configuration_and_di:
      - src/Kernel/ContainerFactory.php
    reflection_and_string_references: []
    fixtures_and_examples: []
    external: []
  dependency_injection:
    mode: config-provider
    provider: Kumwe\Example\ConfigProvider
    factories:
      - Kumwe\Example\Container\ExampleServiceFactory
    aliases:
      - "Kumwe\\Example\\Contract\\ExampleServiceInterface => Kumwe\\Example\\ExampleService"
    service_lifetimes:
      - "Kumwe\\Example\\ExampleService: shared"
    configuration_keys:
      - kumwe.example.prefix
    provider_absence_reason: null
native_cpp: null
php_extension: null
tests:
  moved_or_added:
    - tests/ExampleServiceTest.php
  remain_in_app_or_consumer:
    - tests/Integration/Example/DescribeSubjectIntegrationTest.php
  split_tests: []
  prohibited_duplicates:
    - tests/Unit/Example/Describing/DescriberTest.php
  corpora: []
documentation:
  charter: CHARTER.md
  readme: README.md
  public_api: docs/public-api.md
  architecture: docs/architecture.md
  integration_or_consumer: docs/integration.md
  examples:
    - examples/describe.php
  changelog_record: "CHANGELOG.md ## 0.1.0"
release_expectations:
  version_policy: "SemVer; exact pins while pre-1.0; the newest CHANGELOG heading is the release record"
  expected_artifact_types:
    - "Composer dist archive"
  required_checks:
    - "composer check"
    - "clean-consumer gate"
  required_registry_or_installer: Packagist
  required_external_attestation: true
next_task:
  phase_name: "Phase 2 App adoption of kumwe/example"
  permitted_only_when:
    - "RELEASE-ATTESTATION.yaml with status verified exists for the 0.1.0 release"
  consumer_repository: kumwe/app
  dependency_or_native_change: "composer require kumwe/example:0.1.0"
  namespace_or_api_replacements:
    - "Kumwe\\App\\Example\\Describing\\Describer -> Kumwe\\Example\\ExampleService"
  files_to_update:
    - src/Example/Application/DescribeSubject.php
    - src/Kernel/ContainerFactory.php
  files_to_remove:
    - src/Example/Describing/Describer.php
    - src/Example/Describing/DescriberInterface.php
  tests_to_remove:
    - tests/Unit/Example/Describing/DescriberTest.php
  tests_to_retain_or_add:
    - tests/Integration/Example/DescribeSubjectIntegrationTest.php
  di_or_provisioning_changes:
    - "Bind Kumwe\\Example\\Contract\\ExampleServiceInterface to the App adapter in ContainerFactory"
  capability_index_changes:
    - "composer kumwe:capability-index adds the kumwe/example entry"
  changelog_and_evidence_changes:
    - "CHANGELOG.md Added entry under NRM-2026-099; ledger record KUMWE-MIG-2026-001"
  verification_commands:
    - "composer qa"
concurrency:
  likely_conflict_files:
    - src/Kernel/ContainerFactory.php
    - composer.json
  related_migrations: []
  ownership_conflicts: []
  integration_train: null
  resolution_rule: semantic-preservation
governance:
  roadmap_source_sha256: a202155ef1a65f5ab293d4f8397ebf4ac430db7f1e877c776bbe7851e6fe18d8
  roadmap_refs: []
  non_roadmap_refs:
    - NRM-2026-099
  completion_claim: false
decisions:
  - "The prefix policy stays in App as host authority."
blockers: []
---

## 1. Migration/implementation summary

The describing contract and its default implementation moved to `kumwe/example`; the App adapter and the
prefix policy stayed in App because they read site settings.

## 2. Public API and responsibility

`Kumwe\Example\Contract\ExampleServiceInterface`, `Kumwe\Example\ExampleService`,
`Kumwe\Example\ConfigProvider` and `Kumwe\Example\Container\ExampleServiceFactory`; see `docs/public-api.md`.

## 3. Capability reuse/semantic input review

No installed package owned the describing contract; searches by responsibility ("describe", "prefix")
found only the App classes being extracted.

## 4. Consumer inventory

`src/Example/Application/DescribeSubject.php` and the kernel binding in `src/Kernel/ContainerFactory.php`.

## 5. Test ownership

The unit test of the service moved with it; the App integration test stays and proves composition.

## 6. Next-task execution notes

Require the verified release, replace the old names, delete the old classes and their unit test, bind the
adapter in the kernel, regenerate the capability index and record the ledger entry.

## 7. Drift check

Compare `src/Example/Describing` at the baseline commit with the current tree before adopting; route any
newer portable change through a successor release.

## 8. Validation recipe and observed local results

`composer check` in the package passed locally on the tested head; the clean-consumer gate passed.
